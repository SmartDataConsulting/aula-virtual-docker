<?php

namespace App\Services;

use App\Services\Http\WordpressAuthClient;
use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Servicio de autenticacion y validacion contra WordPress.
 */
class AuthService
{
    public function __construct(
        private readonly WordpressAuthClient $client,
        private readonly ApiServiciosClient $apiClient
    ) {
    }

    public function authenticateWithWordpress(string $username, string $password): ServiceResult
    {
        // Solicita token JWT al gateway de WordPress.
        try {
            $response = $this->client->requestToken($username, $password);
        } catch (ConnectionException | RuntimeException $exception) {
            return ServiceResult::failure([
                'message' => $exception->getMessage(),
            ]);
        }

        if ($response->successful()) {
            return ServiceResult::success((array) $response->json(), $response->status());
        }

        return ServiceResult::failure($this->extractError($response), $response->status());
    }

    private function extractError(Response $response): array
    {
        $payload = $response->json();

        if (is_array($payload)) {
            return $payload;
        }

        return [
            'message' => $response->body(),
        ];
    }

    public function validateWordpressToken(string $token): ServiceResult
    {
        // Valida el token JWT en WordPress.
        try {
            $response = $this->client->validateToken($token);
        } catch (ConnectionException | RuntimeException $exception) {
            return ServiceResult::failure([
                'message' => $exception->getMessage(),
            ]);
        }

        if ($response->successful()) {
            return ServiceResult::success((array) $response->json(), $response->status());
        }

        return ServiceResult::failure($this->extractError($response), $response->status());
    }

    public function authenticateWithCore(string $email, string $password): ServiceResult
    {
        $result = $this->apiClient->login($email, $password);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success(
            (array) $result->data(),
            $result->status()
        );
    }
}
