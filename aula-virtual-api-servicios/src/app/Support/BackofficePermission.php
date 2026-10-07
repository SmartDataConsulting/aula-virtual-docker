<?php

namespace App\Support;

final class BackofficePermission
{
    private const ADMIN_PERMISSIONS = [
        'video.read', 'video.write',
        'materials.read', 'materials.write',
        'evaluations.read', 'evaluations.write',
        'announcements.read', 'announcements.write',
        'attendance.read', 'attendance.write',
    ];

    private const TEACHER_PERMISSIONS = [
        'video.read',
        'materials.read', 'materials.write',
        'evaluations.read',
        'announcements.read', 'announcements.write',
        'attendance.read',
    ];

    private const STUDENT_PERMISSIONS = ['materials.read'];

    public static function normalizeRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));

        if ($role === 'administrador') {
            return 'admin';
        }

        if ($role === 'profesor') {
            return 'docente';
        }

        return $role === 'student' ? 'alumno' : $role;
    }

    public static function allows(?string $role, string $permission): bool
    {
        $role = self::normalizeRole($role);

        if ($role === 'admin' || $role === 'operador') {
            return in_array($permission, self::ADMIN_PERMISSIONS, true);
        }

        if ($role === 'alumno') {
            return in_array($permission, self::STUDENT_PERMISSIONS, true);
        }

        return $role === 'docente' && in_array($permission, self::TEACHER_PERMISSIONS, true);
    }
}
