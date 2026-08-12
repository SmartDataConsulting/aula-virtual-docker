<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $rol = $request->header('X-USER-ROL');

        if (!$rol || !in_array($rol, $roles)) {
            return response()->json([
                'ok' => false,
                'message' => 'No autorizado'
            ], 403);
        }

        return $next($request);
    }
}