<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use App\Services\AuditSnapshotService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditMutation
{
    public function __construct(
        private AuditService $audit,
        private AuditSnapshotService $snapshots
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        $event = $this->eventFor($request);
        if ($event === null) {
            return $next($request);
        }

        [$before, $beforeFailed] = $this->capture(
            fn () => $this->snapshots->captureBefore($request, $event),
            $event,
            'before'
        );

        $response = $next($request);
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 500;
        if ($status < 200 || $status >= 300) {
            return $response;
        }

        [$after, $afterFailed] = $this->capture(
            function () use ($request, &$event, $response) {
                return $this->snapshots->captureAfter($request, $event, $response);
            },
            $event,
            'after'
        );

        $this->enrichEvent($event, $after ?? $before);

        if (!$this->snapshotsAreComplete($event, $before, $after, $beforeFailed, $afterFailed)) {
            Log::warning('audit_event_skipped_incomplete_snapshot', [
                'action' => $event['action'],
                'entity_type' => $event['entity_type'],
                'entity_id' => $event['entity_id'] ?? null,
                'before_failed' => $beforeFailed,
                'after_failed' => $afterFailed,
            ]);

            return $response;
        }

        try {
            $this->audit->record($request, $event, $before, $after);
        } catch (\Throwable $exception) {
            Log::error('audit_record_failed', [
                'action' => $event['action'],
                'entity_type' => $event['entity_type'],
                'exception' => $exception::class,
            ]);
        }

        return $response;
    }

    private function eventFor(Request $request): ?array
    {
        $method = strtoupper($request->method());
        $path = trim($request->path(), '/');

        if ($method === 'POST' && preg_match('#(?:^|/)sesiones/(\d+)/materiales$#', $path, $matches)) {
            return $this->event('material.created', 'material', null, null, $matches[1]);
        }

        if (in_array($method, ['PUT', 'DELETE'], true)
            && preg_match('#(?:^|/)sesiones/(\d+)/materiales/(\d+)$#', $path, $matches)) {
            $action = $method === 'PUT' ? 'material.updated' : 'material.deleted';
            return $this->event($action, 'material', $matches[2], null, $matches[1]);
        }

        if ($method === 'POST' && preg_match('#(?:^|/)anuncios$#', $path)) {
            return $this->event('announcement.created', 'announcement');
        }

        if (in_array($method, ['PUT', 'DELETE'], true)
            && preg_match('#(?:^|/)anuncios/(\d+)$#', $path, $matches)) {
            $action = $method === 'PUT' ? 'announcement.updated' : 'announcement.deleted';
            return $this->event($action, 'announcement', $matches[1]);
        }

        if ($method === 'POST' && preg_match('#(?:^|/)sesiones/(\d+)/evaluacion$#', $path, $matches)) {
            return $this->event('evaluation.assigned', 'evaluation', null, null, $matches[1]);
        }

        if (in_array($method, ['PUT', 'DELETE'], true)
            && preg_match('#(?:^|/)sesiones/(\d+)/evaluacion/(\d+)$#', $path, $matches)) {
            $action = $method === 'PUT' ? 'evaluation.updated' : 'evaluation.removed';
            return $this->event($action, 'evaluation', $matches[2], null, $matches[1]);
        }

        if ($method === 'POST' && preg_match('#(?:^|/)evaluaciones/(\d+)/publicar$#', $path, $matches)) {
            return $this->event('evaluation.published', 'evaluation_definition', $matches[1]);
        }

        if ($method === 'PATCH'
            && preg_match('#(?:^|/)sesiones/(\d+)/asistencias/(\d+)$#', $path, $matches)) {
            return $this->event('attendance.updated', 'attendance', $matches[2], null, $matches[1]);
        }

        if ($method === 'POST'
            && preg_match('#(?:^|/)sesiones/(\d+)/asistencias/(identify|sync)$#', $path, $matches)) {
            $action = $matches[2] === 'identify' ? 'attendance.identified' : 'attendance.synced';
            return $this->event($action, 'attendance', null, null, $matches[1]);
        }

        if ($method === 'POST'
            && preg_match('#(?:^|/)sesiones/(\d+)/video/([a-z-]+)$#', $path, $matches)) {
            return $this->event('video.'.str_replace('-', '_', $matches[2]), 'video', $matches[1], null, $matches[1]);
        }

        return null;
    }

    private function event(
        string $action,
        string $entityType,
        $entityId = null,
        $courseId = null,
        $sessionId = null
    ): array {
        return [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $this->positiveInt($entityId),
            'curso_edicion_id' => $this->positiveInt($courseId),
            'session_id' => $this->positiveInt($sessionId),
        ];
    }

    private function capture(callable $capture, array $event, string $phase): array
    {
        try {
            return [$capture(), false];
        } catch (\Throwable $exception) {
            Log::warning('audit_snapshot_failed', [
                'phase' => $phase,
                'action' => $event['action'],
                'entity_type' => $event['entity_type'],
                'entity_id' => $event['entity_id'] ?? null,
                'exception' => $exception::class,
            ]);

            return [null, true];
        }
    }

    private function snapshotsAreComplete(
        array $event,
        ?array $before,
        ?array $after,
        bool $beforeFailed,
        bool $afterFailed
    ): bool {
        if ($event['entity_type'] === 'video') {
            return !$beforeFailed && !$afterFailed;
        }

        if ($beforeFailed || $afterFailed) {
            return false;
        }

        $action = (string) $event['action'];
        if (str_ends_with($action, '.created')) {
            return $before === null && $after !== null;
        }

        if (str_ends_with($action, '.deleted') || str_ends_with($action, '.removed')) {
            return $before !== null && $after === null;
        }

        return $before !== null && $after !== null;
    }

    private function enrichEvent(array &$event, ?array $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        $event['curso_edicion_id'] ??= $this->positiveInt($snapshot['curso_edicion_id'] ?? null);
        $event['session_id'] ??= $this->positiveInt(
            $snapshot['session_id'] ?? $snapshot['curso_edicion_sesion_id'] ?? null
        );
    }

    private function positiveInt($value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
