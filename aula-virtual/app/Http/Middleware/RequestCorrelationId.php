<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inyecta un correlation-id para trazabilidad de requests.
 */
class RequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Obtiene o genera el correlation-id y lo agrega al contexto.
        $headerName = (string) config('services.correlation.header', 'X-Correlation-ID');
        $correlationId = (string) $request->header($headerName);
        if ($correlationId === '') {
            $correlationId = Str::uuid()->toString();
        }

        $request->attributes->set('correlation_id', $correlationId);
        Log::withContext([
            'correlation_id' => $correlationId,
        ]);

        $response = $next($request);
        $response->headers->set($headerName, $correlationId);

        return $response;
    }
}
