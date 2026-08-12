<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    public function boot(): void
    {
        $thresholdMs = (float) env('DB_SLOW_QUERY_MS', 250);

        if ($thresholdMs <= 0) {
            return;
        }

        DB::listen(function ($query) use ($thresholdMs) {
            if ((float) $query->time < $thresholdMs) {
                return;
            }

            Log::warning('Slow database query', [
                'connection' => $query->connectionName,
                'duration_ms' => round((float) $query->time, 2),
                'sql' => $this->summarizeSql((string) $query->sql),
                'bindings_count' => count($query->bindings ?? []),
            ]);
        });
    }

    private function summarizeSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?: '';

        return mb_strlen($sql) > 500 ? mb_substr($sql, 0, 500).'...' : $sql;
    }
}
