<?php

namespace App\Support;

class ApiRequestMetrics
{
    private static int $count = 0;
    private static int $durationMs = 0;
    private static int $slowCount = 0;

    public static function record(int $durationMs, int $status = 0): void
    {
        self::$count++;
        self::$durationMs += max(0, $durationMs);

        if ($durationMs >= (int) env('API_SERVICIOS_SLOW_LOG_MS', 800) || $status >= 500) {
            self::$slowCount++;
        }
    }

    public static function snapshot(): array
    {
        return [
            'api_calls' => self::$count,
            'api_duration_ms' => self::$durationMs,
            'api_slow_calls' => self::$slowCount,
        ];
    }
}
