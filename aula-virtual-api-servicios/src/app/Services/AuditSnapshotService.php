<?php

namespace App\Services;

use App\Helpers\DbSafe;
use Illuminate\Http\Request;

class AuditSnapshotService
{
    public function captureBefore(Request $request, array $event): ?array
    {
        return $this->isCreate($event) ? null : $this->capture($request, $event, null);
    }

    public function captureAfter(Request $request, array &$event, $response): ?array
    {
        return $this->isDelete($event) ? null : $this->capture($request, $event, $this->responseId($response));
    }

    protected function capture(Request $request, array &$event, ?int $responseId): ?array
    {
        return match ($event['entity_type']) {
            'material' => $this->material($event, $responseId),
            'announcement' => $this->announcement($event, $responseId),
            'evaluation' => $this->evaluation($event),
            'evaluation_definition' => $this->evaluationDefinition($event, $responseId),
            'attendance' => $this->attendance($request, $event),
            'video' => $this->video($event),
            default => null,
        };
    }

    private function material(array &$event, ?int $responseId): ?array
    {
        $id = $this->id($event['entity_id'] ?? null) ?? $responseId;
        if (!$id) return null;
        $event['entity_id'] = $id;

        return $this->one(<<<'SQL'
            SELECT m.id, m.curso_edicion_sesion_id, s.curso_edicion_id, m.titulo,
                   m.descripcion, m.tipo, m.url_externa, m.orden, m.activo
            FROM curso_edicion_sesion_materiales m
            INNER JOIN curso_edicion_sesiones s ON s.id = m.curso_edicion_sesion_id
            WHERE m.id = ? LIMIT 1
        SQL, [$id]);
    }

    private function announcement(array &$event, ?int $responseId): ?array
    {
        $id = $this->id($event['entity_id'] ?? null) ?? $responseId;
        if (!$id) return null;
        $event['entity_id'] = $id;

        return $this->one(<<<'SQL'
            SELECT a.id, a.entidad_tipo, a.entidad_id,
                   CASE WHEN a.entidad_tipo = 'curso' THEN a.entidad_id ELSE s.curso_edicion_id END AS curso_edicion_id,
                   CASE WHEN a.entidad_tipo = 'sesion' THEN a.entidad_id ELSE NULL END AS session_id,
                   a.titulo, a.contenido, a.tipo, a.enlace_url, a.activo
            FROM curso_edicion_anuncios a
            LEFT JOIN curso_edicion_sesiones s ON a.entidad_tipo = 'sesion' AND s.id = a.entidad_id
            WHERE a.id = ? LIMIT 1
        SQL, [$id]);
    }

    private function evaluation(array &$event): ?array
    {
        $sessionId = $this->id($event['session_id'] ?? null);
        if (!$sessionId) return null;

        $context = $this->sessionContext($sessionId);
        if ($context === null) {
            return null;
        }

        $rows = DbSafe::select('mysql_cursos', <<<'SQL'
            SELECT se.sesion_id, se.evaluacion_id, e.nombre, e.tipo_param_id, e.publicada,
                   se.fecha_limite, se.hito_nombre, se.hito_orden, se.grupo_nombre, se.plazo_dias
            FROM curso_edicion_sesion_evaluaciones se
            INNER JOIN evaluacion e ON e.id = se.evaluacion_id
            WHERE se.sesion_id = ? ORDER BY se.evaluacion_id
        SQL, [$sessionId]);

        return $context + ['items' => $this->rows($rows), 'total' => count($rows)];
    }

    private function evaluationDefinition(array &$event, ?int $responseId): ?array
    {
        $id = $responseId ?? $this->id($event['entity_id'] ?? null);
        if (!$id) {
            return null;
        }

        $event['entity_id'] = $id;

        return $this->one(
            'SELECT id, curso_id AS curso_edicion_id, nombre, tipo_param_id, peso, publicada, activo FROM evaluacion WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    private function attendance(Request $request, array &$event): ?array
    {
        $action = (string) ($event['action'] ?? '');
        $sessionId = $this->id($event['session_id'] ?? null);
        $context = $sessionId ? $this->sessionContext($sessionId) : null;
        if ($context === null) {
            return null;
        }

        if ($action === 'attendance.identified') {
            $eventId = $this->id($request->input('event_id'));
            if (!$eventId) return null;
            $event['entity_id'] = $eventId;
            $snapshot = $this->one('SELECT id AS event_id, asistencia_id, meeting_id, tipo_evento, ocurrido_at FROM curso_edicion_sesion_asistencia_eventos WHERE id = ? LIMIT 1', [$eventId]);
            return $snapshot === null ? null : $context + $snapshot;
        }

        $attendanceId = $this->id($event['entity_id'] ?? null) ?? $this->id($request->input('attendance_id'));
        if ($attendanceId && $action !== 'attendance.synced') {
            $event['entity_id'] = $attendanceId;
            $snapshot = $this->one(<<<'SQL'
                SELECT a.id AS attendance_id, a.curso_edicion_sesion_id AS session_id,
                       a.tipo_participante, a.alumno_correo, a.estado_automatico, a.estado_manual,
                       a.motivo_manual, a.primer_ingreso_at, a.ultima_salida_at,
                       a.segundos_asistencia, a.porcentaje_permanencia,
                       a.zoom_verificado_at, a.finalizado_at
                FROM curso_edicion_sesion_asistencias a WHERE a.id = ? LIMIT 1
            SQL, [$attendanceId]);
            return $snapshot === null ? null : $context + $snapshot;
        }

        $rows = DbSafe::select('mysql_cursos', <<<'SQL'
            SELECT COALESCE(estado_manual, estado_automatico, 'pendiente') AS estado, COUNT(*) AS total
            FROM curso_edicion_sesion_asistencias WHERE curso_edicion_sesion_id = ?
            GROUP BY COALESCE(estado_manual, estado_automatico, 'pendiente') ORDER BY estado
        SQL, [$sessionId]);

        return $context + ['summary' => $this->rows($rows), 'total' => array_sum(array_map(fn ($row) => (int) $row->total, $rows))];
    }

    private function video(array &$event): ?array
    {
        $sessionId = $this->id($event['session_id'] ?? null);
        if (!$sessionId) return null;
        $event['entity_id'] = $sessionId;

        return $this->one(<<<'SQL'
            SELECT id AS session_id, curso_edicion_id, video_status, video_drive_file_id,
                   video_uploaded_at, video_filesize, video_chat_drive_file_id,
                   video_chat_titulo, video_chat_filesize, video_chat_uploaded_at
            FROM curso_edicion_sesiones WHERE id = ? LIMIT 1
        SQL, [$sessionId]);
    }

    private function one(string $sql, array $bindings): ?array
    {
        $rows = DbSafe::select('mysql_cursos', $sql, $bindings);
        return $rows === [] ? null : (array) $rows[0];
    }

    private function sessionContext(int $sessionId): ?array
    {
        return $this->one(
            'SELECT id AS session_id, curso_edicion_id FROM curso_edicion_sesiones WHERE id = ? LIMIT 1',
            [$sessionId]
        );
    }

    private function rows(array $rows): array
    {
        return array_map(fn ($row) => (array) $row, $rows);
    }

    private function responseId($response): ?int
    {
        if (!method_exists($response, 'getContent')) return null;
        $payload = json_decode((string) $response->getContent(), true);
        return is_array($payload) ? $this->id($payload['id'] ?? $payload['data']['id'] ?? null) : null;
    }

    private function isCreate(array $event): bool
    {
        return str_ends_with((string) ($event['action'] ?? ''), '.created');
    }

    private function isDelete(array $event): bool
    {
        return str_ends_with((string) ($event['action'] ?? ''), '.deleted')
            || str_ends_with((string) ($event['action'] ?? ''), '.removed');
    }

    private function id($value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
