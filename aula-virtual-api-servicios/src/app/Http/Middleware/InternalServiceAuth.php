<?php

namespace App\Http\Middleware;

use Closure;

class InternalServiceAuth
{
    public function handle($request, Closure $next)
    {
        if ($request->header('X-INTERNAL-SERVICE-TOKEN') !== env('INTERNAL_SERVICE_TOKEN')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
