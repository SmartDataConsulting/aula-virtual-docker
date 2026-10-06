<?php

namespace App\Http\Middleware;

use App\Support\AuthSessionKeys;
use App\Support\BackofficePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureBackofficePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!BackofficePermission::allows($request->session()->get(AuthSessionKeys::USER_ROLE), $permission)) {
            abort(403, 'No autorizado para realizar esta accion.');
        }

        return $next($request);
    }
}
