<?php

namespace App\Repositories;

use App\Helpers\DbSafe;

class UsuarioRepository
{
    /**
     * Obtiene un usuario por email
     */
    public function obtenerPorEmail(string $email)
    {
        $sql = "
            SELECT
                id,
                colaborador_id,
                role_id,
                nombre,
                email,
                pass_hash,
                rol,
                activo,
                two_factor_enabled,
                two_factor_secret
            FROM usuario
            WHERE email = ?
            LIMIT 1
        ";

        $result = DbSafe::select('mysql_cursos', $sql, [$email]);

        return $result[0] ?? null;
    }

    /**
     * Obtiene un usuario por ID
     */
    public function obtenerPorId(int $userId)
    {
        $sql = "
            SELECT
                id,
                colaborador_id,
                role_id,
                nombre,
                email,
                pass_hash,
                rol,
                activo,
                two_factor_enabled,
                two_factor_secret
            FROM usuario
            WHERE id = ?
            LIMIT 1
        ";

        $result = DbSafe::select('mysql_cursos', $sql, [$userId]);

        return $result[0] ?? null;
    }

    /**
     * Obtiene solo el hash de contraseña (para autenticación)
     */
    public function obtenerHashPorEmail(string $email): ?string
    {
        $sql = "
            SELECT pass_hash
            FROM usuario
            WHERE email = ?
            LIMIT 1
        ";

        $result = DbSafe::select('mysql_cursos', $sql, [$email]);

        return $result[0]['pass_hash'] ?? null;
    }

    /**
     * Actualiza contraseña del usuario
     */
    public function updatePassword(int $userId, string $hash): void
    {
        $sql = "
            UPDATE usuario
            SET pass_hash = ?
            WHERE id = ?
        ";

        DbSafe::update('mysql_cursos', $sql, [$hash, $userId]);
    }

    /**
     * Actualiza último uso de 2FA
     */
    public function updateLast2fa(int $userId): void
    {
        $sql = "
            UPDATE usuario
            SET two_factor_last_used_at = NOW()
            WHERE id = ?
        ";

        DbSafe::update('mysql_cursos', $sql, [$userId]);
    }

    /**
     * Lista usuarios activos
     */
    public function listarActivos()
    {
        $sql = "
            SELECT
                id,
                nombre,
                email,
                rol,
                activo
            FROM usuario
            WHERE activo = 1
            ORDER BY nombre
        ";

        return DbSafe::select('mysql_cursos', $sql);
    }

    /**
     * Crea usuario
     */
    public function crear(array $data): void
    {
        $sql = "
            INSERT INTO usuario (
                colaborador_id,
                role_id,
                nombre,
                email,
                pass_hash,
                rol,
                activo
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";

        DbSafe::insert('mysql_cursos', $sql, [
            $data['colaborador_id'] ?? null,
            $data['role_id'] ?? null,
            $data['nombre'],
            $data['email'],
            $data['pass_hash'],
            $data['rol'],
            $data['activo'] ?? 1
        ]);
    }

    /**
     * Desactiva usuario
     */
    public function desactivar(int $userId): void
    {
        $sql = "
            UPDATE usuario
            SET activo = 0
            WHERE id = ?
        ";

        DbSafe::update('mysql_cursos', $sql, [$userId]);
    }
}