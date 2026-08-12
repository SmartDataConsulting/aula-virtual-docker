<?php

namespace Tests\Unit;

use App\Services\EvaluationService;
use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvaluationQualificationIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'token-123',
        ]);
        session([
            AuthSessionKeys::USER_EMAIL => 'admin@example.com',
            AuthSessionKeys::USER_ROLE => 'admin',
        ]);
    }

    public function test_dashboard_keeps_relation_id_separate_from_real_evaluation_id(): void
    {
        Http::fake([
            'https://api.test/v1/calificaciones/cursos/34' => Http::response([
                'course' => ['id' => 34, 'name' => 'Curso'],
                'evaluations' => [[
                    'curso_sesion_evaluacion_id' => 3,
                    'evaluacion_id' => 6,
                    'nombre' => 'Trabajo practico',
                    'tipo_param_id' => 3,
                    'is_work' => true,
                ]],
            ]),
        ]);

        $result = app(EvaluationService::class)->getCourseQualificationsDashboard(34);
        $evaluation = $result->data()['evaluations']->first();

        $this->assertTrue($result->ok());
        $this->assertSame(3, $evaluation['id']);
        $this->assertSame(3, $evaluation['course_session_evaluation_id']);
        $this->assertSame(6, $evaluation['evaluation_id']);
    }

    public function test_participants_are_requested_with_the_real_evaluation_id(): void
    {
        Http::fake([
            'https://api.test/v1/evaluaciones/6/participantes' => Http::response([]),
        ]);

        $result = app(EvaluationService::class)->getEvaluationParticipants(6);

        $this->assertTrue($result->ok());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.test/v1/evaluaciones/6/participantes');
    }
}
