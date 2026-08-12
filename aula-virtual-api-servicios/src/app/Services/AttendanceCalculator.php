<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class AttendanceCalculator
{
    public const TEACHER_MINIMUM_PERCENTAGE = 80.0;

    public function calculate(
        string $participantType,
        CarbonImmutable $scheduledStart,
        CarbonImmutable $scheduledEnd,
        array $intervals,
        bool $cancelled = false
    ): array {
        if ($cancelled) {
            return $this->result('no_aplica', null, null, 0, 0.0);
        }

        $merged = $this->mergeIntervals($scheduledStart, $scheduledEnd, $intervals);
        $firstJoin = $merged[0][0] ?? null;
        $lastLeave = $merged === [] ? null : $merged[array_key_last($merged)][1];
        $seconds = array_sum(array_map(
            fn (array $interval): int => $interval[0]->diffInSeconds($interval[1]),
            $merged
        ));
        $scheduledSeconds = max(1, $scheduledStart->diffInSeconds($scheduledEnd));
        $percentage = round(min(100, ($seconds / $scheduledSeconds) * 100), 2);

        if ($participantType === 'alumno') {
            return $this->result(
                $firstJoin ? 'asistio' : 'falta',
                $firstJoin,
                $lastLeave,
                $seconds,
                $percentage
            );
        }

        if (!$firstJoin || $percentage < self::TEACHER_MINIMUM_PERCENTAGE) {
            return $this->result('falta', $firstJoin, $lastLeave, $seconds, $percentage);
        }

        $lateBoundary = $scheduledStart->addMinute();
        $status = $firstJoin->gte($lateBoundary) ? 'tardanza' : 'presente';

        return $this->result($status, $firstJoin, $lastLeave, $seconds, $percentage);
    }

    private function mergeIntervals(
        CarbonImmutable $scheduledStart,
        CarbonImmutable $scheduledEnd,
        array $intervals
    ): array {
        $normalized = [];

        foreach ($intervals as $interval) {
            $join = $this->date($interval['join_at'] ?? null);
            $leave = $this->date($interval['leave_at'] ?? null) ?? $scheduledEnd;

            if (!$join || $leave->lte($scheduledStart) || $join->gte($scheduledEnd)) {
                continue;
            }

            $join = $join->lt($scheduledStart) ? $scheduledStart : $join;
            $leave = $leave->gt($scheduledEnd) ? $scheduledEnd : $leave;

            if ($leave->gt($join)) {
                $normalized[] = [$join, $leave];
            }
        }

        usort($normalized, fn (array $a, array $b): int => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());
        $merged = [];

        foreach ($normalized as $interval) {
            $last = array_key_last($merged);
            if ($last === null || $interval[0]->gt($merged[$last][1])) {
                $merged[] = $interval;
                continue;
            }

            if ($interval[1]->gt($merged[$last][1])) {
                $merged[$last][1] = $interval[1];
            }
        }

        return $merged;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if (!$value) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, 'America/Lima');
        } catch (\Throwable) {
            return null;
        }
    }

    private function result(
        string $status,
        ?CarbonImmutable $firstJoin,
        ?CarbonImmutable $lastLeave,
        int $seconds,
        float $percentage
    ): array {
        return [
            'status' => $status,
            'first_join_at' => $firstJoin?->format('Y-m-d H:i:s'),
            'last_leave_at' => $lastLeave?->format('Y-m-d H:i:s'),
            'attended_seconds' => $seconds,
            'attendance_percentage' => $percentage,
        ];
    }
}
