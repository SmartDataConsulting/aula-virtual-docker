<?php

namespace Tests\Unit;

use App\Services\Support\ServiceResult;
use App\Support\PerformanceCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PerformanceCacheTest extends TestCase
{
    public function test_failed_service_result_uses_last_successful_value(): void
    {
        $key = 'test:stale-service-result';
        PerformanceCache::forget($key);
        $success = ServiceResult::success(['courses' => [1, 2, 3]]);

        self::assertSame($success, PerformanceCache::remember($key, 60, fn () => $success));
        Cache::forget('portal-perf:'.$key);

        $result = PerformanceCache::remember(
            $key,
            60,
            fn () => ServiceResult::failure(['message' => 'timeout'])
        );

        self::assertTrue($result->ok());
        self::assertSame([1, 2, 3], $result->data()['courses']);
        PerformanceCache::forget($key);
    }

    public function test_failed_service_result_is_not_cached_without_a_stale_value(): void
    {
        $key = 'test:failed-service-result';
        PerformanceCache::forget($key);
        $calls = 0;

        PerformanceCache::remember($key, 60, function () use (&$calls) {
            $calls++;
            return ServiceResult::failure(['message' => 'timeout']);
        });
        PerformanceCache::remember($key, 60, function () use (&$calls) {
            $calls++;
            return ServiceResult::failure(['message' => 'timeout']);
        });

        self::assertSame(2, $calls);
    }
}
