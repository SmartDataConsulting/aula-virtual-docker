<?php

namespace App\Http\Middleware;

use App\Support\BackofficePermission;
use Closure;
use Illuminate\Http\Request;

final class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (!BackofficePermission::allows(\App\Support\AulaIdentity::role($request), $permission)) {
            return response()->json([
                'ok' => false,
                'message' => 'No autorizado',
            ], 403);
        }

        return $next($request);
    }
}
