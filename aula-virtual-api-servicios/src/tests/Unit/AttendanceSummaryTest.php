<?php

namespace Tests\Unit;

use App\Repositories\AttendanceRepository;
use App\Repositories\MeetingRepository;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceService;
use App\Services\MeetingService;
use App\Services\ZoomReportClient;
use App\Support\ApiCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AttendanceSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        ApiCache::bumpAttendanceSummary();
    }

    public function test_course_summary_marks_attention_without_counting_future_sessions(): void
    {
        $repository = $this->createMock(AttendanceRepository::class);
        $repository->expects(self::once())->method('accessibleCourseSummaries')->willReturn([
            (object) [
                'course_id' => 10, 'sessions_total' => 15, 'sessions_finished' => 4,
                'sessions_reconciled' => 3, 'sessions_pending' => 1,
                'records_total' => 40, 'unresolved_count' => 0,
                'last_sync_at' => '2026-08-03 22:00:00',
            ],
        ]);

        $service = $this->service($repository);
        $result = $service->listCourseSummaries('admin', 'admin@example.com');

        self::assertSame(1, $result[0]['sessions_pending']);
        self::assertSame(4, $result[0]['sessions_finished']);
        self::assertSame('attention', $result[0]['attendance_status']);
    }

    public function test_course_without_attendance_records_is_reported_separately(): void
    {
        $repository = $this->createMock(AttendanceRepository::class);
        $repository->method('accessibleCourseSummaries')->willReturn([
            (object) [
                'course_id' => 11, 'sessions_total' => 3, 'sessions_finished' => 0,
                'sessions_reconciled' => 0, 'sessions_pending' => 0,
                'records_total' => 0, 'unresolved_count' => 0, 'last_sync_at' => null,
            ],
        ]);

        $result = $this->service($repository)->listCourseSummaries('admin', 'admin@example.com');
        self::assertSame('no_records', $result[0]['attendance_status']);
    }

    private function service(AttendanceRepository $repository): AttendanceService
    {
        return new AttendanceService(
            $repository,
            $this->createMock(MeetingRepository::class),
            $this->createMock(MeetingService::class),
            $this->createMock(AttendanceCalculator::class),
            $this->createMock(ZoomReportClient::class)
        );
    }
}
