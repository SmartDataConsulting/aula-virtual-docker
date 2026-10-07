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
        $db->statement('CREATE TABLE curso_edicion (id INTEGER, curso TEXT, edicion TEXT, docente_id_colaborador INTEGER, docente2_id_colaborador INTEGER)');
        $db->statement('CREATE TABLE Ficha_inscripcion (curso TEXT, grupo TEXT, CORREO_PERSONAL TEXT, correo_corporativo TEXT)');
        $db->statement('CREATE TABLE curso_edicion_sesiones (id INTEGER, curso_edicion_id INTEGER, docente_id INTEGER)');
        $db->statement("CREATE TABLE usuario (id INTEGER, email TEXT, colaborador_id INTEGER, rol TEXT DEFAULT 'docente', role_id INTEGER DEFAULT 3, activo INTEGER DEFAULT 1)");
        $db->statement('CREATE TABLE colaborador (id_colaborador INTEGER, rol_maestro INTEGER, rol_id INTEGER)');
        $db->statement('CREATE TABLE parametros (id_maestro INTEGER, id_valor INTEGER, flg_activo INTEGER)');
        $db->table('parametros')->insert(['id_maestro' => 2, 'id_valor' => 4, 'flg_activo' => 1]);
        $db->table('colaborador')->insert(['id_colaborador' => 99, 'rol_maestro' => 2, 'rol_id' => 4]);
        $db->table('usuario')->insert([
            ['email' => 'admin@example.invalid', 'rol' => 'admin', 'role_id' => 1, 'colaborador_id' => null],
            ['email' => 'operador@example.invalid', 'rol' => 'operador', 'role_id' => 2, 'colaborador_id' => 99],
        ]);
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
    private function authorize(int $id, string $email = 'student@example.invalid', string $role = 'alumno', string $scope = 'material', string $permission = 'materials.read'): int
    {
        $request = Request::create('/v1/materiales/'.$id.'/descargar', 'GET');
        $request->headers->set('X-USER-ROL', $role);
        $request->headers->set('X-USER-EMAIL', $email);
        if (in_array($role, ['admin', 'administrador', 'operador'], true)) {
            $request->headers->set('X-AULA-ACTOR-EMAIL', $role === 'operador' ? 'operador@example.invalid' : 'admin@example.invalid');
        }
        $request->setRouteResolver(fn () => [true, [], ['id' => $id, 'sesionId' => $id, 'cursoId' => $id]]);
        $response = (new PermissionMiddleware())->handle($request,
            fn ($request) => (new CourseScopeMiddleware())->handle($request, fn () => response('', 204), $scope),
            $permission
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

    public function test_video_content_requires_student_enrollment_and_never_grants_management(): void
    {
        foreach (['alumno', 'student'] as $role) {
            self::assertSame(204, $this->authorize(10, role: $role, scope: 'session', permission: 'video.content.read'));
            self::assertSame(403, $this->authorize(20, role: $role, scope: 'session', permission: 'video.content.read'));
            self::assertSame(403, $this->authorize(30, role: $role, scope: 'session', permission: 'video.content.read'));
            self::assertSame(403, $this->authorize(10, '', $role, 'session', 'video.content.read'));
            self::assertSame(403, $this->authorize(999, role: $role, scope: 'session', permission: 'video.content.read'));
            foreach (['video.read', 'video.write'] as $permission) {
                self::assertSame(403, $this->authorize(10, role: $role, scope: 'session', permission: $permission));
            }
        }
        $route = $this->app->router->getRoutes()['GET/v1/sesiones/{sesionId}/video/content'];
        self::assertContains('permission:video.content.read', $route['action']['middleware']);
        self::assertContains('course.scope:session', $route['action']['middleware']);
        self::assertContains('internal.auth', $route['action']['middleware']);
    }

    public function test_video_content_keeps_primary_second_and_session_teacher_course_scope(): void
    {
        $db = DB::connection('mysql_cursos');
        $db->table('curso_edicion')->where('id', 1)->update(['docente_id_colaborador' => 1, 'docente2_id_colaborador' => 2]);
        $db->table('curso_edicion_sesiones')->where('id', 10)->update(['docente_id' => 3]);
        $db->table('curso_edicion_sesiones')->insert(['id' => 11, 'curso_edicion_id' => 1]);
        foreach ([1, 2, 3, 4] as $id) {
            $db->table('usuario')->insert(['email' => 'teacher'.$id.'@example.invalid', 'colaborador_id' => $id]);
            $expected = $id === 4 ? 403 : 204;
            self::assertSame($expected, $this->authorize(11, 'teacher'.$id.'@example.invalid', 'docente', 'session', 'video.content.read'));
            self::assertSame(403, $this->authorize(11, 'teacher'.$id.'@example.invalid', 'docente', 'session', 'video.write'));
        }
    }

    public function test_real_content_route_applies_scope_before_controller(): void
    {
        // Isolate internal transport authentication, not permission/enrollment middleware.
        $auth = \Mockery::mock(\App\Http\Middleware\InternalServiceAuth::class);
        $auth->shouldReceive('handle')->andReturnUsing(fn ($request, $next) => $next($request));
        $this->app->instance(\App\Http\Middleware\InternalServiceAuth::class, $auth);
        $service = \Mockery::mock(\App\Services\SesionVideoService::class);
        $service->shouldReceive('getVideoContent')->once()->with(10)
            ->andReturn(['status' => 'ready', 'file_id' => 'fixture-video', 'chat' => ['file_id' => 'fixture-chat']]);
        $this->app->instance(\App\Services\SesionVideoService::class, $service);
        $headers = ['X-USER-ROL' => 'alumno', 'X-USER-EMAIL' => 'student@example.invalid'];
        $this->get('/v1/sesiones/10/video/content', $headers);
        $this->assertResponseStatus(200);
        $this->seeJson(['file_id' => 'fixture-video']);
        $this->get('/v1/sesiones/20/video/content', $headers);
        $this->assertResponseStatus(403);
    }

    public function test_existing_session_reads_cannot_expose_foreign_recording_ids(): void
    {
        self::assertSame(204, $this->authorize(1, scope: 'course', permission: 'video.content.read'));
        self::assertSame(403, $this->authorize(2, scope: 'course', permission: 'video.content.read'));
        self::assertSame(403, $this->authorize(3, scope: 'course', permission: 'video.content.read'));
        $routes = $this->app->router->getRoutes();
        foreach ([
            'GET/v1/curso/{cursoId}/sesiones' => 'course.scope:course',
            'GET/v1/alumno/cursos/{cursoId}/sesiones/light' => 'course.scope:course',
            'GET/v1/alumno/cursos/{cursoId}/sesiones/{sesionId}/detalle' => 'course.scope:session',
        ] as $route => $scope) {
            self::assertContains($scope, $routes[$route]['action']['middleware']);
        }
    }

    public function test_content_service_returns_only_metadata_without_drive_or_db_writes(): void
    {
        $repo = \Mockery::mock(\App\Repositories\SesionVideoUploadRepository::class);
        $repo->shouldReceive('getVideoStatus')->once()->with(10)->andReturn([
            'status' => 'ready', 'file_id' => 'fixture-video', 'upload_url' => 'discarded-fixture',
            'chat' => ['file_id' => 'fixture-chat', 'title' => 'class.txt', 'upload_url' => 'discarded-fixture'],
        ]);
        $repo->shouldNotReceive('updateVideoStatus');
        $drive = \Mockery::mock(\App\Helpers\GoogleDriveHelper::class);
        $drive->shouldNotReceive('getVideoStatus');
        $service = new \App\Services\SesionVideoService($repo, \Mockery::mock(\App\Repositories\SesionRepository::class), $drive);
        self::assertSame([
            'status' => 'ready', 'file_id' => 'fixture-video',
            'chat' => ['file_id' => 'fixture-chat', 'title' => 'class.txt'],
        ], $service->getVideoContent(10));
    }

    public function test_readers_observe_processing_to_ready_without_writes_and_chat_survives_drive_outage(): void
    {
        $repo = \Mockery::mock(\App\Repositories\SesionVideoUploadRepository::class);
        $repo->shouldReceive('getVideoStatus')->twice()->with(10)->andReturn([
            'status' => 'processing', 'file_id' => 'fixture-video', 'chat' => ['file_id' => 'fixture-chat'],
        ]);
        $repo->shouldNotReceive('updateVideoStatus');
        $sessions = \Mockery::mock(\App\Repositories\SesionRepository::class);
        $drive = \Mockery::mock(\App\Helpers\GoogleDriveHelper::class);
        $drive->shouldReceive('getVideoStatus')->once()->with('fixture-video')->andReturn(['status' => 'ready']);
        $service = new \App\Services\SesionVideoService($repo, $sessions, $drive);
        $readyContent = $service->getVideoContent(10);
        self::assertSame('ready', $readyContent['status']);
        self::assertSame('fixture-video', $readyContent['file_id']);
        $failedDrive = \Mockery::mock(\App\Helpers\GoogleDriveHelper::class);
        $failedDrive->shouldReceive('getVideoStatus')->once()->with('fixture-video')->andThrow(new \RuntimeException('Fixture unavailable'));
        $degradedService = new \App\Services\SesionVideoService($repo, $sessions, $failedDrive);
        $content = $degradedService->getVideoContent(10);
        self::assertSame('processing', $content['status']);
        self::assertNull($content['file_id']);
        self::assertSame(['file_id' => 'fixture-chat'], $content['chat']);
    }

    public function test_unready_final_states_never_expose_recording_id_and_keep_chat(): void
    {
        foreach (['processing', 'uploaded', 'completed'] as $status) {
            $repo = \Mockery::mock(\App\Repositories\SesionVideoUploadRepository::class);
            $repo->shouldReceive('getVideoStatus')->once()->with(10)->andReturn([
                'status' => $status, 'file_id' => 'fixture-video', 'chat' => ['file_id' => 'fixture-chat'],
            ]);
            $repo->shouldNotReceive('updateVideoStatus');
            $drive = \Mockery::mock(\App\Helpers\GoogleDriveHelper::class);
            $drive->shouldReceive('getVideoStatus')->once()->with('fixture-video')->andReturn(['status' => $status]);
            // Strict mocks permit only the reads above, so any extra write fails the test.
            $service = new \App\Services\SesionVideoService($repo, \Mockery::mock(\App\Repositories\SesionRepository::class), $drive);
            $content = $service->getVideoContent(10);
            self::assertSame($status, $content['status']);
            self::assertNull($content['file_id']);
            self::assertSame(['file_id' => 'fixture-chat'], $content['chat']);
        }
    }

    public function test_api_video_delete_notifications_require_write_before_reaching_controller(): void
    {
        $routes = $this->app->router->getRoutes();
        foreach (['deleted', 'chat-deleted', 'upload-started', 'chat-uploaded', 'upload-cancelled', 'upload-completed'] as $action) {
            // API uses POST metadata notifications; Portal owns the DELETE routes.
            $route = $routes['POST/v1/sesiones/{sesionId}/video/'.$action];
            self::assertContains('permission:video.write', $route['action']['middleware']);
            self::assertContains('course.scope:session', $route['action']['middleware']);
            foreach (['docente' => 403, 'admin' => 204] as $role => $expected) {
                $request = Request::create('/v1/sesiones/10/video/'.$action, 'POST');
                $request->headers->set('X-USER-ROL', $role);
                if ($role === 'admin') {
                    $request->headers->set('X-AULA-ACTOR-EMAIL', 'admin@example.invalid');
                }
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
