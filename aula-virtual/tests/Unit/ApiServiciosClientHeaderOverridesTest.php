<?php

namespace Tests\Unit;

use App\Services\CursoService;
use App\Services\Http\ApiServiciosClient;
use App\Support\AuthSessionKeys;
use App\Support\PerformanceCache;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiServiciosClientHeaderOverridesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.api_servicios.base_url' => 'https://api.example.invalid',
            'services.api_servicios.token' => 'synthetic-test-token',
            'services.correlation.header' => 'X-Correlation-ID',
        ]);
        session([
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::AULA_ROLE => 'admin',
            AuthSessionKeys::USER_EMAIL => 'probe@example.invalid',
            AuthSessionKeys::USER_NAME => 'Probe User',
        ]);
        $request = Request::create('/backoffice/courses');
        $request->setLaravelSession(app('session.store'));
        $request->attributes->set('correlation_id', 'synthetic-test-correlation');
        app()->instance('request', $request);
        Http::preventStrayRequests();
        Http::fake(function (OutgoingRequest $request) {
            $unscoped = $request->header('X-USER-EMAIL') === ['']
                && $request->header('X-USER-ROL') === ['admin'];
            return Http::response(['ok' => true, 'courses' => $unscoped
                ? [['id' => 127, 'nombre' => 'Recovered course', 'estado' => 'en curso']]
                : []]);
        });
    }

    private function assertSingleUserHeaders(OutgoingRequest $request): void
    {
        foreach ($request->headers() as $name => $values) {
            if (str_starts_with(strtoupper($name), 'X-USER-')) {
                self::assertCount(1, $values, 'Multiple values for '.$name);
            }
        }
        // Assert presence without exposing credential/correlation values on failure.
        self::assertTrue($request->hasHeader('X-INTERNAL-SERVICE-TOKEN'));
        self::assertTrue($request->hasHeader('X-Correlation-ID'));
        self::assertCount(1, $request->header('Accept'));
        self::assertSame(['application/json'], $request->header('Accept'));
    }

    public function test_actor_identity_is_preserved_when_admin_listing_scope_is_explicitly_empty(): void
    {
        app(ApiServiciosClient::class)->resumenBackoffice('', 'admin');
        Http::assertSent(fn (OutgoingRequest $request) => $request->header('X-AULA-ACTOR-EMAIL') === ['probe@example.invalid']
            && $request->header('X-USER-EMAIL') === ['']);
    }

    public function test_generic_overrides_cannot_replace_or_add_an_actor_header(): void
    {
        $client = new ApiServiciosClient();
        $method = new \ReflectionMethod($client, 'client');
        $method->invoke($client, [
            'x-aula-actor-email' => 'forged@example.invalid',
            'X-AULA-ACTOR-EMAIL' => '',
            'X-USER-EMAIL' => '', 'X-USER-ROL' => 'docente', 'accept' => '*/*',
        ])->get('https://api.example.invalid/v1/fixture');
        Http::assertSent(function (OutgoingRequest $request) {
            self::assertSame(['probe@example.invalid'], $request->header('X-AULA-ACTOR-EMAIL'));
            self::assertSame([''], $request->header('X-USER-EMAIL'));
            self::assertSame(['docente'], $request->header('X-USER-ROL'));
            self::assertSame(['*/*'], $request->header('accept'));
            self::assertTrue($request->hasHeader('X-INTERNAL-SERVICE-TOKEN'));
            self::assertTrue($request->hasHeader('X-Correlation-ID'));
            return true;
        });
        app('session.store')->forget(AuthSessionKeys::USER_EMAIL);
        $method->invoke($client, ['x-aula-actor-email' => 'forged@example.invalid'])
            ->get('https://api.example.invalid/v1/no-actor');
        Http::assertSent(fn (OutgoingRequest $request) => str_ends_with($request->url(), '/no-actor')
            && $request->header('X-AULA-ACTOR-EMAIL') === ['']);
    }

    public function test_admin_empty_email_override_replaces_web_session_email(): void
    {
        self::assertTrue((new ApiServiciosClient())->resumenBackoffice('', 'admin')->ok());
        Http::assertSent(function (OutgoingRequest $request) {
            $this->assertSingleUserHeaders($request);
            self::assertSame(['admin'], $request->header('X-USER-ROL'));
            self::assertSame([''], $request->header('X-USER-EMAIL'));
            self::assertNotContains('probe@example.invalid', $request->header('X-USER-EMAIL'));
            return true;
        });
    }

    public function test_teacher_overrides_replace_session_identity(): void
    {
        (new ApiServiciosClient())->resumenBackoffice('teacher@example.invalid', 'docente');
        Http::assertSent(function (OutgoingRequest $request) {
            $this->assertSingleUserHeaders($request);
            self::assertSame(['docente'], $request->header('X-USER-ROL'));
            self::assertSame(['teacher@example.invalid'], $request->header('X-USER-EMAIL'));
            return true;
        });
    }

    public function test_endpoint_without_overrides_preserves_automatic_session_context(): void
    {
        (new ApiServiciosClient())->obtenerCurso(10);
        Http::assertSent(function (OutgoingRequest $request) {
            $this->assertSingleUserHeaders($request);
            self::assertSame(['admin'], $request->header('X-USER-ROL'));
            self::assertSame(['probe@example.invalid'], $request->header('X-USER-EMAIL'));
            self::assertSame(['Probe User'], $request->header('X-USER-NAME'));
            return true;
        });
    }

    public function test_identical_session_overrides_and_both_name_headers_are_single_values(): void
    {
        (new ApiServiciosClient())->crearMensajeChat('test-room', ['mensaje' => 'Synthetic']);
        Http::assertSent(function (OutgoingRequest $request) {
            $this->assertSingleUserHeaders($request);
            self::assertSame(['admin'], $request->header('X-USER-ROL'));
            self::assertSame(['probe@example.invalid'], $request->header('X-USER-EMAIL'));
            self::assertSame(['Probe User'], $request->header('X-USER-NAME'));
            self::assertSame(['Probe User'], $request->header('X-USER-NOMBRE'));
            return true;
        });
    }

    public function test_multipart_overrides_preserve_single_identity_headers(): void
    {
        (new ApiServiciosClient())->actualizarAdjuntosPerfilAlumno('student@example.invalid', []);
        Http::assertSent(function (OutgoingRequest $request) {
            $this->assertSingleUserHeaders($request);
            self::assertSame(['alumno'], $request->header('X-USER-ROL'));
            self::assertSame(['student@example.invalid'], $request->header('X-USER-EMAIL'));
            self::assertTrue(str_starts_with($request->header('Content-Type')[0], 'multipart/form-data'));
            return true;
        });
    }

    public function test_download_preserves_accept_override_and_single_role(): void
    {
        (new ApiServiciosClient())->descargarMaterialSesion(10, 'docente');
        Http::assertSent(function (OutgoingRequest $request) {
            self::assertSame(['docente'], $request->header('X-USER-ROL'));
            self::assertSame(['probe@example.invalid'], $request->header('X-USER-EMAIL'));
            self::assertCount(1, $request->header('Accept'));
            self::assertSame(['*/*'], $request->header('Accept'));
            return true;
        });
    }

    public function test_course_service_admin_web_request_has_no_session_email_scope(): void
    {
        PerformanceCache::forget(PerformanceCache::courseListKey('main', 'admin', ''));
        $result = (new CursoService(new ApiServiciosClient()))->listarCursos('');
        self::assertTrue($result->ok());
        self::assertCount(1, $result->data()['courses']);
        self::assertSame(127, $result->data()['courses']->first()['id']);
        Http::assertSent(function (OutgoingRequest $request) {
            $this->assertSingleUserHeaders($request);
            self::assertSame([''], $request->header('X-USER-EMAIL'));
            self::assertSame(['admin'], $request->header('X-USER-ROL'));
            return $request->url() === 'https://api.example.invalid/v1/backoffice/resumen';
        });
        Http::assertSentCount(1);
    }
}
