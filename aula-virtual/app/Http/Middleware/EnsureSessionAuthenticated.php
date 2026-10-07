<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use App\Support\AuthSessionKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica sesión antes de permitir el acceso.
 */
class EnsureSessionAuthenticated
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $isLoggedIn = (bool) $request->session()->get(AuthSessionKeys::LOGGED_IN, false);

        if (!$isLoggedIn || !in_array(\App\Support\BackofficePermission::normalizeRole($request->session()->get(AuthSessionKeys::AULA_ROLE)), ['admin', 'operador', 'docente', 'alumno'], true)) {
            // Old sessions must reauthenticate instead of inheriting operator powers.
            $request->session()->forget(AuthSessionKeys::all());
            Log::notice('Authentication required', [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
            ]);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Tu sesion requiere iniciar sesion nuevamente.',
                    'code' => 'reauthentication_required',
                ], 401);
            }
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
