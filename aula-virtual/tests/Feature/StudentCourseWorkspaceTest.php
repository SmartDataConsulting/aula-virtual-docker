<?php

namespace Tests\Feature;

use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudentCourseWorkspaceTest extends TestCase
{
    public function test_student_workspace_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('mis-cursos.sessions.workspace'));
        $this->assertTrue(Route::has('mis-cursos.sessions.panels.show'));
        $this->assertTrue(Route::has('mis-cursos.community.show'));
    }

    public function test_student_course_uses_the_shared_workspace_without_management_actions(): void
    {
        session([
            AuthSessionKeys::USER_EMAIL => 'student@local.test',
            AuthSessionKeys::USER_ROLE => 'alumno',
        ]);

        $session = $this->makeSession(2349, 1);
        $html = view('mis-cursos.show', [
            'course' => (object) [
                'id' => 34,
                'title' => 'IA Generativa Aplicada',
                'edition' => 14,
                'state' => 'en curso',
                'teacher_name' => 'Gómez Soto, Lucía',
            ],
            'sessions' => collect([$session]),
            'session' => $session,
            'anuncioSesionNoLeido' => ['existen' => false, 'pendiente' => null],
        ])->render();

        $this->assertStringContainsString('data-workspace-context="student"', $html);
        $this->assertStringContainsString('Docente: Gómez Soto, Lucía', $html);
        $this->assertStringContainsString('data-tab="materials"', $html);
        $this->assertStringContainsString('data-tab="surveys"', $html);
        $this->assertStringContainsString('data-tab="attendance"', $html);
        $this->assertStringContainsString('data-community-toggle', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('inert', $html);
        $this->assertStringNotContainsString('Subir video', $html);
        $this->assertStringNotContainsString('Configurar evaluación', $html);
        $this->assertStringNotContainsString('Conciliar con Zoom', $html);
    }

    public function test_student_sidebar_uses_learning_states_and_keeps_future_sessions_selectable(): void
    {
        $current = $this->makeSession(2349, 1);
        $future = $this->makeSession(2350, 2);
        $future->date = now('America/Lima')->addMonth()->toDateString();
        $future->state = 'future';
        $future->evaluation_pending = true;

        $html = view('mis-cursos.partials.sidebar', [
            'course' => (object) ['id' => 34],
            'sessions' => collect([$current, $future]),
            'session' => $current,
        ])->render();

        $this->assertStringContainsString('Por completar', $html);
        $this->assertStringContainsString('Completadas', $html);
        $this->assertStringContainsString('Próximas', $html);
        $this->assertStringContainsString('data-session-id="2350"', $html);
        $this->assertStringContainsString('data-session-state="upcoming"', $html);
        $this->assertStringContainsString('Tu avance', $html);
        $this->assertMatchesRegularExpression('/data-session-filter="attention">Por completar\s*<span>0<\/span>/', $html);
        $this->assertStringNotContainsString('aria-disabled="true"', $html);
        $this->assertStringNotContainsString('Falta material', $html);
        $this->assertStringNotContainsString('Sin evaluación', $html);
    }

    public function test_live_zoom_is_the_primary_student_next_action(): void
    {
        $session = $this->makeSession(2349, 1);
        $session->date = now('America/Lima')->toDateString();
        $session->start_time = '00:00:00';
        $session->end_time = '23:59:59';
        $session->meeting = (object) [
            'scheduled' => true,
            'can_join' => true,
            'join_url' => 'https://zoom.example.test/join',
            'availability' => 'open',
        ];
        $session->evaluaciones = [[
            'id' => 5,
            'nombre' => 'Examen parcial',
            'rendicion_estado' => 'en_progreso',
            'rendicion_id' => 22,
        ]];

        $html = view('mis-cursos.partials.session', [
            'course' => (object) ['id' => 34],
            'sessions' => collect([$session]),
            'session' => $session,
            'anuncioSesionNoLeido' => ['existen' => false],
        ])->render();

        $this->assertStringContainsString('TU SIGUIENTE PASO', $html);
        $this->assertStringContainsString('Ingresar a la clase', $html);
        $this->assertStringContainsString('form="sessionZoomJoinForm-2349"', $html);
        $this->assertStringNotContainsString('Subir video', $html);
    }

    public function test_started_evaluation_is_recommended_before_survey_and_materials(): void
    {
        $session = $this->makeSession(2349, 1);
        $session->evaluaciones = [[
            'id' => 5,
            'nombre' => 'Examen parcial',
            'rendicion_estado' => 'en_progreso',
            'rendicion_id' => 22,
        ]];
        $session->surveys = collect([(object) ['status' => 'pending']]);

        $html = view('mis-cursos.partials.session', [
            'course' => (object) ['id' => 34],
            'sessions' => collect([$session]),
            'session' => $session,
            'anuncioSesionNoLeido' => ['existen' => false],
        ])->render();

        $this->assertStringContainsString('Continuar evaluación', $html);
        $this->assertStringContainsString('data-open-session-panel="evaluations"', $html);
    }

    public function test_student_panels_expose_only_consumption_actions(): void
    {
        $session = $this->makeSession(2349, 1);
        $session->evaluaciones = [[
            'id' => 5,
            'nombre' => 'Examen parcial',
            'tipo_param_id' => 1,
            'rendicion_estado' => 'en_progreso',
            'rendicion_id' => 22,
        ]];

        $html = view('mis-cursos.partials.panels.evaluations', [
            'course' => (object) ['id' => 34],
            'session' => $session,
        ])->render();

        $this->assertStringContainsString('Continuar', $html);
        $this->assertStringNotContainsString('Editar', $html);
        $this->assertStringNotContainsString('Eliminar', $html);
        $this->assertStringNotContainsString('Publicar', $html);
    }

    public function test_completed_automatic_evaluation_shows_score_at_first_sight(): void
    {
        $session = $this->makeSession(2349, 1);
        $session->evaluaciones = [[
            'id' => 5,
            'nombre' => 'Examen parcial',
            'tipo_param_id' => 1,
            'rendicion_estado' => 'finalizado',
            'rendicion_id' => 22,
            'score' => 17,
            'max_score' => 20,
            'pass_score' => 11,
            'approved' => true,
        ]];

        $html = view('mis-cursos.partials.panels.evaluations', [
            'course' => (object) ['id' => 34],
            'session' => $session,
        ])->render();

        $this->assertStringContainsString('Puntaje', $html);
        $this->assertStringContainsString('17 <small>/ 20</small>', $html);
        $this->assertStringContainsString('Aprobada', $html);
        $this->assertStringContainsString('Ver resultado', $html);
    }

    private function makeSession(int $id, int $number): object
    {
        return (object) [
            'id' => $id,
            'number' => $number,
            'date' => now('America/Lima')->subDay()->toDateString(),
            'start_time' => '19:00:00',
            'end_time' => '22:00:00',
            'state' => 'past',
            'video_status' => 'ready',
            'video_drive_file_id' => 'drive-file',
            'materials' => collect([(object) ['id' => 1]]),
            'evaluaciones' => [],
            'surveys' => collect(),
            'announcements_count' => 0,
            'meeting' => (object) ['scheduled' => false, 'can_join' => false],
        ];
    }
}
