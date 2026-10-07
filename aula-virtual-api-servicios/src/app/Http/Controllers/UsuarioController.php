<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Routing\Controller as BaseController;
use App\Services\UsuarioService;

class UsuarioController extends BaseController
{
    protected UsuarioService $service;

    public function __construct(UsuarioService $service)
    {
        $this->service = $service;
    }

    /**
     * Login contra core
     */
    public function login()
    {
        $start = microtime(true);

        $email = request()->input('email');
        $password = request()->input('password');

        if (!$email) {
            return response()->json([
                'error' => 'email requerido'
            ], 400);
        }

        $login = $this->service->login($email, $password);
        $usuario = $login['usuario'] ?? null;

        if (($login['status'] ?? '') === 'not_found') {

            Log::warning('login_failed_core', [
                'reason' => 'not_found',
            ]);

            return response()->json([
                'error' => 'usuario no encontrado',
                'reason' => 'not_found',
            ], 404);
        }

        if (!$usuario) {

            Log::warning('login_failed_core', [
                'reason' => $login['status'] ?? 'invalid',
            ]);

            return response()->json([
                'error' => 'credenciales invalidas',
                'reason' => $login['status'] ?? 'invalid',
            ], 401);
        }

        $resolver = app(\App\Services\AulaRoleResolver::class);
        $role = $resolver->systemRole($usuario);

        if ($role === null) {
            Log::warning('login_failed_core', [
                'reason' => 'role_missing',
                'user_id' => $usuario->id,
                'role_id' => isset($usuario->role_id) ? (int) $usuario->role_id : null,
            ]);

            return response()->json([
                'error' => 'rol no configurado',
                'reason' => 'role_missing',
            ], 422);
        }

        try {
            $aulaRole = $resolver->forUser($usuario);
        } catch (\Throwable $exception) {
            Log::warning('aula_login_profile_resolution_failed', ['exception' => $exception::class]);
            $aulaRole = null;
        }
        if ($aulaRole === null) {
            return response()->json([
                'error' => 'No fue posible determinar el perfil de acceso al Aula Virtual.',
                'reason' => 'aula_profile_unresolved',
            ], 403);
        }

        $data = [
            'id' => (int) $usuario->id,
            'nombre' => $usuario->nombre,
            'email' => $usuario->email,
            'rol' => $role,
            'aula_role' => $aulaRole,
            'rol_original' => $usuario->rol ?? null,
            'role_id' => isset($usuario->role_id) ? (int) $usuario->role_id : null,
            'colaborador_id' => isset($usuario->colaborador_id) ? (int) $usuario->colaborador_id : null,
        ];

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('login_success_core', [
            'user_id' => $usuario->id,
            'ms' => $elapsed
        ]);

        return response()->json($data);
    }

    public function aulaIdentity()
    {
        return response()->json(['aula_role' => request()->attributes->get('canonical_aula_role')]);
    }
}
