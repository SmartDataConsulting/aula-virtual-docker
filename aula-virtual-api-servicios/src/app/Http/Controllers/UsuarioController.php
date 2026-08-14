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

        $data = [
            'id' => (int) $usuario->id,
            'nombre' => $usuario->nombre,
            'email' => $usuario->email,
            'rol' => $usuario->rol,
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
}
