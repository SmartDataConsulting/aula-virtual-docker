<?php

namespace Tests\Unit;

use App\Services\AttendanceCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AttendanceCalculatorTest extends TestCase
{
    private AttendanceCalculator $calculator;
    private CarbonImmutable $start;
    private CarbonImmutable $end;

    protected function setUp(): void
    {
        $this->calculator = new AttendanceCalculator();
        $this->start = CarbonImmutable::parse('2026-08-05 19:00:00', 'America/Lima');
        $this->end = $this->start->addHours(3);
    }

    public function test_student_attends_with_any_confirmed_zoom_interval(): void
    {
        $result = $this->calculator->calculate('alumno', $this->start, $this->end, [[
            'join_at' => '2026-08-05 21:59:00',
            'leave_at' => '2026-08-05 22:00:00',
        ]]);

        self::assertSame('asistio', $result['status']);
        self::assertSame(60, $result['attended_seconds']);
    }

    public function test_student_never_receives_late_status(): void
    {
        $result = $this->calculator->calculate('alumno', $this->start, $this->end, [[
            'join_at' => '2026-08-05 20:00:00',
            'leave_at' => '2026-08-05 20:01:00',
        ]]);

        self::assertSame('asistio', $result['status']);
    }

    public function test_teacher_at_70059_is_on_time_with_eighty_percent(): void
    {
        $result = $this->calculator->calculate('docente', $this->start, $this->end, [[
            'join_at' => '2026-08-05 19:00:59',
            'leave_at' => '2026-08-05 21:25:00',
        ]]);

        self::assertSame('presente', $result['status']);
        self::assertGreaterThanOrEqual(80, $result['attendance_percentage']);
    }

    public function test_teacher_at_701_is_late_with_eighty_percent(): void
    {
        $result = $this->calculator->calculate('docente', $this->start, $this->end, [[
            'join_at' => '2026-08-05 19:01:00',
            'leave_at' => '2026-08-05 21:30:00',
        ]]);

        self::assertSame('tardanza', $result['status']);
    }

    public function test_teacher_below_eighty_percent_is_absent(): void
    {
        $result = $this->calculator->calculate('docente', $this->start, $this->end, [[
            'join_at' => '2026-08-05 19:00:00',
            'leave_at' => '2026-08-05 21:23:59',
        ]]);

        self::assertSame('falta', $result['status']);
        self::assertLessThan(80, $result['attendance_percentage']);
    }

    public function test_reconnections_are_merged_without_duplicate_seconds(): void
    {
        $result = $this->calculator->calculate('docente', $this->start, $this->end, [
            ['join_at' => '2026-08-05 19:00:00', 'leave_at' => '2026-08-05 20:30:00'],
            ['join_at' => '2026-08-05 20:00:00', 'leave_at' => '2026-08-05 22:00:00'],
        ]);

        self::assertSame(10800, $result['attended_seconds']);
        self::assertSame(100.0, $result['attendance_percentage']);
    }
}
