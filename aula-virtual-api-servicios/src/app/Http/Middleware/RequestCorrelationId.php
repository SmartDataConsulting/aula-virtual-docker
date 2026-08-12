<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class RequestCorrelationId
{
    public function handle($request, Closure $next)
    {
        // 1️⃣ Obtener correlation id del header o generar uno nuevo
        $correlationId = $request->header('X-Correlation-ID') 
            ?? (string) Str::uuid();

        // 2️⃣ Guardarlo en el request (para usarlo después)
        $request->headers->set('X-Correlation-ID', $correlationId);

        // 3️⃣ Agregarlo al contexto global de logs
        Log::withContext([
            'correlation_id' => $correlationId
        ]);

        // 4️⃣ Continuar request
        $response = $next($request);

        // 5️⃣ Devolver el mismo header en la respuesta
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}