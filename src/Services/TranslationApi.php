<?php

namespace Neiderruiz\TranslateProjectsLaravel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TranslationApi
{
    private const RETRY_STATUS = [429, 500, 502, 503, 504, 524];
    // El progreso solo llega al terminar cada lote de IA: hay que tolerar un lote lento
    private const IDLE_TIMEOUT = 120;

    public int $requestsMade = 0;
    public ?string $lastError = null;
    public int $retryDelayMs = 1000;

    private string $baseUrl;

    public function __construct(private string $token, ?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?: 'https://api.translateprojects.dev', '/');
    }

    /**
     * Llama a la API reintentando 429, 5xx y fallos de red.
     * Devuelve el `data` de la respuesta o null; nunca lanza excepciones.
     */
    private function request(string $method, string $endpoint, array $query = [], ?array $body = null, int $retries = 3): ?array
    {
        $this->lastError = null;
        $url = $this->baseUrl . $endpoint . ($query ? '?' . http_build_query($query) : '');

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            if ($attempt > 1) {
                usleep($this->retryDelayMs * 1000 * 2 ** ($attempt - 2));
            }
            $this->requestsMade++;

            try {
                $response = Http::withToken($this->token, 'Token')
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(60)
                    ->send($method, $url, $body === null ? [] : ['json' => $body]);
            } catch (ConnectionException $e) {
                $this->lastError = "Request failed: {$e->getMessage()}";
                continue;
            }

            if ($response->status() === 200) {
                $json = $response->json();
                if (!is_array($json)) {
                    $this->lastError = 'Invalid JSON response';
                    return null;
                }
                return is_array($json['data'] ?? null) ? $json['data'] : [];
            }

            $this->lastError = "API Error ({$response->status()}): "
                . trim(($response->json('code') ?? '') . ' ' . ($response->json('message') ?? substr($response->body(), 0, 200)));

            if (!in_array($response->status(), self::RETRY_STATUS)) {
                return null;
            }
        }

        return null;
    }

    /** Devuelve las entradas {file_hash: version} que el backend aún no tiene. */
    public function validateChangesFiles(array $fileHashes): ?array
    {
        return $this->request('POST', '/v1/translations/validate-changes-files', [], $fileHashes);
    }

    /** Sube los textos fuente {hash: texto}. Devuelve el task_id o null. */
    public function syncSources(array $data, string $sourceLang, string $routeFile, string $cacheHash): ?string
    {
        $response = $this->request('POST', '/v1/translations/load-sources', [
            'source_lang' => $sourceLang,
            'route_file' => $routeFile,
            'type_project' => 'laravel',
            'cache_hash' => $cacheHash,
        ], $data);

        return $this->taskId($response);
    }

    /** Pide traducir el archivo a $targetLang. Devuelve el task_id o null. */
    public function makeTranslations(string $sourceLang, string $targetLang, string $routeFile): ?string
    {
        $response = $this->request('POST', '/v1/translations/', [
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
            'route_file' => $routeFile,
        ]);

        return $this->taskId($response);
    }

    /** Devuelve las traducciones del proyecto {hash: traducción}. */
    public function getTranslations(string $targetLang): ?array
    {
        return $this->request('GET', '/v1/translations/language-project', ['target_lang' => $targetLang]);
    }

    private function taskId(?array $response): ?string
    {
        if ($response !== null && empty($response['task_id'])) {
            $this->lastError = 'The API did not return a task_id';
        }
        return $response['task_id'] ?? null;
    }

    /**
     * Espera a que la tarea llegue al 100 % por el WebSocket de progreso.
     * Devuelve false si se corta o no hay progreso en IDLE_TIMEOUT segundos; nunca lanza.
     */
    public function waitTask(string $taskId, ?callable $onProgress = null): bool
    {
        $parts = parse_url($this->baseUrl);
        $secure = ($parts['scheme'] ?? 'https') === 'https';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($secure ? 443 : 80);

        $socket = @stream_socket_client(($secure ? 'tls://' : 'tcp://') . "$host:$port", $errno, $errstr, 10);
        if (!$socket) {
            $this->lastError = "Progress socket failed ($errstr)";
            return false;
        }
        stream_set_timeout($socket, self::IDLE_TIMEOUT);

        // shortcut: cliente WebSocket mínimo (solo frames de texto del servidor, sin fragmentación); usar una librería si el backend cambia de protocolo
        fwrite($socket, "GET /ws/progress/$taskId/ HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $status = fgets($socket);
        if (!$status || !str_contains($status, ' 101 ')) {
            fclose($socket);
            $this->lastError = 'Progress socket handshake failed';
            return false;
        }
        while (($line = fgets($socket)) !== false && trim($line) !== '');

        while (($header = $this->readBytes($socket, 2)) !== null) {
            $opcode = ord($header[0]) & 0x0F;
            $length = ord($header[1]) & 0x7F;
            if ($length === 126) {
                $length = unpack('n', $this->readBytes($socket, 2) ?? "\0\0")[1];
            } elseif ($length === 127) {
                $length = unpack('J', $this->readBytes($socket, 8) ?? str_repeat("\0", 8))[1];
            }
            $payload = $length ? $this->readBytes($socket, $length) : '';
            if ($payload === null || $opcode === 0x8) {
                break;
            }
            if ($opcode !== 0x1) {
                continue;
            }
            $progress = (float) (json_decode($payload, true)['progress'] ?? 0);
            if ($onProgress) {
                $onProgress($progress);
            }
            if ($progress >= 100) {
                fclose($socket);
                return true;
            }
        }

        $timedOut = stream_get_meta_data($socket)['timed_out'];
        fclose($socket);
        $this->lastError = $timedOut
            ? 'No progress for ' . self::IDLE_TIMEOUT . "s on task $taskId"
            : 'Progress socket closed before 100%';
        return false;
    }

    private function readBytes($socket, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }
        return $data;
    }
}
