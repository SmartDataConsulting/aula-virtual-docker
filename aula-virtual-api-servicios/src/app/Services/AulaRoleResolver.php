<?php

namespace App\Services;

use App\Helpers\DbSafe;
use App\Support\BackofficePermission;

class AulaRoleResolver
{
    private const LABOR_ROLE_CATALOG = 2;
    private const TEACHER_LABOR_ROLE = 1;

    public function systemRole(object $user): ?string
    {
        $role = BackofficePermission::normalizeRole($user->rol ?? null);
        if (in_array($role, ['admin', 'operador', 'docente', 'alumno'], true)) {
            return $role;
        }
        return match ((int) ($user->role_id ?? 0)) {
            1, 5 => 'admin', 2 => 'operador', 3 => 'docente', 4 => 'alumno', default => null,
        };
    }

    public function forUser(object $user): ?string
    {
        $role = $this->systemRole($user);
        if ($role !== 'operador') {
            return $role;
        }
        $collaboratorId = filter_var($user->colaborador_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($collaboratorId === false) {
            return null;
        }
        // Only the principal labor role classifies Aula identity, never the secondary role.
        $rows = DbSafe::select('mysql_cursos',
            'SELECT c.rol_maestro, c.rol_id FROM colaborador c
             JOIN parametros p ON p.id_maestro = c.rol_maestro AND p.id_valor = c.rol_id
             WHERE c.id_colaborador = ? AND c.rol_maestro = ? AND c.rol_id > 0 AND p.flg_activo = 1 LIMIT 1',
            [$collaboratorId, self::LABOR_ROLE_CATALOG]);
        $collaborator = $rows[0] ?? null;
        if ($collaborator === null) {
            return null;
        }
        return (int) $collaborator->rol_id === self::TEACHER_LABOR_ROLE ? 'docente' : 'operador';
    }

    public function forEmail(string $email): ?string
    {
        if (trim($email) === '') {
            return null;
        }
        // Authorization does not retrieve password hashes or 2FA secrets.
        $rows = DbSafe::select('mysql_cursos',
            'SELECT id, colaborador_id, rol, role_id, activo FROM usuario WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1',
            [trim($email)]);
        $user = $rows[0] ?? null;
        return $user && (int) $user->activo === 1 ? $this->forUser($user) : null;
    }
}
