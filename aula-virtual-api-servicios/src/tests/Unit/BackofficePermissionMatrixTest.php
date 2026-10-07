<?php

namespace Tests\Unit;

use App\Support\BackofficePermission;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\CourseScopeMiddleware;
use Illuminate\Http\Request;
use Tests\TestCase;

class BackofficePermissionMatrixTest extends TestCase
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

    public function test_teacher_can_read_but_cannot_mutate_video_evaluations_or_attendance(): void
    {
        foreach (['video.read', 'evaluations.read', 'attendance.read'] as $permission) {
            self::assertTrue(BackofficePermission::allows('docente', $permission), $permission);
            self::assertTrue(BackofficePermission::allows('profesor', $permission), $permission);
        }

        foreach (['video.write', 'evaluations.write', 'attendance.write'] as $permission) {
            self::assertFalse(BackofficePermission::allows('docente', $permission), $permission);
            self::assertFalse(BackofficePermission::allows('profesor', $permission), $permission);
        }
    }

    public function test_teacher_can_mutate_materials_and_announcements(): void
    {
        self::assertTrue(BackofficePermission::allows('docente', 'materials.write'));
        self::assertTrue(BackofficePermission::allows('docente', 'announcements.write'));
    }

    public function test_admin_and_operator_keep_full_backoffice_permissions(): void
    {
        foreach (['video.write', 'materials.write', 'evaluations.write', 'announcements.write', 'attendance.write'] as $permission) {
            self::assertTrue(BackofficePermission::allows('admin', $permission), $permission);
            self::assertTrue(BackofficePermission::allows('administrador', $permission), $permission);
            self::assertTrue(BackofficePermission::allows('operador', $permission), $permission);
        }
    }

    public function test_routes_apply_the_expected_read_and_write_permissions(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        self::assertStringContainsString("'permission:video.write', 'course.scope:session'", $routes);
        self::assertStringContainsString("'permission:video.read', 'course.scope:session'", $routes);
        self::assertStringContainsString("'middleware' => 'permission:evaluations.write',", $routes);
        self::assertStringContainsString("'permission:attendance.write', 'course.scope:session'", $routes);
        self::assertStringContainsString("'permission:materials.write', 'course.scope:session'", $routes);
        self::assertStringContainsString("'permission:announcements.write', 'course.scope:announcement'", $routes);
    }

    public function test_permission_middleware_returns_403_for_teacher_write_and_allows_admin_write(): void
    {
        $middleware = new PermissionMiddleware();
        $teacherRequest = Request::create('/v1/sesiones/1/video/upload-started', 'POST');
        $teacherRequest->headers->set('X-USER-ROL', 'docente');

        $denied = $middleware->handle($teacherRequest, fn () => response()->json(['ok' => true]), 'video.write');
        self::assertSame(403, $denied->getStatusCode());

        $adminRequest = Request::create('/v1/sesiones/1/video/upload-started', 'POST');
        $adminRequest->headers->set('X-USER-ROL', 'admin');
        $adminRequest->headers->set('X-USER-EMAIL', 'admin@example.invalid');
        $resolver = \Mockery::mock(\App\Services\AulaRoleResolver::class);
        $resolver->shouldReceive('forEmail')->with('admin@example.invalid')->andReturn('admin');
        app()->instance(\App\Services\AulaRoleResolver::class, $resolver);
        $allowed = $middleware->handle($adminRequest, fn () => response()->json(['ok' => true]), 'video.write');
        self::assertSame(200, $allowed->getStatusCode());
    }

    public function test_course_scope_bypasses_admin_and_rejects_teacher_without_identity(): void
    {
        $middleware = new CourseScopeMiddleware();
        $admin = Request::create('/v1/cursos/10/evaluaciones', 'GET');
        $admin->headers->set('X-USER-ROL', 'administrador');
        $admin->headers->set('X-USER-EMAIL', 'admin@example.invalid');
        $resolver = \Mockery::mock(\App\Services\AulaRoleResolver::class);
        $resolver->shouldReceive('forEmail')->with('admin@example.invalid')->andReturn('admin');
        $resolver->shouldReceive('forEmail')->with('')->andReturn(null);
        app()->instance(\App\Services\AulaRoleResolver::class, $resolver);
        $allowed = $middleware->handle($admin, fn () => response()->json(['ok' => true]), 'course');
        self::assertSame(200, $allowed->getStatusCode());

        $teacher = Request::create('/v1/cursos/10/evaluaciones', 'GET');
        $teacher->headers->set('X-USER-ROL', 'profesor');
        $denied = $middleware->handle($teacher, fn () => response()->json(['ok' => true]), 'course');
        self::assertSame(403, $denied->getStatusCode());
    }
}
