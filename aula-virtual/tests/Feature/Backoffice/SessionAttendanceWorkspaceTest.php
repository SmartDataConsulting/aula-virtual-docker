<?php

namespace Tests\Feature\Backoffice;

use Tests\TestCase;

class SessionAttendanceWorkspaceTest extends TestCase
{
    public function test_attendance_is_a_lazy_workspace_tab(): void
    {
        $session = (object) [
            'id' => 2349,
            'number' => 10,
            'title' => 'Sesion 10',
            'materials_count' => 0,
            'announcements_count' => 0,
            'evaluaciones' => [],
            'materials' => collect(),
            'announcements' => collect(),
            'meeting' => ['scheduled' => false],
        ];

        $html = view('backoffice.courses.partials.session', [
            'course' => (object) ['id' => 10, 'title' => 'Curso'],
            'sessions' => collect([$session]),
            'session' => $session,
        ])->render();

        self::assertStringContainsString('data-tab="attendance"', $html);
        self::assertStringContainsString('data-panel="attendance"', $html);
        self::assertStringNotContainsString('/backoffice/attendance?course=10', $html);
    }

    public function test_session_attendance_panel_renders_summary_and_role_actions(): void
    {
        $record = (object) [
            'id' => 7, 'session_id' => 2349, 'participant_type' => 'alumno',
            'name' => 'Alumno Prueba', 'email' => 'alumno@example.com',
            'status' => 'asistio', 'manual_status' => null,
            'first_join_at' => '2026-08-03 19:00:20', 'minutes' => 15,
            'percentage' => 0,
        ];
        $session = (object) ['id' => 2349, 'number' => 10];

        $html = view('backoffice.courses.partials.session-attendance', [
            'course' => (object) ['id' => 10],
            'session' => $session,
            'isAdmin' => false,
            'attendance' => [
                'enabled' => true, 'meeting_scheduled' => true, 'status' => 'pending',
                'can_sync' => false, 'sync' => null, 'session' => ['number' => 10],
                'items' => collect([$record]), 'unresolved' => collect(),
                'summary' => ['students' => 1, 'present' => 1, 'absent' => 0, 'pending' => 0],
            ],
        ])->render();

        self::assertStringContainsString('Asistencia de la sesión 10', $html);
        self::assertStringContainsString('Alumno Prueba', $html);
        self::assertStringContainsString('data-attendance-students', $html);
        self::assertStringContainsString('Ver alumnos (1)', $html);
        self::assertStringContainsString('data-attendance-search', $html);
        self::assertStringNotContainsString('Conciliar con Zoom</button>', $html);
    }
}
