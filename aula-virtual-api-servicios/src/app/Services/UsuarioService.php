<?php

namespace App\Services;

use App\Repositories\UsuarioRepository;

class UsuarioService
{
    protected UsuarioRepository $repo;

    public function __construct(UsuarioRepository $repo)
    {
        $this->repo = $repo;
    }

    public function login(string $email, ?string $password): array
    {
        $usuario = $this->repo->obtenerPorEmail($email);

        if (!$usuario) {
            return ['status' => 'not_found', 'usuario' => null];
        }

        if (!$usuario->activo) {
            return ['status' => 'inactive', 'usuario' => null];
        }

        if (!$password || !password_verify($password, (string) $usuario->pass_hash)) {
            return ['status' => 'invalid_password', 'usuario' => null];
        }

        return ['status' => 'ok', 'usuario' => $usuario];
    }

    public function obtenerPorId(int $id)
    {
        return $this->repo->obtenerPorId($id);
    }
}
