<?php

namespace App\Http\Middleware;

use App\Support\AulaIdentity;
use Closure;
use Illuminate\Http\Request;

final class EnsureAulaIdentity
{
    public function handle(Request $request, Closure $next)
    {
        if (AulaIdentity::role($request) === null) {
            return response()->json(['ok' => false, 'message' => 'Identidad Aula no disponible'], 403);
        }
        return $next($request);
    }
}
