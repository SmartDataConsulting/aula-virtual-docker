<?php

namespace Tests\Unit;

use App\Services\Support\ServiceResult;
use App\Support\PerformanceCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PerformanceCacheRecoveryTest extends TestCase
{
    private string $key = 'courses:main:admin:all';

    protected function setUp(): void
    {
        parent::setUp();
        PerformanceCache::forget($this->key);
    }

    private function freshKey(): string
    {
        return PerformanceCache::NAMESPACE.$this->key;
    }

    public function test_invalidation_uses_effective_profile_and_actor_without_clearing_another_profile(): void
    {
        session([\App\Support\AuthSessionKeys::USER_ROLE => 'operador',
            \App\Support\AuthSessionKeys::AULA_ROLE => 'docente',
            \App\Support\AuthSessionKeys::USER_EMAIL => 'teacher@example.invalid']);
        foreach (['docente', 'operador'] as $role) {
            $key = PerformanceCache::NAMESPACE.PerformanceCache::courseListKey('main', $role, 'teacher@example.invalid');
            Cache::put($key, 'fixture', 60);
            Cache::put($key.':stale', 'fixture', 60);
        }
        PerformanceCache::forgetCourseLists();
        $teacherKey = PerformanceCache::NAMESPACE.PerformanceCache::courseListKey('main', 'docente', 'teacher@example.invalid');
        $operatorKey = PerformanceCache::NAMESPACE.PerformanceCache::courseListKey('main', 'operador', 'teacher@example.invalid');
        self::assertNull(Cache::get($teacherKey));
        self::assertNull(Cache::get($teacherKey.':stale'));
        self::assertSame('fixture', Cache::get($operatorKey));
        self::assertSame('fixture', Cache::get($operatorKey.':stale'));
        $allKey = PerformanceCache::NAMESPACE.PerformanceCache::courseListKey('main', 'docente', '');
        Cache::put($allKey, 'fixture', 60);
        PerformanceCache::forgetCourseLists(email: '');
        self::assertNull(Cache::get($allKey));
    }

    public function test_fresh_hit_does_not_call_backend(): void
    {
        $fresh = ServiceResult::success(['courses' => [1]]);
        Cache::put($this->freshKey(), $fresh, 60);
        self::assertSame($fresh, PerformanceCache::rememberFreshOrStaleOnError($this->key, 60, function () {
            self::fail('Backend called on fresh hit.');
        }));
    }

    public function test_empty_successful_stale_is_replaced_synchronously_by_recovered_courses(): void
    {
        Cache::put($this->freshKey().':stale', ServiceResult::success(['courses' => []]), 3600);
        $new = ServiceResult::success(['courses' => [['id' => 127]]]);
        $calls = 0;
        $result = PerformanceCache::rememberFreshOrStaleOnError($this->key, 60, function () use (&$calls, $new) {
            $calls++;
            return $new;
        });
        self::assertSame(1, $calls);
        self::assertSame($new, $result);
        self::assertSame($new, Cache::get($this->freshKey()));
        self::assertSame($new, Cache::get($this->freshKey().':stale'));
    }

    public function test_failure_uses_valid_stale_without_replacing_it_and_logs_status(): void
    {
        $stale = ServiceResult::success(['courses' => [1]]);
        Cache::put($this->freshKey().':stale', $stale, 3600);
        Log::shouldReceive('warning')->once()->with('performance_cache_stale_on_error', [
            'cache_key' => hash('sha256', $this->freshKey()), 'scope' => 'courses:main', 'status' => 503,
        ]);
        $result = PerformanceCache::rememberFreshOrStaleOnError($this->key, 60,
            fn () => ServiceResult::failure(['message' => 'Unavailable'], 503));
        self::assertSame($stale, $result);
        self::assertSame($stale, Cache::get($this->freshKey().':stale'));
        self::assertFalse(Cache::has($this->freshKey()));
    }

    public function test_failure_without_stale_is_returned_and_never_cached(): void
    {
        $failure = ServiceResult::failure(['message' => 'Unavailable'], 502);
        self::assertSame($failure, PerformanceCache::rememberFreshOrStaleOnError($this->key, 60, fn () => $failure));
        self::assertFalse(Cache::has($this->freshKey()));
        self::assertFalse(Cache::has($this->freshKey().':stale'));
    }

    public function test_exception_uses_stale_and_does_not_poison_cache(): void
    {
        $stale = ServiceResult::success(['courses' => [1]]);
        Cache::put($this->freshKey().':stale', $stale, 3600);
        $result = PerformanceCache::rememberFreshOrStaleOnError($this->key, 60, function () {
            throw new \RuntimeException('Sensitive upstream detail');
        });
        self::assertSame($stale, $result);
        self::assertSame($stale, Cache::get($this->freshKey().':stale'));
        self::assertFalse(Cache::has($this->freshKey()));
    }

    public function test_exception_without_stale_returns_failure(): void
    {
        $result = PerformanceCache::rememberFreshOrStaleOnError($this->key, 60, function () {
            throw new \RuntimeException('Sensitive upstream detail');
        });
        self::assertFalse($result->ok());
        self::assertSame(503, $result->status());
        self::assertStringNotContainsString('Sensitive', json_encode($result->error()));
        self::assertFalse(Cache::has($this->freshKey()));
        self::assertFalse(Cache::has($this->freshKey().':stale'));
    }

    public function test_successful_empty_courses_is_cached_as_legitimate_result(): void
    {
        $empty = ServiceResult::success(['courses' => []]);
        self::assertSame($empty, PerformanceCache::rememberFreshOrStaleOnError($this->key, 60, fn () => $empty));
        self::assertSame($empty, Cache::get($this->freshKey()));
        self::assertSame($empty, Cache::get($this->freshKey().':stale'));
    }

    public function test_v1_entries_and_failed_stale_are_not_reused(): void
    {
        Cache::put('portal-perf:'.$this->key, ServiceResult::success(['courses' => []]), 60);
        Cache::put('portal-perf:'.$this->key.':stale', ServiceResult::success(['courses' => []]), 3600);
        Cache::put($this->freshKey().':stale', ServiceResult::failure(['message' => 'Old failure']), 60);
        $failure = ServiceResult::failure(['message' => 'Unavailable'], 503);
        self::assertSame($failure, PerformanceCache::rememberFreshOrStaleOnError($this->key, 60, fn () => $failure));
        self::assertFalse(Cache::has($this->freshKey()));
    }
}
