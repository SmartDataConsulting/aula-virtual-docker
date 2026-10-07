<?php

namespace Tests\Unit;

use App\Http\Middleware\CourseScopeMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Support\Facades\DB;
use Laravel\Lumen\Http\Request;
use Tests\TestCase;

class StudentMaterialScopeTest extends TestCase
{
    private array $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.connections.mysql_cursos');
        config(['database.connections.mysql_cursos' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('mysql_cursos');
        $db = DB::connection('mysql_cursos');
        $db->getPdo()->sqliteCreateCollation('utf8mb4_unicode_ci', 'strcasecmp');
        $db->statement('CREATE TABLE curso_edicion (id INTEGER, curso TEXT, edicion TEXT)');
        $db->statement('CREATE TABLE Ficha_inscripcion (curso TEXT, grupo TEXT, CORREO_PERSONAL TEXT, correo_corporativo TEXT)');
        $db->statement('CREATE TABLE curso_edicion_sesiones (id INTEGER, curso_edicion_id INTEGER)');
        $db->statement('CREATE TABLE curso_edicion_sesion_materiales (id INTEGER, curso_edicion_sesion_id INTEGER)');
        $db->table('curso_edicion')->insert([
            ['id' => 1, 'curso' => 'Course A', 'edicion' => 'Group 1'],
            ['id' => 2, 'curso' => 'Course B', 'edicion' => 'Group 1'],
            ['id' => 3, 'curso' => 'Course A', 'edicion' => 'Group 2'],
        ]);
        $db->table('Ficha_inscripcion')->insert([
            'curso' => 'Course A', 'grupo' => 'Group 1',
            'CORREO_PERSONAL' => 'student@example.invalid',
            'correo_corporativo' => 'corporate@example.invalid',
        ]);
        foreach ([1, 2, 3] as $id) {
            $db->table('curso_edicion_sesiones')->insert(['id' => $id * 10, 'curso_edicion_id' => $id]);
            $db->table('curso_edicion_sesion_materiales')->insert(['id' => $id * 100, 'curso_edicion_sesion_id' => $id * 10]);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_cursos');
        config(['database.connections.mysql_cursos' => $this->originalConnection]);
        parent::tearDown();
    }

    // 204 means the authorization pipeline continued, not that Drive downloaded a file.
    private function authorize(int $id, string $email = 'student@example.invalid', string $role = 'alumno', string $scope = 'material'): int
    {
        $request = Request::create('/v1/materiales/'.$id.'/descargar', 'GET');
        $request->headers->set('X-USER-ROL', $role);
        $request->headers->set('X-USER-EMAIL', $email);
        $request->setRouteResolver(fn () => [true, [], ['id' => $id, 'sesionId' => $id]]);
        $response = (new PermissionMiddleware())->handle($request,
            fn ($request) => (new CourseScopeMiddleware())->handle($request, fn () => response('', 204), $scope),
            'materials.read'
        );
        return $response->getStatusCode();
    }

    public function test_enrolled_student_and_alias_pass_permission_and_material_scope(): void
    {
        foreach (['alumno', 'student'] as $role) {
            self::assertSame(204, $this->authorize(100, ' STUDENT@EXAMPLE.INVALID ', $role));
        }
        $routes = $this->app->router->getRoutes();
        $route = $routes['GET/v1/materiales/{id}/descargar'];
        self::assertContains('permission:materials.read', $route['action']['middleware']);
        self::assertContains('course.scope:material', $route['action']['middleware']);
    }

    public function test_other_course_and_other_edition_are_denied(): void
    {
        self::assertSame(403, $this->authorize(200));
        self::assertSame(403, $this->authorize(300));
    }

    public function test_empty_identity_unenrolled_identity_and_corporate_email_are_denied(): void
    {
        foreach (['', ' ', 'other@example.invalid', 'corporate@example.invalid'] as $email) {
            self::assertSame(403, $this->authorize(100, $email));
        }
    }

    public function test_missing_material_and_orphan_session_are_denied(): void
    {
        self::assertSame(403, $this->authorize(999));
        DB::connection('mysql_cursos')->table('curso_edicion_sesiones')->where('id', 10)->delete();
        self::assertSame(403, $this->authorize(100));
    }

    public function test_session_material_list_also_requires_enrollment(): void
    {
        self::assertSame(204, $this->authorize(10, scope: 'session'));
        self::assertSame(403, $this->authorize(20, scope: 'session'));
        self::assertSame(403, $this->authorize(30, scope: 'session'));
    }

    public function test_database_failure_fails_closed(): void
    {
        DB::connection('mysql_cursos')->statement('DROP TABLE Ficha_inscripcion');
        self::assertSame(403, $this->authorize(100));
        DB::connection('mysql_cursos')->statement('DROP TABLE curso_edicion_sesion_materiales');
        self::assertSame(403, $this->authorize(100));
    }

    public function test_admin_and_operator_bypass_scope_unchanged(): void
    {
        foreach (['admin', 'administrador', 'operador'] as $role) {
            self::assertSame(204, $this->authorize(999, '', $role));
        }
    }

    public function test_api_video_delete_notifications_require_write_before_reaching_controller(): void
    {
        $routes = $this->app->router->getRoutes();
        foreach (['deleted', 'chat-deleted'] as $action) {
            // API uses POST metadata notifications; Portal owns the DELETE routes.
            $route = $routes['POST/v1/sesiones/{sesionId}/video/'.$action];
            self::assertContains('permission:video.write', $route['action']['middleware']);
            self::assertContains('course.scope:session', $route['action']['middleware']);
            foreach (['docente' => 403, 'admin' => 204] as $role => $expected) {
                $request = Request::create('/v1/sesiones/10/video/'.$action, 'POST');
                $request->headers->set('X-USER-ROL', $role);
                $called = false;
                $response = (new PermissionMiddleware())->handle($request, function () use (&$called) {
                    $called = true;
                    return response('', 204);
                }, 'video.write');
                self::assertSame($expected, $response->getStatusCode());
                self::assertSame($role === 'admin', $called);
            }
        }
    }
}
