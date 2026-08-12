<?php

namespace App\Services;

class ZoomWebhookVerifier
{
    public function valid(string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        $secret = trim((string) config('services.zoom.webhook_secret', ''));
        if ($secret === '' || !$timestamp || !$signature || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $expected = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public function validationResponse(string $plainToken): array
    {
        return [
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac(
                'sha256',
                $plainToken,
                (string) config('services.zoom.webhook_secret', '')
            ),
        ];
    }
}
