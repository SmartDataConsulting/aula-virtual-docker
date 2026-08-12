<?php

namespace Tests\Feature\Backoffice;

use App\Support\AuthSessionKeys;
use App\Services\SesionService;
use App\Services\Support\ServiceResult;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class CourseWorkspaceDesignTest extends TestCase
{
    public function test_session_workspace_exposes_actions_tabs_and_sequence_navigation(): void
    {
        $sessions = collect([
            $this->makeSession(101, 1, true),
            $this->makeSession(102, 2, false),
        ]);

        $html = view('backoffice.courses.partials.session', [
            'course' => (object) ['id' => 32, 'title' => 'Ciberseguridad en Azure'],
            'sessions' => $sessions,
            'session' => $sessions->first(),
            'error' => null,
        ])->render();

        $this->assertStringContainsString('Tareas de esta sesión', $html);
        $this->assertStringContainsString('data-open-session-panel="materials"', $html);
        $this->assertStringContainsString('data-panel="evaluations"', $html);
        $this->assertStringContainsString('data-session-nav', $html);
        $this->assertStringContainsString('Sesión 1 de 2', $html);
    }

    public function test_sidebar_is_searchable_and_filterable(): void
    {
        $sessions = collect([$this->makeSession(101, 1, true)]);

        $html = view('backoffice.courses.partials.sidebar', [
            'course' => (object) [
                'id' => 32,
                'progress' => '0 de 1',
                'progress_percent' => 0,
            ],
            'sessions' => $sessions,
            'session' => $sessions->first(),
        ])->render();

        $this->assertStringContainsString('id="courseSessionSearch"', $html);
        $this->assertStringContainsString('data-session-filter="attention"', $html);
        $this->assertStringContainsString('data-session-link', $html);
        $this->assertStringContainsString('aria-current="step"', $html);
    }

    public function test_progressive_workspace_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('backoffice.courses.sessions.workspace'));
        $this->assertTrue(Route::has('backoffice.courses.sessions.panels.show'));
        $this->assertTrue(Route::has('backoffice.courses.community.show'));
    }

    public function test_future_incomplete_sessions_are_not_counted_as_attention(): void
    {
        $future = $this->makeSession(201, 3, true);
        $future->date = now('America/Lima')->addMonth()->toDateString();
        $future->state = 'future';

        $html = view('backoffice.courses.partials.sidebar', [
            'course' => (object) ['id' => 32],
            'sessions' => collect([$future]),
            'session' => $future,
        ])->render();

        $this->assertStringContainsString('data-session-filter="attention">Requieren atención <span>0</span>', $html);
        $this->assertStringContainsString('data-session-filter="upcoming">Próximas <span>1</span>', $html);
        $this->assertStringContainsString('Preparación pendiente', $html);
        $this->assertStringNotContainsString('session-flag-warning', $html);
    }

    public function test_course_page_exposes_the_lazy_community_drawer(): void
    {
        session([
            AuthSessionKeys::USER_EMAIL => 'admin@local.test',
            AuthSessionKeys::USER_ROLE => 'admin',
        ]);
        $sessions = collect([$this->makeSession(101, 1, true)]);

        $html = view('backoffice.courses.show', [
            'course' => (object) [
                'id' => 32,
                'title' => 'Ciberseguridad en Azure',
                'teacher_name' => 'Quispe, Carlos',
                'progress' => '0 de 1',
                'progress_percent' => 0,
            ],
            'sessions' => $sessions,
            'session' => $sessions->first(),
            'error' => null,
            'chat' => [],
        ])->render();

        $this->assertStringContainsString('data-community-toggle', $html);
        $this->assertStringContainsString('data-community-url=', $html);
        $this->assertStringContainsString('Responsable: Quispe, Carlos', $html);
    }

    public function test_teacher_workspace_does_not_repeat_the_teacher_name(): void
    {
        session([
            AuthSessionKeys::USER_EMAIL => 'teacher@local.test',
            AuthSessionKeys::USER_ROLE => 'docente',
        ]);
        $sessions = collect([$this->makeSession(101, 1, true)]);

        $html = view('backoffice.courses.show', [
            'course' => (object) [
                'id' => 32,
                'title' => 'Ciberseguridad en Azure',
                'teacher_name' => 'Quispe, Carlos',
                'progress' => '0 de 1',
                'progress_percent' => 0,
            ],
            'sessions' => $sessions,
            'session' => $sessions->first(),
            'error' => null,
            'chat' => [],
        ])->render();

        $this->assertStringNotContainsString('Responsable: Quispe, Carlos', $html);
        $this->assertStringNotContainsString('Prof. Quispe, Carlos', $html);
    }

    public function test_workspace_endpoint_returns_rendered_session_json(): void
    {
        $sessions = collect([$this->makeSession(101, 1, true)]);
        $this->mock(SesionService::class, function (MockInterface $mock) use ($sessions) {
            $mock->shouldReceive('listarSesionesCurso')
                ->once()
                ->with(32, 'admin')
                ->andReturn(ServiceResult::success(['sessions' => $sessions]));
        });

        $response = $this->withSession([
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_EMAIL => 'admin@local.test',
            AuthSessionKeys::USER_ROLE => 'admin',
        ])->getJson('/backoffice/courses/32/sessions/101/workspace');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('meta.session_id', 101)
            ->assertJsonPath('meta.position', 1);

        $this->assertStringContainsString('data-session-workspace', $response->json('html'));
    }

    private function makeSession(int $id, int $number, bool $pending): object
    {
        return (object) [
            'id' => $id,
            'number' => $number,
            'title' => 'Sesión '.$number,
            'date' => '2026-06-19',
            'start_time' => '19:00:00',
            'end_time' => '22:00:00',
            'state' => $number === 1 ? 'current' : 'future',
            'material_pending' => $pending,
            'has_evaluation' => !$pending,
            'video_status' => null,
            'video_drive_file_id' => null,
            'materials_count' => 0,
            'announcements_count' => 0,
            'evaluaciones' => [],
        ];
    }
}
