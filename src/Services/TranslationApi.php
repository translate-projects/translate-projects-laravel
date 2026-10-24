<?php

namespace Neiderruiz\TranslateProjectsLaravel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationApi
{
    private string $baseUrl;
    private ?string $token;
    private array $headers;

    public function __construct(?string $token = null)
    {
        $this->baseUrl = rtrim('https://translateprojects.dev', '/');
        $this->token = $token;
        $this->headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($this->token) {
            $this->headers['Authorization'] = "Token {$this->token}";
        }
    }

    /**
     * Realiza una solicitud HTTP a la API
     */
    private function makeRequest(string $method, string $endpoint, array $params = [], array $data = null, int $retries = 3): ?array
    {
        $url = "{$this->baseUrl}{$endpoint}";

        for ($attempt = 0; $attempt < $retries; $attempt++) {
            try {
                $response = Http::withHeaders($this->headers)
                    ->{$method}($url, $method === 'GET' ? $params : $data);

                if ($response->successful()) {
                    return $response->json();
                } elseif (in_array($response->status(), [524, 504])) {
                    Log::warning("🛑 Warning: Timeout detected (attempt {$attempt}/{$retries}). Retrying...");
                    continue;
                } else {
                    Log::error("🛑 API Error ({$response->status()}): " . $response->body());
                    return null;
                }
            } catch (\Exception $e) {
                Log::error("Request failed: {$e->getMessage()} (attempt {$attempt}/{$retries})");
            }
        }

        Log::error("Max retries reached. Request failed.");
        return null;
    }

    /**
     * Envía datos para traducir
     */
    public function makeTranslations(array $config): ?array
    {
        return $this->makeRequest('POST', '/v1/translations/', [
            'source_lang' => $config['sourceLang'],
            'target_lang' => $config['targetLang'],
            'route_file' => $config['route_file'] ?? null,
            'cache_hash' => $config['cache_hash'] ?? null,
        ]);
    }

    /**
     * Obtiene la lista de traducciones
     */
    public function fetchTranslations(array $params = []): ?array
    {
        return $this->makeRequest('GET', '/v1/translations/list', $params);
    }

    public function syncSources(array $params): ?array
    {
        // Si 'data' es un string JSON, lo convertimos a array
        if (isset($params['data']) && is_string($params['data'])) {
            $params['data'] = json_decode($params['data'], true);
        }
    
        $response = Http::withHeaders([
            'Authorization' => 'Token ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post("{$this->baseUrl}/translations/load-sources", $params);
    
        if ($response->successful()) {
            return $response->json();
        }
    
        // Registrar error en el log de Laravel
        \Log::error('Error en la sincronización de traducciones', [
            'status' => $response->status(),
            'error' => $response->body(),
        ]);
    
        return null;
    }    

    /**
     * Obtiene traducciones específicas
     */
    public function getTranslations(string $targetLang): ?array
    {
        return $this->makeRequest('GET', '/v1/translations/language-project', [
            'target_lang' => $targetLang,
        ]);
    }

    /**
     * Valida cambios en archivos de traducción
     */
    public function validateChangesFiles(array $data): ?array
    {
        return $this->makeRequest('POST', '/v1/translations/validate-changes-files', [], $data);
    }
}
