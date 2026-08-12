<?php

namespace Tests\Unit;

use App\Support\SessionPresentation;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class SessionPresentationTest extends TestCase
{
    public function test_formats_a_readable_spanish_schedule(): void
    {
        $session = (object) [
            'date' => '2026-06-19',
            'start_time' => '19:00:00',
            'end_time' => '22:00:00',
        ];

        $label = SessionPresentation::dateTimeLabel($session);

        $this->assertStringContainsString('19 de junio', $label);
        $this->assertStringContainsString('7:00 p.m. - 10:00 p.m.', $label);
    }

    public function test_complete_session_requires_video_and_material(): void
    {
        $session = (object) [
            'material_pending' => false,
            'has_evaluation' => true,
            'video_status' => 'ready',
            'video_drive_file_id' => 'drive-id',
        ];

        $this->assertTrue(SessionPresentation::isComplete($session));
    }

    public function test_future_incomplete_session_is_preparation_not_attention(): void
    {
        $session = (object) [
            'date' => '2026-08-10',
            'start_time' => '19:00:00',
            'end_time' => '22:00:00',
            'state' => 'future',
            'material_pending' => true,
            'has_evaluation' => false,
            'video_status' => null,
            'video_drive_file_id' => null,
        ];
        $now = Carbon::parse('2026-08-03 10:00:00', 'America/Lima');

        $this->assertTrue(SessionPresentation::isUpcoming($session, $now));
        $this->assertFalse(SessionPresentation::requiresAttention($session, $now));
        $this->assertCount(2, SessionPresentation::missingTasks($session));
    }

    public function test_started_incomplete_session_requires_attention(): void
    {
        $session = (object) [
            'date' => '2026-08-03',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'material_pending' => true,
            'has_evaluation' => true,
            'video_status' => 'ready',
            'video_drive_file_id' => 'drive-id',
        ];

        $this->assertTrue(SessionPresentation::requiresAttention(
            $session,
            Carbon::parse('2026-08-03 10:00:00', 'America/Lima')
        ));
    }
}
