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
        if ($permission === BackofficePermission::VIDEO_WRITE
            && BackofficePermission::allows($request->session()->get(AuthSessionKeys::AULA_ROLE), $permission)) {
            // Revalidate before any Portal-side Drive effects, including stale operator sessions.
            $identity = app(\App\Services\Http\ApiServiciosClient::class)->getAulaIdentity();
            $payload = $identity->data();
            $role = $identity->ok() && is_array($payload) ? ($payload['aula_role'] ?? null) : null;
            if (!in_array($role, ['admin', 'operador', 'docente', 'alumno'], true)) {
                abort(403, 'No se pudo verificar el perfil Aula.');
            }
            $request->session()->put(AuthSessionKeys::AULA_ROLE, $role);
        }
        if (!BackofficePermission::allows($request->session()->get(AuthSessionKeys::AULA_ROLE), $permission)) {
            abort(403, 'No autorizado para realizar esta accion.');
        }

        return $next($request);
    }
}
