<?php

namespace Tests\Feature;

use App\Support\AuthSessionKeys;
use App\Services\Support\ServiceResult;
use App\Services\SurveyService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class SurveyModuleTest extends TestCase
{
    public function test_student_survey_index_returns_to_the_course_catalog(): void
    {
        $response = $this->withSession([
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_EMAIL => 'student@example.com',
            AuthSessionKeys::USER_ROLE => 'alumno',
        ])->get(route('mis-cursos.surveys.index'));

        $response->assertRedirect(route('mis-cursos.index'));
    }

    public function test_non_student_cannot_submit_a_survey_response(): void
    {
        $response = $this->withSession([
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_EMAIL => 'teacher@example.com',
            AuthSessionKeys::USER_ROLE => 'operador',
        ])->post(route('mis-cursos.survey.store', [10, 44, 5]), [
            'answers' => ['satisfaccion' => 5],
        ]);

        $response->assertForbidden();
    }

    public function test_admin_results_show_auditable_responses_and_accessible_tabs(): void
    {
        session([
            AuthSessionKeys::USER_EMAIL => 'admin@example.com',
            AuthSessionKeys::USER_ROLE => 'admin',
        ]);

        $results = new LengthAwarePaginator([[
            'respuesta_id' => 91,
            'nro_sesion' => 3,
            'respondent' => 'student@example.com',
            'kind' => 'session',
            'formulario' => 'Encuesta de sesion',
            'formulario_version' => 1,
            'docente' => 'Docente de prueba',
            'submitted_at' => '2026-08-05 20:00:00',
            'answers' => [['label' => 'Satisfaccion', 'value' => 5]],
        ]], 1, 25);

        $html = view('backoffice.surveys.results', [
            'cursoEdicionId' => 10,
            'course' => [
                'title' => 'Curso de prueba',
                'code' => 'Edicion 1',
                'teacher' => 'Docente de prueba',
                'schedule' => 'Lun 7:00 p.m.',
            ],
            'courseTitle' => 'Curso de prueba',
            'resultados' => $results,
            'error' => null,
            'filters' => ['kind' => 'all', 'session' => 0, 'teacher' => 0, 'form' => 0, 'view' => 'responses'],
            'catalogs' => ['sessions' => [3], 'teachers' => [], 'forms' => []],
            'surveySummary' => [
                'submissions' => 1,
                'participants' => 1,
                'eligible_students' => 3,
                'participation_percent' => 33.3,
                'sessions' => 1,
                'average' => 5,
                'comments' => 0,
                'sample_small' => true,
                'privacy_protected' => false,
            ],
            'questionResults' => collect(),
            'comparisons' => ['sessions' => [], 'teachers' => [], 'course_average' => 5],
            'comments' => collect(),
            'isAdmin' => true,
        ])->render();

        self::assertStringContainsString('student@example.com', $html);
        self::assertStringContainsString('Exportar detalle', $html);
        self::assertStringContainsString('role="tab"', $html);
        self::assertStringContainsString('aria-selected="true"', $html);
        self::assertStringContainsString('Respuestas individuales', $html);
    }

    public function test_summary_export_preserves_filters_and_writes_utf8_csv(): void
    {
        $surveyService = $this->mock(SurveyService::class);
        $surveyService->shouldReceive('obtenerDetalleResultadosCurso')
            ->once()
            ->with(18, Mockery::on(fn (array $filters) => $filters['kind'] === 'final'
                && $filters['session'] === 10
                && $filters['per_page'] === 5000))
            ->andReturn(ServiceResult::success([
                'summary' => [
                    'participants' => 5,
                    'eligible_students' => 10,
                    'participation_percent' => 50,
                    'submissions' => 5,
                    'average' => 4.5,
                    'comments' => 2,
                ],
                'questions' => [[
                    'form_name' => 'Evaluación final',
                    'version' => 1,
                    'kind' => 'final',
                    'label' => '¿Recomendarías el curso?',
                    'responses' => 5,
                    'average' => 4.5,
                    'distribution' => [5 => 4, 4 => 1],
                ]],
                'responses' => ['data' => []],
            ]));

        $response = $this->withSession([
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_EMAIL => 'admin@example.com',
            AuthSessionKeys::USER_ROLE => 'admin',
        ])->get(route('backoffice.surveys.results.export', 18).'?scope=summary&kind=final&session=10');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        self::assertStringContainsString('Evaluación final', $content);
        self::assertStringContainsString('¿Recomendarías el curso?', $content);
    }
}
