<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PerformanceCache
{
    public const COURSE_LIST_TTL = 120;
    public const DETAIL_TTL = 60;
    public const CATALOG_TTL = 900;
    public const SHORT_TTL = 10;
    private const STALE_TTL = 3600;

    public static function remember(string $key, int $seconds, Closure $callback): mixed
    {
        $freshKey = self::prefix($key);
        if (Cache::has($freshKey)) {
            return Cache::get($freshKey);
        }

        $staleKey = self::stalePrefix($key);
        if (Cache::has($staleKey)) {
            self::refreshAfterResponse($key, $seconds, $callback);
            return Cache::get($staleKey);
        }

        $value = $callback();
        $isServiceResult = is_object($value) && method_exists($value, 'ok');

        if ($isServiceResult && !$value->ok()) {
            return Cache::has($staleKey) ? Cache::get($staleKey) : $value;
        }

        Cache::put($freshKey, $value, $seconds);
        if ($isServiceResult) {
            Cache::put(self::stalePrefix($key), $value, self::STALE_TTL);
        }

        return $value;
    }

    private static function refreshAfterResponse(string $key, int $seconds, Closure $callback): void
    {
        $lockKey = self::prefix($key) . ':refreshing';

        try {
            $lock = Cache::lock($lockKey, 30);
            if (!$lock->get()) {
                return;
            }

            app()->terminating(function () use ($key, $seconds, $callback, $lock) {
                try {
                    $value = $callback();
                    $isServiceResult = is_object($value) && method_exists($value, 'ok');

                    if ($isServiceResult && !$value->ok()) {
                        return;
                    }

                    Cache::put(self::prefix($key), $value, $seconds);
                    if ($isServiceResult) {
                        Cache::put(self::stalePrefix($key), $value, self::STALE_TTL);
                    }
                } finally {
                    optional($lock)->release();
                }
            });
        } catch (Throwable) {
            // Some cache stores do not support locks. Serving stale is still better
            // than blocking the user on a slow upstream request.
        }
    }

    public static function forget(string $key): void
    {
        Cache::forget(self::prefix($key));
        Cache::forget(self::stalePrefix($key));
    }

    public static function courseListKey(string $scope, ?string $role, ?string $email): string
    {
        return 'courses:' . $scope . ':' . self::clean($role ?: 'guest') . ':' . self::clean($email ?: 'all');
    }

    public static function parametersKey(int $idMaestro): string
    {
        return 'parameters:maestro:' . $idMaestro;
    }

    public static function paymentKey(string $email): string
    {
        return 'payments:' . self::clean($email);
    }

    public static function forgetCourseLists(?string $role = null, ?string $email = null): void
    {
        foreach (['main', 'evaluations', 'qualifications', 'surveys', 'certificates'] as $scope) {
            self::forget(self::courseListKey($scope, $role ?: session(AuthSessionKeys::USER_ROLE), $email ?: session(AuthSessionKeys::USER_EMAIL)));
        }
    }

    private static function prefix(string $key): string
    {
        return 'portal-perf:' . $key;
    }

    private static function stalePrefix(string $key): string
    {
        return self::prefix($key) . ':stale';
    }

    private static function clean(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', strtolower(trim($value))) ?: 'empty';
    }
}
