<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\RequestCorrelationId::class);
        $middleware->append(\App\Http\Middleware\RequestTiming::class);
        $middleware->alias([
            'auth.session' => \App\Http\Middleware\EnsureSessionAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (Throwable $exception, Request $request) {
            if ($exception instanceof ValidationException) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return null;
            }

            $status = 500;
            $message = 'Ocurrio un error inesperado.';

            if ($exception instanceof ConnectionException) {
                $status = 503;
                $message = 'Servicio no disponible. Intenta mas tarde.';
            }

            Log::error('Unhandled exception', [
                'status' => $status,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            $correlationId = (string) $request->attributes->get('correlation_id', '');
            $wantsJson = $request->expectsJson() || $request->is('api/*');

            if ($wantsJson) {
                $payload = [
                    'ok' => false,
                    'message' => $message,
                    'correlation_id' => $correlationId,
                ];

                if (config('app.debug')) {
                    $payload['debug'] = [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ];
                }

                return response()->json($payload, $status);
            }

            return response()->view('errors.general', [
                'message' => $message,
                'correlation_id' => $correlationId,
            ], $status);
        });
    })->create();
