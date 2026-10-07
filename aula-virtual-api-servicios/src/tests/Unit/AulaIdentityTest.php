<?php

namespace Tests\Unit;

use App\Http\Middleware\CourseScopeMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Services\AulaRoleResolver;
use App\Services\UsuarioService;
use Illuminate\Support\Facades\DB;
use Laravel\Lumen\Http\Request;
use Tests\TestCase;

class AulaIdentityTest extends TestCase
{
    private array $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.connections.mysql_cursos');
        config(['database.connections.mysql_cursos' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('mysql_cursos');
        $db = DB::connection('mysql_cursos');
        $db->statement('CREATE TABLE usuario (id INTEGER, email TEXT, rol TEXT, role_id INTEGER, colaborador_id INTEGER, activo INTEGER)');
        $db->statement('CREATE TABLE colaborador (id_colaborador INTEGER, rol_maestro INTEGER, rol_id INTEGER, rol2_maestro INTEGER, rol2_id INTEGER)');
        $db->statement('CREATE TABLE parametros (id_maestro INTEGER, id_valor INTEGER, flg_activo INTEGER DEFAULT 1)');
        foreach ([1, 4, 9, 10] as $laborRole) {
            $db->table('parametros')->insert(['id_maestro' => 2, 'id_valor' => $laborRole]);
        }
        $db->statement('CREATE TABLE curso_edicion (id INTEGER, docente_id_colaborador INTEGER, docente2_id_colaborador INTEGER)');
        $db->statement('CREATE TABLE curso_edicion_sesiones (id INTEGER, curso_edicion_id INTEGER, docente_id INTEGER)');
        $db->statement('CREATE TABLE curso_edicion_sesion_materiales (id INTEGER, curso_edicion_sesion_id INTEGER)');
        $db->statement('CREATE TABLE evaluacion (id INTEGER, curso_id INTEGER)');
        $db->statement('CREATE TABLE evaluacion_rendicion (id INTEGER, evaluacion_id INTEGER)');
        $db->statement('CREATE TABLE evaluacion_rendicion_trabajo (archivo_id INTEGER, rendicion_id INTEGER, activo INTEGER)');
        $db->statement('CREATE TABLE evaluacion_subsanacion (evaluacion_id INTEGER, evidencia_archivo TEXT)');
        $db->statement('CREATE TABLE curso_edicion_anuncios (id INTEGER, entidad_tipo TEXT, entidad_id INTEGER, activo INTEGER)');
        foreach ([1 => [2, 1, 9], 2 => [2, 4, 1], 3 => [2, 10, null], 4 => [99, 1, null]] as $id => $roles) {
            $db->table('colaborador')->insert(['id_colaborador' => $id, 'rol_maestro' => $roles[0], 'rol_id' => $roles[1], 'rol2_maestro' => 2, 'rol2_id' => $roles[2]]);
            $db->table('usuario')->insert(['id' => $id, 'email' => 'actor'.$id.'@example.invalid', 'rol' => 'operador', 'role_id' => 2, 'colaborador_id' => $id, 'activo' => 1]);
        }
        $db->table('usuario')->insert([
            ['id' => 5, 'email' => 'admin@example.invalid', 'rol' => 'admin', 'role_id' => 1, 'colaborador_id' => 1, 'activo' => 1],
            ['id' => 6, 'email' => 'legacy@example.invalid', 'rol' => 'docente', 'role_id' => 3, 'colaborador_id' => 6, 'activo' => 1],
        ]);
        $db->table('curso_edicion')->insert(['id' => 10, 'docente_id_colaborador' => 1, 'docente2_id_colaborador' => null]);
        $db->table('curso_edicion_sesiones')->insert([
            ['id' => 100, 'curso_edicion_id' => 10, 'docente_id' => 6],
            ['id' => 101, 'curso_edicion_id' => 10, 'docente_id' => null],
        ]);
        $db->table('curso_edicion_sesion_materiales')->insert(['id' => 20, 'curso_edicion_sesion_id' => 101]);
        $db->table('evaluacion')->insert(['id' => 30, 'curso_id' => 10]);
        $db->table('evaluacion_rendicion')->insert(['id' => 50, 'evaluacion_id' => 30]);
        $db->table('evaluacion_rendicion_trabajo')->insert(['archivo_id' => 60, 'rendicion_id' => 50, 'activo' => 1]);
        $db->table('evaluacion_subsanacion')->insert(['evaluacion_id' => 30, 'evidencia_archivo' => 'subsanaciones/fixture.txt']);
        $db->table('curso_edicion_anuncios')->insert(['id' => 40, 'entidad_tipo' => 'curso', 'entidad_id' => 10, 'activo' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_cursos');
        config(['database.connections.mysql_cursos' => $this->originalConnection]);
        parent::tearDown();
    }

    public function test_principal_labor_role_is_canonical_and_secondary_teacher_does_not_demote_operator(): void
    {
        $resolver = app(AulaRoleResolver::class);
        foreach ([1 => 'docente', 2 => 'operador', 3 => 'operador', 4 => null] as $id => $expected) {
            self::assertSame($expected, $resolver->forEmail('actor'.$id.'@example.invalid'));
        }
        self::assertSame('admin', $resolver->forEmail('admin@example.invalid'));
        self::assertSame('docente', $resolver->forEmail('legacy@example.invalid'));
        self::assertSame('alumno', $resolver->forUser((object) ['rol' => 'alumno', 'colaborador_id' => 1]));
        self::assertNull($resolver->forEmail('unknown@example.invalid'));
        DB::connection('mysql_cursos')->table('usuario')->where('id', 1)->update(['activo' => 0]);
        self::assertNull($resolver->forEmail('actor1@example.invalid'));
    }

    public function test_login_retains_system_role_and_returns_additive_aula_profile(): void
    {
        $user = (object) ['id' => 1, 'nombre' => 'Fixture Actor', 'email' => 'actor1@example.invalid', 'rol' => 'operador', 'role_id' => 2, 'colaborador_id' => 1];
        $service = $this->createMock(UsuarioService::class);
        $service->expects(self::once())->method('login')->willReturn(['status' => 'ok', 'usuario' => $user]);
        $this->app->instance(UsuarioService::class, $service);
        $this->post('/v1/login', ['email' => $user->email, 'password' => 'fixture-password']);
        $this->assertResponseStatus(200);
        $this->seeJson(['rol' => 'operador', 'aula_role' => 'docente', 'colaborador_id' => 1]);
    }

    public function test_indeterminate_operators_never_inherit_administrative_permissions_or_scope(): void
    {
        $db = DB::connection('mysql_cursos');
        $cases = [
            [null, 2, 4], [999, 2, 4], [-1, 2, 4], [0, 2, 4], ['invalid', 2, 4],
            [2, null, 4], [2, 2, null], [2, 99, 4], [2, 2, 0], [2, 2, 999],
        ];
        foreach ($cases as [$collaboratorId, $catalog, $laborRole]) {
            $db->table('usuario')->where('id', 2)->update(['colaborador_id' => $collaboratorId]);
            $db->table('colaborador')->where('id_colaborador', 2)->update(['rol_maestro' => $catalog, 'rol_id' => $laborRole]);
            self::assertNull(app(AulaRoleResolver::class)->forEmail('actor2@example.invalid'));
            self::assertSame(403, $this->authorize('video.write', email: 'actor2@example.invalid')[0]);
            $request = Request::create('/v1/cursos/10', 'GET');
            $request->headers->set('X-USER-ROL', 'operador');
            $request->headers->set('X-AULA-ACTOR-EMAIL', 'actor2@example.invalid');
            $response = (new CourseScopeMiddleware())->handle($request, function () {
                self::fail('An indeterminate actor reached the scoped controller.');
            });
            self::assertSame(403, $response->getStatusCode());
        }
    }

    public function test_inactive_labor_classification_is_indeterminate_and_secondary_role_never_repairs_it(): void
    {
        DB::connection('mysql_cursos')->table('parametros')->where('id_valor', 4)->update(['flg_activo' => 0]);
        self::assertNull(app(AulaRoleResolver::class)->forEmail('actor2@example.invalid'));
    }

    public function test_primary_teacher_with_null_secondary_and_non_operator_profiles_do_not_need_collaborator_lookup(): void
    {
        DB::connection('mysql_cursos')->table('colaborador')->where('id_colaborador', 1)->update(['rol2_id' => null]);
        self::assertSame('docente', app(AulaRoleResolver::class)->forEmail('actor1@example.invalid'));
        DB::connection('mysql_cursos')->statement('DROP TABLE colaborador');
        foreach (['admin', 'alumno', 'docente'] as $role) {
            self::assertSame($role, app(AulaRoleResolver::class)->forUser((object) ['rol' => $role, 'colaborador_id' => null]));
        }
    }

    public function test_login_rejects_unresolved_profile_without_reporting_bad_credentials_or_using_role_id(): void
    {
        $user = (object) ['id' => 7, 'nombre' => 'Fixture', 'email' => 'missing@example.invalid', 'rol' => 'operador', 'role_id' => 1, 'colaborador_id' => null];
        $service = $this->createMock(UsuarioService::class);
        $service->method('login')->willReturn(['status' => 'ok', 'usuario' => $user]);
        $this->app->instance(UsuarioService::class, $service);
        $this->post('/v1/login', ['email' => $user->email, 'password' => 'fixture-password']);
        $this->assertResponseStatus(403);
        $this->seeJson(['reason' => 'aula_profile_unresolved']);
        self::assertArrayNotHasKey('aula_role', json_decode($this->response->getContent(), true));
        DB::connection('mysql_cursos')->statement('DROP TABLE colaborador');
        $user->colaborador_id = 1;
        $this->post('/v1/login', ['email' => $user->email, 'password' => 'fixture-password']);
        $this->assertResponseStatus(403);
        $this->seeJson(['reason' => 'aula_profile_unresolved']);
    }

    public function test_actual_video_mutations_deny_an_indeterminate_operator_before_the_controller(): void
    {
        DB::connection('mysql_cursos')->table('usuario')->where('id', 2)->update(['colaborador_id' => null]);
        $auth = \Mockery::mock(\App\Http\Middleware\InternalServiceAuth::class);
        $auth->shouldReceive('handle')->andReturnUsing(fn ($request, $next) => $next($request));
        $this->app->instance(\App\Http\Middleware\InternalServiceAuth::class, $auth);
        $service = \Mockery::mock(\App\Services\SesionVideoService::class);
        foreach (['registerUploadStart', 'updateUploadProgress', 'finalizeUpload', 'markUploadError', 'cancelUpload', 'updateVideoStatus', 'deleteVideoRecord', 'registerVideoChat', 'deleteVideoChat'] as $method) {
            $service->shouldNotReceive($method);
        }
        $this->app->instance(\App\Services\SesionVideoService::class, $service);
        foreach (['upload-started', 'upload-progress', 'upload-completed', 'upload-error', 'upload-cancelled', 'status-updated', 'deleted', 'chat-uploaded', 'chat-deleted'] as $action) {
            $this->post('/v1/sesiones/101/video/'.$action, [], ['X-USER-ROL' => 'operador', 'X-AULA-ACTOR-EMAIL' => 'actor2@example.invalid']);
            $this->assertResponseStatus(403);
        }
    }

    private function authorize(string $permission, string $scope = 'session', string $email = 'actor1@example.invalid'): array
    {
        $request = Request::create('/v1/fixture?correo=&path=subsanaciones/fixture.txt', 'POST');
        $request->headers->set('X-USER-ROL', 'operador');
        $request->headers->set('X-USER-AULA-ROLE', 'admin');
        $request->headers->set('X-USER-EMAIL', '');
        $request->headers->set('X-AULA-ACTOR-EMAIL', $email);
        $request->setRouteResolver(fn () => [true, [], ['sesionId' => 101, 'cursoId' => 10, 'id' => 20, 'evaluacionId' => 30, 'anuncioId' => 40, 'archivoId' => 60]]);
        $called = false;
        $response = (new PermissionMiddleware())->handle($request, function ($request) use ($scope, &$called) {
            return (new CourseScopeMiddleware())->handle($request, function () use (&$called) {
                $called = true;
                return response('', 204);
            }, $scope);
        }, $permission);
        return [$response->getStatusCode(), $called, $request];
    }

    public function test_forged_admin_headers_cannot_grant_teacher_write_and_all_required_module_permissions_hold(): void
    {
        foreach (['video.read', 'video.content.read', 'materials.read', 'materials.write', 'announcements.read', 'announcements.write', 'evaluations.read', 'attendance.read'] as $permission) {
            [$status, $called, $request] = $this->authorize($permission);
            self::assertSame(204, $status, $permission);
            self::assertTrue($called);
            self::assertSame('docente', $request->header('X-USER-ROL'));
            self::assertSame('actor1@example.invalid', $request->header('X-USER-EMAIL'));
            self::assertSame('actor1@example.invalid', $request->query('correo'));
        }
        foreach (['video.write', 'evaluations.write', 'attendance.write'] as $permission) {
            [$status, $called] = $this->authorize($permission);
            self::assertSame(403, $status, $permission);
            self::assertFalse($called);
        }
        foreach (['material' => 'materials.write', 'announcement' => 'announcements.write', 'evaluation' => 'evaluations.read'] as $scope => $permission) {
            self::assertSame(204, $this->authorize($permission, $scope)[0]);
        }
        foreach (['actor2@example.invalid', 'actor3@example.invalid', 'admin@example.invalid'] as $email) {
            foreach (['video.write', 'materials.write', 'announcements.write', 'evaluations.write', 'attendance.write'] as $permission) {
                self::assertSame(204, $this->authorize($permission, email: $email)[0]);
            }
        }
    }

    public function test_effective_teacher_scope_covers_principal_second_and_any_session_but_not_unrelated_courses(): void
    {
        self::assertSame(204, $this->authorize('video.content.read')[0]);
        $db = DB::connection('mysql_cursos');
        $db->table('curso_edicion')->where('id', 10)->update(['docente_id_colaborador' => null, 'docente2_id_colaborador' => 1]);
        self::assertSame(204, $this->authorize('video.content.read')[0]);
        $db->table('curso_edicion')->where('id', 10)->update(['docente2_id_colaborador' => null]);
        $db->table('curso_edicion_sesiones')->where('id', 100)->update(['docente_id' => 1]);
        self::assertSame(204, $this->authorize('video.content.read')[0]);
        $db->table('curso_edicion_sesiones')->where('id', 100)->update(['docente_id' => null]);
        foreach (['video.read', 'materials.write', 'announcements.write', 'evaluations.read', 'attendance.read'] as $permission) {
            self::assertSame(403, $this->authorize($permission)[0]);
        }
        self::assertSame(403, $this->authorize('video.write', email: 'unknown@example.invalid')[0]);
    }

    public function test_actual_api_video_write_routes_deny_before_controller_even_with_operator_claim(): void
    {
        $auth = \Mockery::mock(\App\Http\Middleware\InternalServiceAuth::class);
        $auth->shouldReceive('handle')->andReturnUsing(fn ($request, $next) => $next($request));
        $this->app->instance(\App\Http\Middleware\InternalServiceAuth::class, $auth);
        $service = \Mockery::mock(\App\Services\SesionVideoService::class);
        foreach (['registerUploadStart', 'updateUploadProgress', 'finalizeUpload', 'markUploadError', 'cancelUpload', 'updateVideoStatus', 'deleteVideoRecord', 'registerVideoChat', 'deleteVideoChat'] as $method) {
            $service->shouldNotReceive($method);
        }
        $this->app->instance(\App\Services\SesionVideoService::class, $service);
        foreach (['upload-started', 'upload-progress', 'upload-completed', 'upload-error', 'upload-cancelled', 'status-updated', 'deleted', 'chat-uploaded', 'chat-deleted'] as $action) {
            $this->post('/v1/sesiones/101/video/'.$action, [], ['X-USER-ROL' => 'operador', 'X-USER-EMAIL' => 'actor1@example.invalid']);
            $this->assertResponseStatus(403);
        }
    }

    public function test_internal_preflight_and_course_summary_use_canonical_identity_not_scope_overrides(): void
    {
        $auth = \Mockery::mock(\App\Http\Middleware\InternalServiceAuth::class);
        $auth->shouldReceive('handle')->andReturnUsing(fn ($request, $next) => $next($request));
        $this->app->instance(\App\Http\Middleware\InternalServiceAuth::class, $auth);
        $headers = ['X-USER-ROL' => 'admin', 'X-USER-EMAIL' => '', 'X-AULA-ACTOR-EMAIL' => 'actor1@example.invalid'];
        $this->get('/v1/aula/identity', $headers);
        $this->assertResponseStatus(200);
        $this->seeJson(['aula_role' => 'docente']);
        $courses = \Mockery::mock(\App\Services\CursoService::class);
        $courses->shouldReceive('listarCursosBackoffice')->once()->with('actor1@example.invalid', 'docente')->andReturn([]);
        $this->app->instance(\App\Services\CursoService::class, $courses);
        $this->get('/v1/backoffice/resumen?correo=', $headers);
        $this->assertResponseStatus(200);
        $this->seeJson(['ok' => true, 'courses' => []]);
    }

    public function test_identity_query_failure_is_closed_without_running_mutation_callback(): void
    {
        DB::connection('mysql_cursos')->statement('DROP TABLE colaborador');
        [$status, $called] = $this->authorize('video.write');
        self::assertSame(403, $status);
        self::assertFalse($called);
    }

    public function test_material_and_announcement_crud_routes_all_apply_permission_and_course_scope(): void
    {
        $routes = $this->app->router->getRoutes();
        foreach ([
            'GET/v1/sesiones/{sesionId}/materiales' => 'materials.read',
            'POST/v1/sesiones/{sesionId}/materiales' => 'materials.write',
            'PUT/v1/sesiones/{sesionId}/materiales/{id}' => 'materials.write',
            'DELETE/v1/sesiones/{sesionId}/materiales/{id}' => 'materials.write',
            'GET/v1/anuncios/{entidadTipo}/{entidadId}' => 'announcements.read',
            'POST/v1/anuncios' => 'announcements.write',
            'PUT/v1/anuncios/{anuncioId}' => 'announcements.write',
            'DELETE/v1/anuncios/{anuncioId}' => 'announcements.write',
        ] as $route => $permission) {
            $middleware = $routes[$route]['action']['middleware'];
            self::assertContains('permission:'.$permission, $middleware);
            self::assertTrue((bool) array_filter($middleware, fn ($value) => str_starts_with($value, 'course.scope:')));
            self::assertSame(204, $this->authorize($permission)[0]);
        }
    }

    public function test_evaluation_downloads_require_relationship_to_the_files_course(): void
    {
        foreach (['evaluation-file', 'evaluation-evidence'] as $scope) {
            self::assertSame(204, $this->authorize('evaluations.read', $scope)[0]);
        }
        DB::connection('mysql_cursos')->table('evaluacion')->where('id', 30)->update(['curso_id' => 999]);
        foreach (['evaluation-file', 'evaluation-evidence'] as $scope) {
            self::assertSame(403, $this->authorize('evaluations.read', $scope)[0]);
            self::assertSame(204, $this->authorize('evaluations.read', $scope, 'actor2@example.invalid')[0]);
        }
    }
}
