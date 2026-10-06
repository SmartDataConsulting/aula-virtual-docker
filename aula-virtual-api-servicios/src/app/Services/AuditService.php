<?php

namespace App\Services;

use App\Helpers\DbSafe;
use Illuminate\Http\Request;

class AuditService
{
    private const MAX_SNAPSHOT_BYTES = 32768;

    private const ALLOWED_SNAPSHOT_FIELDS = [
        'id',
        'curso_edicion_id',
        'curso_edicion_sesion_id',
        'session_id',
        'sesion_id',
        'entity_type',
        'entity_id',
        'entidad_tipo',
        'entidad_id',
        'titulo',
        'descripcion',
        'contenido',
        'tipo',
        'enlace_url',
        'url_externa',
        'orden',
        'activo',
        'evaluacion_id',
        'nombre',
        'tipo_param_id',
        'peso',
        'publicada',
        'fecha_limite',
        'hito_nombre',
        'hito_orden',
        'grupo_nombre',
        'plazo_dias',
        'attendance_id',
        'event_id',
        'tipo_participante',
        'alumno_correo',
        'estado_automatico',
        'estado_manual',
        'motivo_manual',
        'estado',
        'primer_ingreso_at',
        'ultima_salida_at',
        'segundos_asistencia',
        'porcentaje_permanencia',
        'zoom_verificado_at',
        'finalizado_at',
        'asistencia_id',
        'meeting_id',
        'tipo_evento',
        'ocurrido_at',
        'video_status',
        'video_drive_file_id',
        'video_uploaded_at',
        'video_filesize',
        'video_chat_drive_file_id',
        'video_chat_titulo',
        'video_chat_filesize',
        'video_chat_uploaded_at',
        'items',
        'summary',
        'total',
        'present',
        'absent',
        'pending',
        'updated_at',
    ];

    public function record(Request $request, array $event, ?array $before, ?array $after): void
    {
        $record = $this->buildRecord($request, $event, $before, $after);
        DbSafe::statement(
            'mysql_cursos',
            <<<'SQL'
                INSERT INTO aula_audit_logs
                    (actor_name, actor_email, actor_role, action, entity_type, entity_id,
                     curso_edicion_id, session_id, before_data, after_data, correlation_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL,
            array_values($record)
        );
    }

    public function buildRecord(Request $request, array $event, ?array $before, ?array $after): array
    {
        return [
            'actor_name' => $this->nullableHeader($request, 'X-USER-NAME'),
            'actor_email' => $this->nullableHeader($request, 'X-USER-EMAIL'),
            'actor_role' => $this->nullableHeader($request, 'X-USER-ROL'),
            'action' => (string) $event['action'],
            'entity_type' => (string) $event['entity_type'],
            'entity_id' => isset($event['entity_id']) ? (string) $event['entity_id'] : null,
            'curso_edicion_id' => $event['curso_edicion_id'] ?? null,
            'session_id' => $event['session_id'] ?? null,
            'before_data' => $this->snapshot($before),
            'after_data' => $this->snapshot($after),
            'correlation_id' => $this->nullableHeader($request, 'X-Correlation-ID'),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function snapshot(?array $snapshot): ?string
    {
        if ($snapshot === null) {
            return null;
        }

        $json = json_encode(
            $this->allowlisted($snapshot),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        if (strlen($json) <= self::MAX_SNAPSHOT_BYTES) {
            return $json;
        }

        return json_encode(
            ['total' => $snapshot['total'] ?? null, 'items' => [], 'summary' => [], 'truncated' => true],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function allowlisted(array $snapshot): array
    {
        if (array_is_list($snapshot)) {
            return array_map(
                fn ($item) => is_array($item) ? $this->allowlisted($item) : $item,
                $snapshot
            );
        }

        $allowed = [];
        foreach ($snapshot as $key => $value) {
            if (!in_array((string) $key, self::ALLOWED_SNAPSHOT_FIELDS, true)) {
                continue;
            }

            $allowed[$key] = is_array($value) ? $this->allowlisted($value) : $value;
        }

        return $allowed;
    }

    private function nullableHeader(Request $request, string $name): ?string
    {
        $value = trim((string) $request->header($name));
        return $value === '' ? null : $value;
    }
}
