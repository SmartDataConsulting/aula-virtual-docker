<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;

class ZoomJoinController extends Controller
{
    public function __invoke(AttendanceService $attendance, int $course, int $session)
    {
        $result = $attendance->join($course, $session);
        $payload = is_array($result->data()) ? $result->data() : [];
        $url = (string) ($payload['join_url'] ?? '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!$result->ok() || !filter_var($url, FILTER_VALIDATE_URL)
            || !($host === 'zoom.us' || str_ends_with($host, '.zoom.us'))) {
            return response()->view('errors.zoom-access', [], $result->status() ?: 503);
        }
        return redirect()->away($url, 303);
    }
}
