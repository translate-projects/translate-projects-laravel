<?php

namespace Neiderruiz\TranslateProjectsLaravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Neiderruiz\TranslateProjectsLaravel\Commands\TranslateCommand;
use Neiderruiz\TranslateProjectsLaravel\Commands\TranslateScanCommand;
use Neiderruiz\TranslateProjectsLaravel\Helpers\HashHelper;
use Neiderruiz\TranslateProjectsLaravel\Services\TranslationApi;
use Neiderruiz\TranslateProjectsLaravel\TranslateProjectsLaravelServiceProvider;
use Orchestra\Testbench\TestCase;

class FakeWaitApi extends TranslationApi
{
    public static bool $completes = true;

    public function waitTask(string $taskId, ?callable $onProgress = null): bool
    {
        $this->lastError = 'No progress';
        return self::$completes;
    }
}

class TranslateTest extends TestCase
{
    private const API = 'https://api.translateprojects.dev';
    private const TRANSLATED = [
        'en' => ['Hola' => 'Hello', 'Hola :name' => 'Hello :name', '<b>Guardar</b>' => '<b>Save</b>'],
        'pt-BR' => ['Hola' => 'Olá', 'Hola :name' => 'Olá :nome', '<b>Guardar</b>' => '<b>Salvar</b>'],
        'fr' => ['Hola' => 'Bonjour', 'Hola :name' => 'Bonjour :name', '<b>Guardar</b>' => '<b>Enregistrer</b>'],
    ];

    private string $dir;
    private array $stored = [];

    protected function getPackageProviders($app)
    {
        return [TranslateProjectsLaravelServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $this->dir = sys_get_temp_dir() . '/tp-laravel-' . uniqid();
        mkdir("$this->dir/lang", 0777, true);
        $app->useLangPath("$this->dir/lang");
        $app['config']->set('translate-projects.api_key', 'test-key');
        $app['config']->set('translate-projects.default_locale', 'es');
        $app['config']->set('translate-projects.target_languages', ['en', 'pt-BR', 'fr']);
        $app->bind(TranslationApi::class, function ($app, $params) {
            $api = new FakeWaitApi(...$params);
            $api->retryDelayMs = 0;
            return $api;
        });
        FakeWaitApi::$completes = true;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function writeSource(array $texts): void
    {
        file_put_contents("$this->dir/lang/es.json", json_encode($texts));
    }

    private function lang(string $file): ?array
    {
        $path = "$this->dir/lang/$file";
        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }

    /** Backend falso: guarda lo que suben y devuelve las traducciones de TRANSLATED por hash. */
    private function fakeBackend(array $overrides = []): void
    {
        Http::fake($overrides + [
            self::API . '/v1/translations/validate-changes-files' => function (Request $r) {
                $changed = array_filter($r->data(), fn ($v, $k) => ($this->stored[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
                return Http::response(['status' => 'SUCCESS', 'data' => $changed ?: new \stdClass()]);
            },
            self::API . '/v1/translations/load-sources*' => function (Request $r) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
                $this->stored[HashHelper::generateHashText($q['route_file'])] = $q['cache_hash'];
                return Http::response(['status' => 'SUCCESS', 'data' => ['task_id' => 't-sync']]);
            },
            self::API . '/v1/translations/?*' => Http::response(['status' => 'SUCCESS', 'data' => ['task_id' => 't-1']]),
            self::API . '/v1/translations/language-project*' => function (Request $r) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
                $data = [];
                foreach (self::TRANSLATED[$q['target_lang']] as $text => $translation) {
                    $data[HashHelper::generateHashText($text)] = $translation;
                }
                return Http::response(['status' => 'SUCCESS', 'data' => $data]);
            },
        ]);
    }

    private function requestsTo(string $path): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $path))->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_hash_matches_backend_known_value()
    {
        // Valor que da helpers/hash_utils.generate_short_hash del backend
        $this->assertSame('51br2yg5zoskw6o', HashHelper::generateHashText('Hola %(name)s'));
        $this->assertSame(15, strlen(HashHelper::generateHashText('')));
    }

    public function test_locale_codes()
    {
        $this->assertSame('pt_BR', TranslateCommand::fileLocale('pt-br'));
        $this->assertSame('pt-BR', TranslateCommand::apiLocale('pt_BR'));
        $this->assertSame('zh-Hans', TranslateCommand::apiLocale('zh_hans'));
        $this->assertSame('fr', TranslateCommand::apiLocale('fr'));
    }

    public function test_extracts_texts_from_code()
    {
        $code = <<<'PHP'
            {{ __('Hola') }} @lang("Adiós") {{ __('Hola :name', ['name' => $n]) }}
            trans('It\'s ok') trans_choice('manzana|manzanas', 2) __($variable) translate('no')
            PHP;
        $this->assertSame(['Hola', 'Adiós', 'Hola :name', "It's ok", 'manzana|manzanas'], TranslateScanCommand::extract($code));
    }

    public function test_scan_writes_source_json_and_keeps_existing_texts()
    {
        $scan = "$this->dir/app";
        mkdir("$scan/views", 0777, true);
        mkdir("$this->dir/lang/es");
        file_put_contents("$this->dir/lang/es/auth.php", '<?php return [];');
        file_put_contents("$scan/views/home.blade.php", "{{ __('Hola') }} {{ __('auth.failed') }} {{ __('pkg::a.b') }}");
        $this->writeSource(['Viejo' => 'Viejo']);
        config(['translate-projects.scan_paths' => [$scan]]);
        $this->app->setBasePath('/');
        $this->app->useLangPath("$this->dir/lang");

        $this->artisan('translate:scan')->assertSuccessful();

        $this->assertSame(['Hola' => 'Hola', 'Viejo' => 'Viejo'], $this->lang('es.json'));
    }

    public function test_translates_and_writes_each_language()
    {
        $this->writeSource(['Hola' => 'Hola', 'Hola :name' => 'Hola :name', 'save' => '<b>Guardar</b>', ':' => ':']);
        $this->fakeBackend();

        $this->artisan('translate')->assertSuccessful();

        $this->assertSame(['Hola' => 'Hello', 'Hola :name' => 'Hello :name', 'save' => '<b>Save</b>'], $this->lang('en.json'));
        $this->assertSame('Bonjour', $this->lang('fr.json')['Hola']);
        $this->assertSame('Olá', $this->lang('pt_BR.json')['Hola']);

        [$upload] = $this->requestsTo('load-sources');
        $this->assertStringContainsString('type_project=laravel', $upload->url());
        $this->assertStringContainsString('source_lang=es', $upload->url());
        $this->assertSame('Token test-key', $upload->header('Authorization')[0]);
        $this->assertSame(['Hola', 'Hola :name', '<b>Guardar</b>'], array_values($upload->data()));
        $this->assertArrayHasKey(HashHelper::generateHashText('Hola'), $upload->data());
        $this->assertStringContainsString('target_lang=pt-BR', $this->requestsTo('/v1/translations/?')[1]->url());
    }

    public function test_placeholder_changed_by_ai_is_rejected()
    {
        $this->writeSource(['Hola :name' => 'Hola :name']);
        $this->fakeBackend();

        $this->artisan('translate', ['locale' => 'pt-BR'])
            ->expectsOutputToContain('cambiaron los :placeholders')
            ->assertSuccessful();

        $this->assertSame([], $this->lang('pt_BR.json'));
    }

    public function test_second_run_skips_unchanged_texts_and_languages()
    {
        $this->writeSource(['Hola' => 'Hola']);
        $this->fakeBackend();
        $this->artisan('translate')->assertSuccessful();
        $firstRun = count(Http::recorded());

        $this->artisan('translate')->expectsOutputToContain('en está al día')->assertSuccessful();

        $secondRun = array_slice(Http::recorded()->all(), $firstRun);
        $this->assertCount(1, $secondRun, 'solo validate-changes-files');
        $this->assertStringContainsString('validate-changes-files', $secondRun[0][0]->url());
    }

    public function test_changed_source_is_uploaded_and_translated_again()
    {
        $this->writeSource(['Hola' => 'Hola']);
        $this->fakeBackend();
        $this->artisan('translate')->assertSuccessful();

        $this->writeSource(['Hola' => 'Hola', 'Hola :name' => 'Hola :name']);
        $this->artisan('translate')->assertSuccessful();

        $this->assertCount(2, $this->requestsTo('load-sources'));
        $this->assertCount(6, $this->requestsTo('/v1/translations/?'));
        $this->assertSame('Hello :name', $this->lang('en.json')['Hola :name']);
    }

    public function test_force_translates_even_if_cached()
    {
        $this->writeSource(['Hola' => 'Hola']);
        $this->fakeBackend();
        $this->artisan('translate')->assertSuccessful();
        $this->artisan('translate', ['--force' => true])->assertSuccessful();

        $this->assertCount(6, $this->requestsTo('/v1/translations/?'));
    }

    public function test_partial_response_writes_what_arrived_and_retries_next_time()
    {
        // MISSING_API_KEY: el backend deja sin traducir los textos nuevos
        $this->writeSource(['Hola' => 'Hola', 'Nuevo' => 'Nuevo']);
        FakeWaitApi::$completes = false;
        $this->fakeBackend();

        $this->artisan('translate', ['locale' => 'en'])
            ->expectsOutputToContain('1 textos sin traducción')
            ->assertSuccessful();

        $this->assertSame(['Hola' => 'Hello'], $this->lang('en.json'));
        $this->assertArrayNotHasKey('en', $this->lang('.translate-projects.json'));

        $this->artisan('translate', ['locale' => 'en'])->assertSuccessful();
        $this->assertCount(2, $this->requestsTo('/v1/translations/?'));
    }

    public function test_keeps_manual_entries_of_existing_translation_file()
    {
        $this->writeSource(['Hola' => 'Hola', 'Nuevo' => 'Nuevo']);
        file_put_contents("$this->dir/lang/en.json", json_encode(['Nuevo' => 'New (a mano)', 'Otro' => 'Other']));
        $this->fakeBackend();

        $this->artisan('translate', ['locale' => 'en'])->assertSuccessful();

        $this->assertSame(['Nuevo' => 'New (a mano)', 'Otro' => 'Other', 'Hola' => 'Hello'], $this->lang('en.json'));
    }

    public function test_missing_api_key_fails_without_requests()
    {
        config(['translate-projects.api_key' => null]);
        $this->writeSource(['Hola' => 'Hola']);
        Http::fake();

        $this->artisan('translate')->expectsOutputToContain('TRANSLATE_PROJECTS_API_KEY')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_missing_source_file_fails()
    {
        Http::fake();
        $this->artisan('translate')->expectsOutputToContain('translate:scan')->assertFailed();
    }

    public function test_invalid_token_fails_without_touching_files()
    {
        $this->writeSource(['Hola' => 'Hola']);
        file_put_contents("$this->dir/lang/en.json", '{"Hola":"Hi"}');
        $this->fakeBackend([
            self::API . '/v1/translations/load-sources*' => Http::response(
                ['status' => 'FAILURE', 'code' => 'INVALID_INPUT', 'message' => 'Token no válido'], 400),
        ]);

        $this->artisan('translate')->expectsOutputToContain('INVALID_INPUT Token no válido')->assertFailed();

        $this->assertSame('{"Hola":"Hi"}', file_get_contents("$this->dir/lang/en.json"));
        $this->assertCount(1, $this->requestsTo('load-sources'), '4xx no se reintenta');
        $this->assertNull($this->lang('.translate-projects.json'));
    }

    public function test_server_error_is_retried_then_language_is_left_untouched()
    {
        $this->writeSource(['Hola' => 'Hola']);
        file_put_contents("$this->dir/lang/en.json", '{"Hola":"Hi"}');
        $this->fakeBackend([
            self::API . '/v1/translations/language-project?target_lang=en' => Http::response('Bad gateway', 502),
        ]);

        $this->artisan('translate')->expectsOutputToContain('API Error (502)')->assertFailed();

        $this->assertCount(3, $this->requestsTo('language-project?target_lang=en'));
        $this->assertSame('{"Hola":"Hi"}', file_get_contents("$this->dir/lang/en.json"));
        $this->assertSame('Bonjour', $this->lang('fr.json')['Hola'], 'los demás idiomas siguen');
        $this->assertArrayNotHasKey('en', $this->lang('.translate-projects.json'));
    }

    public function test_client_error_on_one_language_does_not_stop_the_others()
    {
        $this->writeSource(['Hola' => 'Hola']);
        $this->fakeBackend([
            self::API . '/v1/translations/?source_lang=es&target_lang=fr*' => Http::response(
                ['status' => 'FAILURE', 'code' => 'INVALID_INPUT', 'message' => 'El idioma de destino no está activo'], 400),
        ]);

        $this->artisan('translate')->expectsOutputToContain('No se pudo iniciar la traducción de fr')->assertFailed();

        $this->assertNull($this->lang('fr.json'));
        $this->assertSame('Hello', $this->lang('en.json')['Hola']);
    }

    public function test_network_failure_does_not_crash()
    {
        $this->writeSource(['Hola' => 'Hola']);
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $this->artisan('translate')->expectsOutputToContain('Could not resolve host')->assertFailed();

        $this->assertNull($this->lang('en.json'));
    }
}
