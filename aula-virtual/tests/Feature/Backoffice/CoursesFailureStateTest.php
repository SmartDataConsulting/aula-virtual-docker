<?php

namespace Tests\Feature\Backoffice;

use App\Support\AuthSessionKeys;
use Tests\TestCase;

class CoursesFailureStateTest extends TestCase
{
    public function test_course_list_renders_an_empty_state_when_api_groups_are_collections(): void
    {
        session([
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::USER_EMAIL => 'admin@local',
        ]);

        $html = view('backoffice.courses.index', [
            'groups' => [
                'activos' => collect(),
                'programados' => collect(),
                'finalizados' => collect(),
            ],
            'counts' => ['activos' => 0, 'programados' => 0, 'finalizados' => 0],
            'error' => 'No se pudieron cargar los cursos.',
            'search' => '',
            'activeTab' => 'activos',
        ])->render();

        self::assertStringContainsString('No se pudieron cargar los cursos.', $html);
        self::assertStringContainsString('No hay cursos activos asignados.', $html);
    }
}
