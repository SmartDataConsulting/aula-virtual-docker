<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DbSafe
{
    public static function select(string $connection, string $sql, array $params = [])
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? null;

        $origen = isset($caller['class'], $caller['function'])
            ? $caller['class'].'::'.$caller['function']
            : 'desconocido';

        try {

            return DB::connection($connection)->select($sql, $params);

        } catch (\Throwable $e) {

            Log::warning('DB primer intento fallido', [
                'origen'   => $origen,
                'conexion' => $connection,
                'sql'      => mb_substr(trim($sql), 0, 200),
                'params'   => $params,
                'mensaje'  => $e->getMessage(),
            ]);

            usleep(200000);

            try {

                return DB::connection($connection)->select($sql, $params);

            } catch (\Throwable $e2) {

                Log::error('DB segundo intento fallido', [
                    'origen'   => $origen,
                    'conexion' => $connection,
                    'sql'      => mb_substr(trim($sql), 0, 200),
                    'params'   => $params,
                    'mensaje'  => $e2->getMessage(),
                ]);

                throw $e2;
            }
        }
    }
    
    public static function execute(string $connection, \Closure $callback)
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? null;

        $origen = isset($caller['class'], $caller['function'])
            ? $caller['class'].'::'.$caller['function']
            : 'desconocido';

        try {

            return $callback();

        } catch (\Throwable $e) {

            Log::warning('DB execute primer intento fallido', [
                'origen'   => $origen,
                'conexion' => $connection,
                'mensaje'  => $e->getMessage(),
            ]);

            usleep(200000);

            try {

                return $callback();

            } catch (\Throwable $e2) {

                Log::error('DB execute segundo intento fallido', [
                    'origen'   => $origen,
                    'conexion' => $connection,
                    'mensaje'  => $e2->getMessage(),
                ]);

                throw $e2;
            }
        }
    }

    public static function statement(string $connection, string $sql, array $params = [])
{
    $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? null;

    $origen = isset($caller['class'], $caller['function'])
        ? $caller['class'].'::'.$caller['function']
        : 'desconocido';

    try {

        return DB::connection($connection)->affectingStatement($sql, $params);

    } catch (\Throwable $e) {

        Log::warning('DB statement primer intento fallido', [
            'origen'   => $origen,
            'conexion' => $connection,
            'sql'      => mb_substr(trim($sql), 0, 200),
            'params'   => $params,
            'mensaje'  => $e->getMessage(),
        ]);

        usleep(200000);

        try {

            return DB::connection($connection)->affectingStatement($sql, $params);

        } catch (\Throwable $e2) {

            Log::error('DB statement segundo intento fallido', [
                'origen'   => $origen,
                'conexion' => $connection,
                'sql'      => mb_substr(trim($sql), 0, 200),
                'params'   => $params,
                'mensaje'  => $e2->getMessage(),
            ]);

            throw $e2;
        }
    }
}

public static function update(string $connection, string $sql, array $params = [])
{
    $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? null;

    $origen = isset($caller['class'], $caller['function'])
        ? $caller['class'].'::'.$caller['function']
        : 'desconocido';

    try {

        return DB::connection($connection)->affectingStatement($sql, $params);

    } catch (\Throwable $e) {

        Log::warning('DB update primer intento fallido', [
            'origen'   => $origen,
            'conexion' => $connection,
            'sql'      => mb_substr(trim($sql), 0, 200),
            'params'   => $params,
            'mensaje'  => $e->getMessage(),
        ]);

        usleep(200000);

        try {

            return DB::connection($connection)->affectingStatement($sql, $params);

        } catch (\Throwable $e2) {

            Log::error('DB update segundo intento fallido', [
                'origen'   => $origen,
                'conexion' => $connection,
                'sql'      => mb_substr(trim($sql), 0, 200),
                'params'   => $params,
                'mensaje'  => $e2->getMessage(),
            ]);

            throw $e2;
        }
    }
}
}