<?php

namespace App\Http\Middleware;

use App\Support\BackofficePermission;
use Closure;
use Illuminate\Http\Request;

final class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (!BackofficePermission::allows($request->header('X-USER-ROL'), $permission)) {
            return response()->json([
                'ok' => false,
                'message' => 'No autorizado',
            ], 403);
        }

        return $next($request);
    }
}
