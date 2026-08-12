<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class ZoomReportClient
{
    public function participants(string $meetingId): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('zoom_not_configured');
        }
        $client = new Client(['timeout' => 15]);
        $response = $client->get(
            'https://api.zoom.us/v2/report/meetings/'.rawurlencode($meetingId).'/participants',
            ['headers' => ['Authorization' => 'Bearer '.$this->token()], 'query' => ['page_size' => 300]]
        );
        $payload = json_decode((string) $response->getBody(), true);
        return is_array($payload['participants'] ?? null) ? $payload['participants'] : [];
    }

    private function token(): string
    {
        return Cache::remember('zoom:s2s:token', 3300, function (): string {
            $client = new Client(['timeout' => 10]);
            $response = $client->post('https://zoom.us/oauth/token', [
                'auth' => [
                    (string) config('services.zoom.client_id'),
                    (string) config('services.zoom.client_secret'),
                ],
                'form_params' => [
                    'grant_type' => 'account_credentials',
                    'account_id' => (string) config('services.zoom.account_id'),
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
            if (empty($payload['access_token'])) {
                throw new \RuntimeException('zoom_token_missing');
            }
            return $payload['access_token'];
        });
    }

    private function configured(): bool
    {
        return trim((string) config('services.zoom.account_id')) !== ''
            && trim((string) config('services.zoom.client_id')) !== ''
            && trim((string) config('services.zoom.client_secret')) !== '';
    }
}
