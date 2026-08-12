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

        if (!$isLoggedIn) {
            Log::notice('Authentication required', [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
            ]);

            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
