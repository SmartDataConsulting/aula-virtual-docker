<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class RequestTiming
{
    public function handle($request, Closure $next)
    {
        $start = microtime(true);

        $response = $next($request);

        $route = $request->route();
        $routeName = is_array($route)
            ? ($route[1]['as'] ?? $route[1]['uses'] ?? null)
            : null;

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;
        $slowMs = (int) env('PERF_LOG_SLOW_MS', 800);
        $sampleRate = (float) env('PERF_LOG_SAMPLE_RATE', 0.05);
        $shouldLog = $durationMs >= $slowMs
            || ($status !== null && $status >= 500)
            || (mt_rand() / mt_getrandmax()) < $sampleRate;

        if (!$shouldLog) {
            return $response;
        }

        $level = $durationMs >= $slowMs || ($status !== null && $status >= 500) ? 'warning' : 'info';

        Log::log($level, 'REQUEST_TIMING', [
            'route' => $routeName ?: $request->path(),
            'method' => $request->method(),
            'status' => $status,
            'duration_ms' => $durationMs,
            'user_role' => $request->header('X-USER-ROL'),
            'correlation_id' => $request->header('X-Correlation-ID'),
        ]);

        return $response;
    }
}
