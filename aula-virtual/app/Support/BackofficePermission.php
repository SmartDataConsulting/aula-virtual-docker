<?php

namespace App\Support;

final class BackofficePermission
{
    public const VIDEO_READ = 'video.read';
    public const VIDEO_WRITE = 'video.write';
    public const MATERIALS_READ = 'materials.read';
    public const MATERIALS_WRITE = 'materials.write';
    public const EVALUATIONS_READ = 'evaluations.read';
    public const EVALUATIONS_WRITE = 'evaluations.write';
    public const ANNOUNCEMENTS_READ = 'announcements.read';
    public const ANNOUNCEMENTS_WRITE = 'announcements.write';
    public const ATTENDANCE_READ = 'attendance.read';
    public const ATTENDANCE_WRITE = 'attendance.write';

    private const ADMIN_PERMISSIONS = [
        self::VIDEO_READ, self::VIDEO_WRITE,
        self::MATERIALS_READ, self::MATERIALS_WRITE,
        self::EVALUATIONS_READ, self::EVALUATIONS_WRITE,
        self::ANNOUNCEMENTS_READ, self::ANNOUNCEMENTS_WRITE,
        self::ATTENDANCE_READ, self::ATTENDANCE_WRITE,
    ];

    private const TEACHER_PERMISSIONS = [
        self::VIDEO_READ,
        self::MATERIALS_READ, self::MATERIALS_WRITE,
        self::EVALUATIONS_READ,
        self::ANNOUNCEMENTS_READ, self::ANNOUNCEMENTS_WRITE,
        self::ATTENDANCE_READ,
    ];

    private const STUDENT_PERMISSIONS = [self::MATERIALS_READ];

    public static function normalizeRole(?string $role): string
    {
        return match (strtolower(trim((string) $role))) {
            'administrador' => 'admin',
            'profesor' => 'docente',
            'student' => 'alumno',
            default => strtolower(trim((string) $role)),
        };
    }

    public static function allows(?string $role, string $permission): bool
    {
        return match (self::normalizeRole($role)) {
            'admin', 'operador' => in_array($permission, self::ADMIN_PERMISSIONS, true),
            'docente' => in_array($permission, self::TEACHER_PERMISSIONS, true),
            'alumno' => in_array($permission, self::STUDENT_PERMISSIONS, true),
            default => false,
        };
    }
}
