<?php

namespace App\Http\Middleware;

use App\Support\AuthSessionKeys;
use App\Support\ApiRequestMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $route = $request->route();
        $routeName = is_object($route) && method_exists($route, 'getName')
            ? $route->getName()
            : null;
        $userRole = $request->hasSession()
            ? $request->session()->get(AuthSessionKeys::USER_ROLE)
            : null;

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $slowMs = (int) env('PERF_LOG_SLOW_MS', 800);
        $sampleRate = (float) env('PERF_LOG_SAMPLE_RATE', 0.05);
        $shouldLog = $durationMs >= $slowMs
            || $response->getStatusCode() >= 500
            || (mt_rand() / mt_getrandmax()) < $sampleRate;

        if (!$shouldLog) {
            return $response;
        }

        $level = $durationMs >= $slowMs || $response->getStatusCode() >= 500 ? 'warning' : 'info';

        Log::log($level, 'REQUEST_TIMING', array_merge([
            'route' => $routeName ?: $request->path(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'user_role' => $userRole,
            'correlation_id' => $request->attributes->get('correlation_id'),
        ], ApiRequestMetrics::snapshot()));

        return $response;
    }
}
