<?php

namespace Tests\Unit;

use App\Services\Http\ApiServiciosClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pruebas del cliente HTTP de servicios academicos.
 */
class ApiServiciosClientTest extends TestCase
{
    public function test_listar_cursos_success_includes_correlation_header(): void
    {
        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'token-123',
            'services.correlation.header' => 'X-Correlation-ID',
        ]);

        $request = Request::create('/test', 'GET');
        $request->attributes->set('correlation_id', 'corr-123');
        app()->instance('request', $request);

        Http::fake([
            'https://api.test/*' => Http::response([
                ['id' => 1, 'nombre' => 'Curso A'],
            ], 200),
        ]);

        $result = app(ApiServiciosClient::class)->listarCursos('user@test.com');

        $this->assertTrue($result->ok());
        $this->assertSame(200, $result->status());

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-INTERNAL-SERVICE-TOKEN', 'token-123')
                && $request->hasHeader('X-Correlation-ID', 'corr-123');
        });
    }

    public function test_listar_cursos_failure_on_non_200(): void
    {
        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'token-123',
        ]);

        Http::fake([
            'https://api.test/*' => Http::response(['error' => 'fail'], 500),
        ]);

        $result = app(ApiServiciosClient::class)->listarCursos('user@test.com');

        $this->assertFalse($result->ok());
        $this->assertSame(500, $result->status());
        $this->assertSame('API Servicios error.', $result->error()['message'] ?? null);
    }

    public function test_student_survey_uses_authenticated_identity_headers(): void
    {
        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'token-123',
        ]);
        session([
            \App\Support\AuthSessionKeys::USER_EMAIL => 'student@example.com',
            \App\Support\AuthSessionKeys::USER_ROLE => 'alumno',
        ]);
        Http::fake([
            'https://api.test/*' => Http::response(['ok' => true, 'survey' => ['link_id' => 99]], 200),
        ]);

        $result = app(ApiServiciosClient::class)->obtenerEncuestaAlumno(10, 20, 99);

        $this->assertTrue($result->ok());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.test/v1/alumno/cursos/10/sesiones/20/encuestas/99'
            && $request->hasHeader('X-USER-ROL', 'alumno')
            && $request->hasHeader('X-USER-EMAIL', 'student@example.com'));
    }

    public function test_survey_results_send_all_analysis_filters(): void
    {
        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'token-123',
        ]);
        session([
            \App\Support\AuthSessionKeys::USER_EMAIL => 'admin@example.com',
            \App\Support\AuthSessionKeys::USER_ROLE => 'admin',
        ]);
        Http::fake([
            'https://api.test/*' => Http::response(['ok' => true, 'summary' => []], 200),
        ]);

        $result = app(ApiServiciosClient::class)->obtenerDetalleResultadosEncuestasCurso(18, [
            'kind' => 'session',
            'session' => 4,
            'teacher' => 9,
            'form' => 2,
            'page' => 3,
        ]);

        $this->assertTrue($result->ok());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/cursos/18/encuestas/detalle-resultados')
            && $request['kind'] === 'session'
            && (int) $request['session'] === 4
            && (int) $request['teacher'] === 9
            && (int) $request['form'] === 2
            && (int) $request['page'] === 3);
    }
}
