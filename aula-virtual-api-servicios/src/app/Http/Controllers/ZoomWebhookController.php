<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use App\Services\ZoomWebhookVerifier;
use Illuminate\Http\Request;
use Laravel\Lumen\Routing\Controller;

class ZoomWebhookController extends Controller
{
    public function __construct(
        private ZoomWebhookVerifier $verifier,
        private AttendanceService $attendance
    ) {}

    public function handle(Request $request)
    {
        $raw = $request->getContent();
        if (!$this->verifier->valid(
            $raw,
            $request->header('x-zm-request-timestamp'),
            $request->header('x-zm-signature')
        )) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'invalid_json'], 400);
        }
        if (($payload['event'] ?? '') === 'endpoint.url_validation') {
            return response()->json($this->verifier->validationResponse(
                (string) ($payload['payload']['plainToken'] ?? '')
            ));
        }
        return response()->json(['ok' => true] + $this->attendance->processZoomEvent($payload, $raw));
    }
}
