<?php

namespace Tests\Feature\Backoffice;

use App\Support\AuthSessionKeys;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class EvaluationsIndexDesignTest extends TestCase
{
    public function test_evaluations_uses_the_shared_three_column_course_card_pattern(): void
    {
        session([
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::USER_EMAIL => 'admin@local',
        ]);

        $html = view('backoffice.evaluations.index', [
            'cursos' => $this->coursesPaginator([
                [
                    'curso_id' => 32,
                    'edicion' => '7',
                    'nombre' => 'Ciberseguridad en Azure',
                    'docente' => 'Quispe, Carlos',
                    'horario' => 'VIE 19:00-22:00 (180 min)',
                    'schedule_label' => 'Vie 7:00 p.m. - 10:00 p.m.',
                    'alumnos_inscritos' => 8,
                    'nro_evaluaciones' => 2,
                    'evaluaciones_publicadas' => 1,
                    'evaluaciones_borrador' => 1,
                ],
            ]),
            'error' => null,
            'search' => '',
            'totalCursos' => 1,
        ])->render();

        $this->assertStringContainsString('grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3', $html);
        $this->assertStringContainsString('backoffice-course-card evaluacion-course-card', $html);
        $this->assertStringContainsString('Edicion 7', $html);
        $this->assertStringContainsString('Gestionar evaluaciones', $html);
        $this->assertStringNotContainsString('evaluations-course-card', $html);
    }

    public function test_evaluations_renders_an_empty_paginated_result(): void
    {
        session([
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::USER_EMAIL => 'admin@local',
        ]);

        $html = view('backoffice.evaluations.index', [
            'cursos' => $this->coursesPaginator([]),
            'error' => null,
            'search' => '',
            'totalCursos' => 0,
        ])->render();

        $this->assertStringContainsString('Mostrando 0 cursos disponibles', $html);
        $this->assertStringContainsString('No hay cursos disponibles para gestionar evaluaciones.', $html);
        $this->assertStringNotContainsString('backoffice-course-card evaluacion-course-card', $html);
    }

    /**
     * @param  array<int, array<string, mixed>>  $courses
     */
    private function coursesPaginator(array $courses): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $courses,
            count($courses),
            6,
            1,
            ['path' => route('backoffice.evaluations.index'), 'pageName' => 'page']
        );
    }
}
