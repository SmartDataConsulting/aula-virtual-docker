<?php

namespace App\Services\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente HTTP para autenticacion JWT con WordPress.
 */
class WordpressAuthClient
{
    public function requestToken(string $username, string $password): Response
    {
        // Solicita token JWT con credenciales de usuario.
        $baseUrl = $this->baseUrl();
        $endpoint = config('services.wordpress.jwt_token_path', '/wp-json/jwt-auth/v1/token');
        $timeout = (int) config('services.wordpress.timeout', 10);
        $retryTimes = (int) config('services.wordpress.retry_times', 1);
        $retrySleep = (int) config('services.wordpress.retry_sleep', 200);

        Log::info('WordPress auth token request.', [
            'username' => $username,
            'base_url' => $baseUrl,
        ]);

        $correlationHeader = (string) config('services.correlation.header', 'X-Correlation-ID');
        return Http::timeout($timeout)
            ->retry($retryTimes, $retrySleep)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                $correlationHeader => $this->correlationId(),
            ])
            ->post($baseUrl.$endpoint, [
                'username' => $username,
                'password' => $password,
            ]);
    }

    public function validateToken(string $token): Response
    {
        // Valida token JWT en el gateway WordPress.
        $baseUrl = $this->baseUrl();
        $endpoint = config('services.wordpress.jwt_validate_path', '/wp-json/jwt-auth/v1/token/validate');
        $timeout = (int) config('services.wordpress.validate_timeout', 5);
        $retryTimes = (int) config('services.wordpress.retry_times', 1);
        $retrySleep = (int) config('services.wordpress.retry_sleep', 200);

        Log::info('WordPress auth validate request.', [
            'base_url' => $baseUrl,
        ]);

        $correlationHeader = (string) config('services.correlation.header', 'X-Correlation-ID');
        return Http::timeout($timeout)
            ->retry($retryTimes, $retrySleep)
            ->acceptJson()
            ->withToken($token)
            ->withHeaders([
                $correlationHeader => $this->correlationId(),
            ])
            ->post($baseUrl.$endpoint);
    }

    private function baseUrl(): string
    {
        // Resuelve base_url y valida configuracion.
        $baseUrl = trim((string) config('services.wordpress.base_url', ''));

        if ($baseUrl === '') {
            throw new RuntimeException('Missing WP_AUTH_BASE_URL value.');
        }

        return rtrim($baseUrl, '/');
    }

    private function correlationId(): string
    {
        // Recupera correlation-id agregado por middleware.
        $request = app('request');
        if (!$request instanceof HttpRequest) {
            return '';
        }

        return (string) $request->attributes->get('correlation_id', '');
    }
}
