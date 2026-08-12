<?php

namespace Tests\Unit;

use App\Services\ZoomWebhookVerifier;
use Tests\TestCase;

class ZoomWebhookVerifierTest extends TestCase
{
    public function test_accepts_a_recent_valid_signature(): void
    {
        config(['services.zoom.webhook_secret' => 'test-webhook-secret']);
        $timestamp = (string) time();
        $body = json_encode(['event' => 'meeting.participant_joined']);
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'test-webhook-secret');

        self::assertTrue((new ZoomWebhookVerifier())->valid($body, $timestamp, $signature));
    }

    public function test_rejects_old_or_invalid_signatures(): void
    {
        config(['services.zoom.webhook_secret' => 'test-webhook-secret']);
        $verifier = new ZoomWebhookVerifier();

        self::assertFalse($verifier->valid('{}', (string) (time() - 301), 'v0=invalid'));
        self::assertFalse($verifier->valid('{}', (string) time(), 'v0=invalid'));
    }

    public function test_builds_zoom_url_validation_response(): void
    {
        config(['services.zoom.webhook_secret' => 'test-webhook-secret']);
        $response = (new ZoomWebhookVerifier())->validationResponse('plain-token');

        self::assertSame('plain-token', $response['plainToken']);
        self::assertSame(
            hash_hmac('sha256', 'plain-token', 'test-webhook-secret'),
            $response['encryptedToken']
        );
    }
}
