<?php

namespace Tests\Feature\Backoffice;

use App\Services\AttendanceService;
use App\Services\CursoService;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class AttendanceViewTest extends TestCase
{
    public function test_attendance_route_uses_one_aggregated_summary_and_renders_cards(): void
    {
        $courses = Mockery::mock(CursoService::class);
        $courses->shouldReceive('listarCursos')->once()->with('')->andReturn(ServiceResult::success([
            'courses' => collect([[
                'id' => 32, 'title' => 'Curso agregado', 'edition' => '9',
                'teacher' => 'Docente', 'schedule' => '', 'schedule_label' => '',
            ]]),
        ]));
        $attendance = Mockery::mock(AttendanceService::class);
        $attendance->shouldReceive('courseSummaries')->once()->andReturn(ServiceResult::success([[
            'course_id' => 32, 'sessions_total' => 2, 'sessions_finished' => 1,
            'sessions_reconciled' => 1, 'sessions_pending' => 0, 'unresolved_count' => 0,
            'last_sync_at' => null, 'attendance_status' => 'up_to_date',
        ]]));
        $this->app->instance(CursoService::class, $courses);
        $this->app->instance(AttendanceService::class, $attendance);

        $response = $this->withSession([
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::USER_EMAIL => 'admin@local',
        ])->get('/backoffice/attendance');

        $response->assertOk();
        $response->assertSee('Curso agregado');
        $response->assertSee('Al día');
    }

    public function test_summary_failure_keeps_courses_visible(): void
    {
        $courses = Mockery::mock(CursoService::class);
        $courses->shouldReceive('listarCursos')->andReturn(ServiceResult::success([
            'courses' => collect([[
                'id' => 34, 'title' => 'Curso disponible', 'edition' => '1',
                'teacher' => 'Docente', 'schedule' => '', 'schedule_label' => '',
            ]]),
        ]));
        $attendance = Mockery::mock(AttendanceService::class);
        $attendance->shouldReceive('courseSummaries')->andReturn(ServiceResult::failure(['message' => 'API error'], 502));
        $this->app->instance(CursoService::class, $courses);
        $this->app->instance(AttendanceService::class, $attendance);

        $response = $this->withSession([
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::USER_EMAIL => 'admin@local',
        ])->get('/backoffice/attendance');

        $response->assertOk();
        $response->assertSee('Curso disponible');
        $response->assertSee('Resumen no disponible');
    }

    public function test_attendance_index_renders_course_cards_and_summary(): void
    {
        $courses = new LengthAwarePaginator([[
            'id' => 32,
            'title' => 'Curso de prueba',
            'edition' => '7',
            'teacher' => 'Docente de prueba',
            'schedule' => 'LUN 19:00-22:00',
            'schedule_label' => 'Lun 7:00 p.m. - 10:00 p.m.',
            'attendance_status' => 'attention',
            'summary_available' => true,
            'sessions_reconciled' => 2,
            'sessions_finished' => 4,
            'sessions_pending' => 2,
            'unresolved_count' => 1,
            'last_sync_at' => '2026-08-03 22:10:00',
        ]], 1, 6);

        $html = view('backoffice.attendance.index', [
            'courses' => $courses,
            'metrics' => ['courses' => 1, 'reconciled' => 2, 'pending' => 2, 'unresolved' => 1],
            'search' => '',
            'status' => 'all',
            'isAdmin' => true,
            'error' => null,
            'summaryError' => false,
        ])->render();

        self::assertStringContainsString('Curso de prueba', $html);
        self::assertStringContainsString('Requiere atención', $html);
        self::assertStringContainsString('Sesiones conciliadas', $html);
        self::assertStringContainsString('Ver asistencia', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function test_attendance_course_detail_renders_session_cards(): void
    {
        $session = (object) [
            'id' => 100, 'number' => 1, 'date' => '2026-08-03',
            'start_time' => '19:00:00', 'end_time' => '22:00:00',
            'status' => 'pending', 'students_count' => 12, 'present_count' => 8,
            'absent_count' => 1, 'pending_count' => 3, 'teacher_status' => 'tardanza',
            'unresolved_count' => 1,
        ];

        $html = view('backoffice.attendance.show', [
            'course' => (object) [
                'id' => 32, 'title' => 'Curso de prueba', 'edition' => '7',
                'teacher' => 'Docente de prueba', 'schedule' => '',
                'schedule_label' => 'Lun 7:00 p.m. - 10:00 p.m.', 'tab' => 'activos',
            ],
            'summary' => ['sessions_total' => 1, 'sessions_reconciled' => 0, 'sessions_pending' => 1, 'unresolved_count' => 1],
            'sessions' => collect([$session]),
            'visibleSessions' => collect([$session]),
            'sessionStatus' => 'all',
            'selectedSession' => null,
            'attendanceData' => null,
            'sessionError' => null,
            'error' => null,
            'isAdmin' => true,
        ])->render();

        self::assertStringContainsString('Sesión 1', $html);
        self::assertStringContainsString('Docente:', $html);
        self::assertStringContainsString('Revisar sesión', $html);
        self::assertStringContainsString('8', $html);
    }
}
