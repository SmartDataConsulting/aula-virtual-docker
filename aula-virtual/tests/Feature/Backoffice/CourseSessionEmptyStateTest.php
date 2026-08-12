<?php

namespace Tests\Feature\Backoffice;

use Tests\TestCase;

class CourseSessionEmptyStateTest extends TestCase
{
    public function test_course_detail_renders_an_empty_state_when_there_are_no_sessions(): void
    {
        $html = view('backoffice.courses.partials.session', [
            'course' => (object) ['id' => 32, 'title' => 'Curso sin sesiones'],
            'sessions' => collect(),
            'session' => null,
            'error' => null,
        ])->render();

        $this->assertStringContainsString('No hay una sesión disponible', $html);
        $this->assertStringContainsString('No se encontraron sesiones para este curso.', $html);
        $this->assertStringNotContainsString('Material de la sesion', $html);
    }
}
