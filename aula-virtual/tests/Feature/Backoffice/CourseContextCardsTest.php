<?php

namespace Tests\Feature\Backoffice;

use App\Support\AuthSessionKeys;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class CourseContextCardsTest extends TestCase
{
    public function test_operational_course_cards_show_edition_and_session_context(): void
    {
        session([
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::USER_EMAIL => 'admin@local',
        ]);

        foreach ([
            'backoffice.qualifications.index',
            'backoffice.surveys.index',
            'backoffice.certificates.index',
        ] as $viewName) {
            $html = view($viewName, [
                'courses' => $this->courses(),
                'error' => null,
                'search' => '',
                'role' => 'admin',
            ])->render();

            $this->assertStringContainsString('Edicion 7', $html, $viewName);
            $this->assertStringContainsString('Sesiones realizadas', $html, $viewName);
            $this->assertStringContainsString('3 de 15', $html, $viewName);
        }
    }

    private function courses(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([
            [
                'id' => 32,
                'code' => 'Edicion 7',
                'edition' => '7',
                'title' => 'Ciberseguridad en Azure',
                'teacher' => 'Quispe, Carlos',
                'schedule' => 'VIE 19:00-22:00 (180 min)',
                'schedule_label' => 'Vie 7:00 p.m. - 10:00 p.m.',
                'students_count' => 8,
                'total_sessions' => 15,
                'sessions_done' => 3,
                'progress_label' => '3 de 15',
                'progress_percent' => 20.0,
                'exam_count' => 1,
                'work_count' => 0,
                'survey_response_count' => 4,
                'certificates_total' => 8,
                'certificates_pending' => 6,
                'certificates_attached' => 2,
                'certificates_sent' => 1,
            ],
        ], 1, 6, 1, ['path' => 'http://localhost/backoffice']);
    }
}
