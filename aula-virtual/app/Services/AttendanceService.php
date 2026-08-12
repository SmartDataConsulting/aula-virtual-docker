<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;

class AttendanceService
{
    public function __construct(private ApiServiciosClient $client) {}

    public function join(int $courseId, int $sessionId): ServiceResult
    {
        return $this->client->registrarIntentoZoom($courseId, $sessionId);
    }

    public function course(int $courseId): ServiceResult
    {
        $result = $this->client->listarAsistenciaCurso($courseId);
        if (!$result->ok()) return $result;
        $payload = is_array($result->data()) ? $result->data() : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        return ServiceResult::success([
            'items' => collect($data['items'] ?? [])->map(fn ($item) => $this->normalize($item))->all(),
            'unresolved' => $data['unresolved'] ?? [],
        ]);
    }

    public function courseSummaries(): ServiceResult
    {
        $result = $this->client->listarResumenesAsistenciaCursos();
        if (!$result->ok()) return $result;

        $payload = is_array($result->data()) ? $result->data() : [];
        return ServiceResult::success(collect($payload['data'] ?? [])->map(function ($item) {
            $data = is_array($item) ? $item : (array) $item;
            return [
                'course_id' => (int) ($data['course_id'] ?? 0),
                'sessions_total' => (int) ($data['sessions_total'] ?? 0),
                'sessions_finished' => (int) ($data['sessions_finished'] ?? 0),
                'sessions_reconciled' => (int) ($data['sessions_reconciled'] ?? 0),
                'sessions_pending' => (int) ($data['sessions_pending'] ?? 0),
                'unresolved_count' => (int) ($data['unresolved_count'] ?? 0),
                'last_sync_at' => $data['last_sync_at'] ?? null,
                'attendance_status' => (string) ($data['attendance_status'] ?? 'no_records'),
            ];
        })->values()->all());
    }

    public function courseSessionSummaries(int $courseId): ServiceResult
    {
        $result = $this->client->listarResumenSesionesAsistencia($courseId);
        if (!$result->ok()) return $result;

        $payload = is_array($result->data()) ? $result->data() : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return ServiceResult::success([
            'summary' => is_array($data['summary'] ?? null) ? $data['summary'] : [],
            'sessions' => collect($data['sessions'] ?? [])->map(function ($item) {
                $value = is_array($item) ? $item : (array) $item;
                return (object) [
                    'id' => (int) ($value['session_id'] ?? 0),
                    'number' => (int) ($value['session_number'] ?? 0),
                    'date' => $value['date'] ?? null,
                    'start_time' => $value['start_time'] ?? null,
                    'end_time' => $value['end_time'] ?? null,
                    'status' => (string) ($value['status'] ?? 'no_records'),
                    'records_total' => (int) ($value['records_total'] ?? 0),
                    'students_count' => (int) ($value['students_count'] ?? 0),
                    'present_count' => (int) ($value['present_count'] ?? 0),
                    'absent_count' => (int) ($value['absent_count'] ?? 0),
                    'pending_count' => (int) ($value['pending_count'] ?? 0),
                    'teacher_status' => (string) ($value['teacher_status'] ?? 'pendiente'),
                    'unresolved_count' => (int) ($value['unresolved_count'] ?? 0),
                    'last_sync_at' => $value['last_sync_at'] ?? null,
                ];
            })->values(),
        ]);
    }

    public function session(int $courseId, int $sessionId): ServiceResult
    {
        $result = $this->client->listarAsistenciaSesion($courseId, $sessionId);
        if (!$result->ok()) return $result;

        $payload = is_array($result->data()) ? $result->data() : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $items = collect($data['items'] ?? [])->map(fn ($item) => $this->normalize($item))->values();
        $students = $items->where('participant_type', 'alumno');

        return ServiceResult::success([
            'enabled' => (bool) ($data['enabled'] ?? true),
            'meeting_scheduled' => (bool) ($data['meeting_scheduled'] ?? false),
            'sync_enabled' => (bool) ($data['sync_enabled'] ?? false),
            'status' => (string) ($data['status'] ?? 'pending'),
            'can_sync' => (bool) ($data['can_sync'] ?? false),
            'sync' => is_array($data['sync'] ?? null) ? $data['sync'] : null,
            'session' => is_array($data['session'] ?? null) ? $data['session'] : [],
            'items' => $items,
            'unresolved' => collect($data['unresolved'] ?? [])->values(),
            'summary' => [
                'count' => $items->count(),
                'students' => $students->count(),
                'present' => $students->whereIn('status', ['asistio', 'presente'])->count(),
                'absent' => $students->where('status', 'falta')->count(),
                'pending' => $students->where('status', 'pendiente')->count(),
            ],
        ]);
    }

    public function student(int $courseId): ServiceResult
    {
        $result = $this->client->listarMiAsistencia($courseId);
        if (!$result->ok()) return $result;
        $payload = is_array($result->data()) ? $result->data() : [];
        return ServiceResult::success(collect($payload['data'] ?? [])->map(fn ($item) => $this->normalize($item))->all());
    }

    public function override(int $sessionId, int $attendanceId, string $status, string $reason): ServiceResult
    {
        return $this->client->corregirAsistencia($sessionId, $attendanceId, $status, $reason);
    }

    public function sync(int $sessionId): ServiceResult
    {
        return $this->client->sincronizarAsistencia($sessionId);
    }

    public function identify(int $sessionId, int $eventId, int $attendanceId): ServiceResult
    {
        return $this->client->identificarParticipanteAsistencia($sessionId, $eventId, $attendanceId);
    }

    private function normalize(mixed $item): object
    {
        $data = is_array($item) ? $item : (array) $item;
        $status = (string) ($data['estado'] ?? $data['estado_manual'] ?? $data['estado_automatico'] ?? 'pendiente');
        return (object) [
            'id' => (int) ($data['id'] ?? 0),
            'session_id' => (int) ($data['curso_edicion_sesion_id'] ?? 0),
            'session_number' => (int) ($data['nro_sesion'] ?? 0),
            'date' => $data['fecha'] ?? null,
            'start_time' => $data['hora_inicio_prog'] ?? null,
            'end_time' => $data['hora_fin_prog'] ?? null,
            'participant_type' => $data['tipo_participante'] ?? 'alumno',
            'name' => $data['nombre_mostrado'] ?? 'Participante',
            'email' => $data['alumno_correo'] ?? null,
            'status' => $status,
            'automatic_status' => $data['estado_automatico'] ?? 'pendiente',
            'manual_status' => $data['estado_manual'] ?? null,
            'first_click_at' => $data['primer_click_at'] ?? null,
            'first_join_at' => $data['primer_ingreso_at'] ?? null,
            'last_leave_at' => $data['ultima_salida_at'] ?? null,
            'minutes' => (float) ($data['minutos_asistencia'] ?? ((int) ($data['segundos_asistencia'] ?? 0) / 60)),
            'percentage' => (float) ($data['porcentaje_permanencia'] ?? 0),
            'reason' => $data['motivo_manual'] ?? null,
            'finalized_at' => $data['finalizado_at'] ?? null,
        ];
    }
}
