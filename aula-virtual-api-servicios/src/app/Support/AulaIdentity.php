<?php

namespace App\Support;

use App\Services\AulaRoleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class AulaIdentity
{
    // internal.auth authenticates the calling service, not the end user.
    // Actor headers are assertions from that trusted service; lookup determines access, not login.
    public static function role(Request $request): ?string
    {
        if ($request->attributes->has('canonical_aula_role')) {
            return $request->attributes->get('canonical_aula_role');
        }
        $claimed = BackofficePermission::normalizeRole($request->header('X-USER-ROL'));
        // WordPress students have no Core account; enrollment remains their authority.
        if ($claimed === 'alumno') {
            $role = 'alumno';
        } else {
            $actor = trim((string) $request->header('X-AULA-ACTOR-EMAIL', $request->header('X-USER-EMAIL', '')));
            try {
                $role = app(AulaRoleResolver::class)->forEmail($actor);
            } catch (\Throwable $exception) {
                Log::warning('aula_identity_resolution_failed', ['exception' => $exception::class]);
                $role = null;
            }
            if ($role === 'docente') {
                // Empty listing overrides must never turn teachers into unscoped readers.
                $request->headers->set('X-USER-EMAIL', $actor);
                $request->query->set('correo', $actor);
                $request->request->set('correo', $actor);
            }
        }
        $request->attributes->set('canonical_aula_role', $role);
        if ($role !== null) {
            $request->attributes->set('system_role_header', $request->header('X-USER-ROL'));
            // Only Aula-protected routes consume the verified profile; legacy routes retain their role.
            $request->headers->set('X-USER-ROL', $role);
        }
        return $role;
    }
}
