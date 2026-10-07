<?php

namespace Tests\Unit;

use App\Support\BackofficePermission;
use PHPUnit\Framework\TestCase;

class BackofficePermissionTest extends TestCase
{
    public function test_students_receive_only_material_and_video_content_read_permissions(): void
    {
        foreach (['alumno', 'student'] as $role) {
            self::assertSame('alumno', BackofficePermission::normalizeRole($role));
            self::assertTrue(BackofficePermission::allows($role, 'materials.read'));
            self::assertTrue(BackofficePermission::allows($role, 'video.content.read'));
            foreach (['materials.write', 'video.read', 'video.write', 'evaluations.read', 'evaluations.write', 'announcements.read', 'announcements.write', 'attendance.read', 'attendance.write', 'unknown'] as $permission) {
                self::assertFalse(BackofficePermission::allows($role, $permission), "$role: $permission");
            }
        }
    }

    public function test_teacher_matrix_matches_read_only_and_editor_capabilities(): void
    {
        self::assertTrue(BackofficePermission::allows('profesor', BackofficePermission::VIDEO_READ));
        self::assertFalse(BackofficePermission::allows('profesor', BackofficePermission::VIDEO_WRITE));
        self::assertTrue(BackofficePermission::allows('docente', BackofficePermission::MATERIALS_WRITE));
        self::assertFalse(BackofficePermission::allows('docente', BackofficePermission::EVALUATIONS_WRITE));
        self::assertTrue(BackofficePermission::allows('docente', BackofficePermission::ANNOUNCEMENTS_WRITE));
        self::assertFalse(BackofficePermission::allows('docente', BackofficePermission::ATTENDANCE_WRITE));
    }

    public function test_administrator_alias_has_full_access(): void
    {
        self::assertTrue(BackofficePermission::allows('administrador', BackofficePermission::VIDEO_WRITE));
        self::assertTrue(BackofficePermission::allows('admin', BackofficePermission::ATTENDANCE_WRITE));
    }
}
