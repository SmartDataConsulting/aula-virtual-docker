<?php

namespace App\Services;

use App\Repositories\AttendanceRepository;
use App\Repositories\MeetingRepository;
use App\Support\ApiCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttendanceService
{
    private const TIMEZONE = 'America/Lima';

    public function __construct(
        private AttendanceRepository $repository,
        private MeetingRepository $meetings,
        private MeetingService $meetingService,
        private AttendanceCalculator $calculator,
        private ZoomReportClient $zoom
    ) {}

    public function joinAttempt(int $courseId, int $sessionId, string $role, string $email): array
    {
        $this->assertEnabled();
        $session = $this->repository->sessionContext($sessionId);
        if (!$session || (int) $session->curso_edicion_id !== $courseId) {
            throw new \InvalidArgumentException('session_not_found');
        }
        $meeting = $this->meetingService->resolveMeeting($session);
        $access = $this->meetingService->presentMeeting($meeting, $role);
        if (!$meeting || !$access['can_join'] || empty($access['join_url'])) {
            throw new \DomainException('meeting_not_available');
        }

        $normalizedRole = strtolower($role);
        if (in_array($normalizedRole, ['admin', 'administrador'], true)) {
            return ['join_url' => $access['join_url'], 'attempt_recorded' => false];
        }

        if ($normalizedRole === 'alumno') {
            $person = $this->repository->enrolledStudent($courseId, $email);
            if (!$person) {
                throw new \DomainException('student_not_enrolled');
            }
            $attendance = $this->repository->upsertPerson(
                $sessionId, (int) $meeting->id, 'alumno', 'alumno:'.$person->correo,
                $person->correo, null, $person->nombre
            );
        } else {
            $person = $this->repository->assignedTeacher($sessionId, $email);
            if (!$person) {
                throw new \DomainException('teacher_not_assigned');
            }
            $attendance = $this->repository->upsertPerson(
                $sessionId, (int) $meeting->id, 'docente', 'docente:'.$person->colaborador_id,
                null, (int) $person->colaborador_id, $person->nombre
            );
        }

        $this->repository->recordClick(
            (int) $attendance->id,
            (int) $meeting->id,
            CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d H:i:s'),
            'click:'.Str::uuid()
        );

        return ['join_url' => $access['join_url'], 'attempt_recorded' => true];
    }

    public function processZoomEvent(array $payload, string $rawBody): array
    {
        if (!config('services.attendance.enabled', false)) {
            return ['ignored' => true, 'reason' => 'attendance_disabled'];
        }
        $eventName = (string) ($payload['event'] ?? '');
        if (!in_array($eventName, ['meeting.participant_joined', 'meeting.participant_left'], true)) {
            return ['ignored' => true];
        }
        $object = $payload['payload']['object'] ?? [];
        $participant = $object['participant'] ?? [];
        $externalMeetingId = (string) ($object['id'] ?? '');
        $meeting = $this->meetings->obtenerPorZoomId($externalMeetingId);
        if (!$meeting) {
            return ['ignored' => true, 'reason' => 'meeting_not_found'];
        }
        $session = $this->repository->sessionForMeeting($meeting);
        if (!$session) {
            return ['ignored' => true, 'reason' => 'session_not_resolved'];
        }
        $this->repository->ensureRoster($session, (int) $meeting->id);

        $email = strtolower(trim((string) ($participant['email'] ?? $participant['user_email'] ?? '')));
        $participantId = trim((string) ($participant['id'] ?? $participant['user_id'] ?? ''));
        $attendance = $email !== ''
            ? $this->repository->resolveAttendanceByEmail((int) $session->id, $email)
            : null;
        $attendance ??= $participantId !== ''
            ? $this->repository->resolveAttendanceByParticipantId((int) $session->id, $participantId)
            : null;
        if ($attendance && $participantId !== '') {
            $this->repository->rememberParticipantIdentity($attendance, $participantId);
        }

        $occurredAt = $this->eventDate($payload, $participant);
        $type = $eventName === 'meeting.participant_joined' ? 'join' : 'leave';
        $eventId = 'zoom:'.hash('sha256', $rawBody);
        $inserted = $this->repository->insertZoomEvent([
            'attendance_id' => $attendance->id ?? null,
            'meeting_id' => (int) $meeting->id,
            'external_event_id' => $eventId,
            'source' => 'zoom_webhook',
            'type' => $type,
            'occurred_at' => $occurredAt,
            'participant_id' => $participantId ?: null,
            'email' => $email ?: null,
            'name' => trim((string) ($participant['user_name'] ?? $participant['name'] ?? '')) ?: null,
        ]);
        if ($inserted && $attendance) {
            $this->repository->updateLiveEvent((int) $attendance->id, $type, $occurredAt);
        }
        return ['ignored' => false, 'resolved' => (bool) $attendance, 'duplicate' => !$inserted];
    }

    public function listCourse(int $courseId, string $role, string $email): array
    {
        $this->assertCourseAccess($courseId, $role, $email);
        $this->ensureCourseRoster($courseId);
        return [
            'items' => $this->repository->courseAttendance($courseId),
            'unresolved' => $this->repository->unresolvedParticipants($courseId),
        ];
    }

    public function listCourseSummaries(string $role, string $email): array
    {
        $key = ApiCache::attendanceSummaryKey('courses', $role, $email);

        return Cache::remember($key, 60, function () use ($role, $email) {
            return array_map(function (object $row): array {
                $records = (int) $row->records_total;
                $pending = (int) $row->sessions_pending;
                $unresolved = (int) $row->unresolved_count;

                return [
                    'course_id' => (int) $row->course_id,
                    'sessions_total' => (int) $row->sessions_total,
                    'sessions_finished' => (int) $row->sessions_finished,
                    'sessions_reconciled' => (int) $row->sessions_reconciled,
                    'sessions_pending' => $pending,
                    'unresolved_count' => $unresolved,
                    'last_sync_at' => $row->last_sync_at,
                    'attendance_status' => $records === 0
                        ? 'no_records'
                        : (($pending > 0 || $unresolved > 0) ? 'attention' : 'up_to_date'),
                ];
            }, $this->repository->accessibleCourseSummaries($role, $email));
        });
    }

    public function listCourseSessionSummaries(int $courseId, string $role, string $email): array
    {
        $this->assertCourseAccess($courseId, $role, $email);
        $key = ApiCache::attendanceSummaryKey('course-'.$courseId, $role, $email);

        return Cache::remember($key, 60, function () use ($courseId) {
            $now = CarbonImmutable::now(self::TIMEZONE);
            $sessions = array_map(function (object $row) use ($now): array {
                $start = CarbonImmutable::parse($row->fecha.' '.$row->start_time, self::TIMEZONE);
                $end = CarbonImmutable::parse($row->fecha.' '.$row->end_time, self::TIMEZONE);
                if ($end->lte($start)) {
                    $end = $end->addDay();
                }

                $cancelled = in_array(strtolower((string) $row->estado_sesion), ['cancelada', 'cancelado'], true);
                $records = (int) $row->records_total;
                $pending = (int) $row->pending_count;
                $status = $cancelled
                    ? 'not_applicable'
                    : ($end->gt($now)
                        ? 'upcoming'
                        : ($records === 0 ? 'no_records' : ($pending > 0 ? 'pending' : 'reconciled')));

                return [
                    'session_id' => (int) $row->session_id,
                    'session_number' => (int) $row->session_number,
                    'date' => $row->fecha,
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'status' => $status,
                    'records_total' => $records,
                    'students_count' => (int) $row->students_count,
                    'present_count' => (int) $row->present_count,
                    'absent_count' => (int) $row->absent_count,
                    'pending_count' => $pending,
                    'teacher_status' => $row->teacher_status ?: 'pendiente',
                    'unresolved_count' => (int) $row->unresolved_count,
                    'last_sync_at' => $row->last_sync_at,
                ];
            }, $this->repository->courseSessionSummaries($courseId));

            return [
                'sessions' => $sessions,
                'summary' => [
                    'sessions_total' => count($sessions),
                    'sessions_finished' => count(array_filter($sessions, fn ($item) => in_array($item['status'], ['reconciled', 'pending', 'no_records'], true))),
                    'sessions_reconciled' => count(array_filter($sessions, fn ($item) => $item['status'] === 'reconciled')),
                    'sessions_pending' => count(array_filter($sessions, fn ($item) => in_array($item['status'], ['pending', 'no_records'], true))),
                    'unresolved_count' => array_sum(array_column($sessions, 'unresolved_count')),
                ],
            ];
        });
    }

    public function listSession(int $courseId, int $sessionId, string $role, string $email): array
    {
        $this->assertCourseAccess($courseId, $role, $email);
        $session = $this->repository->sessionContext($sessionId);

        if (!$session || (int) $session->curso_edicion_id !== $courseId) {
            throw new \InvalidArgumentException('session_not_found');
        }

        $enabled = (bool) config('services.attendance.enabled', false);
        $meeting = $enabled ? $this->meetingService->resolveMeeting($session) : null;

        if ($enabled) {
            $this->repository->ensureRoster($session, $meeting ? (int) $meeting->id : null);
        }

        $items = $enabled ? $this->repository->sessionAttendance($sessionId) : [];
        $unresolved = $enabled ? $this->repository->unresolvedParticipantsBySession($sessionId) : [];
        $sync = $this->repository->attendanceSync($meeting ? (int) $meeting->id : null);
        $end = $this->sessionEnd($session);
        $normalizedRole = strtolower($role);
        $zoomSyncEnabled = (bool) config('services.attendance.zoom_sync_enabled', false);

        return [
            'enabled' => $enabled,
            'meeting_scheduled' => $meeting !== null,
            'sync_enabled' => $zoomSyncEnabled,
            'status' => !$enabled
                ? 'disabled'
                : (!$meeting ? 'no_meeting' : (($sync->estado ?? null) === 'completado' ? 'reconciled' : 'pending')),
            'can_sync' => $meeting !== null
                && $zoomSyncEnabled
                && in_array($normalizedRole, ['admin', 'administrador'], true)
                && $end->lte(CarbonImmutable::now(self::TIMEZONE)),
            'sync' => $sync ? [
                'state' => $sync->estado,
                'attempts' => (int) $sync->intentos,
                'synced_at' => $sync->sincronizado_at,
                'next_attempt_at' => $sync->proximo_intento_at,
            ] : null,
            'session' => [
                'id' => (int) $session->id,
                'number' => (int) ($session->nro_sesion ?? $session->numero ?? 0),
                'date' => $session->fecha ?? null,
                'start_time' => $session->hora_inicio_prog ?? null,
                'end_time' => $session->hora_fin_prog ?? null,
            ],
            'items' => $items,
            'unresolved' => $unresolved,
        ];
    }

    public function listStudent(int $courseId, string $email): array
    {
        if (!$this->repository->enrolledStudent($courseId, $email)) {
            throw new \DomainException('student_not_enrolled');
        }
        $this->ensureCourseRoster($courseId);
        return $this->repository->studentCourseAttendance($courseId, $email);
    }

    public function override(
        int $attendanceId,
        string $status,
        string $reason,
        string $role,
        string $email,
        ?int $expectedSessionId = null
    ): object
    {
        $attendance = $this->repository->findAttendance($attendanceId);
        if (!$attendance || ($expectedSessionId !== null && (int) $attendance->sesion_id !== $expectedSessionId)) {
            throw new \InvalidArgumentException('attendance_not_found');
        }
        $this->assertCourseAccess((int) $attendance->curso_edicion_id, $role, $email);
        if ($attendance->tipo_participante === 'docente'
            && !in_array(strtolower($role), ['admin', 'administrador'], true)) {
            throw new \DomainException('teacher_attendance_admin_only');
        }
        $allowed = $attendance->tipo_participante === 'alumno'
            ? ['asistio', 'falta', 'justificada', 'no_aplica']
            : ['presente', 'tardanza', 'falta', 'justificada', 'no_aplica'];
        if (!in_array($status, $allowed, true) || mb_strlen(trim($reason)) < 3) {
            throw new \InvalidArgumentException('invalid_override');
        }
        $this->repository->manualOverride($attendanceId, $status, trim($reason), $email);
        ApiCache::bumpAttendanceSummary();
        $updated = $this->repository->findAttendance($attendanceId);
        if ($updated && $updated->tipo_participante === 'docente') {
            $this->repository->syncTeacherSummary((int) $updated->sesion_id, $updated);
        }
        return $updated;
    }

    public function identify(int $sessionId, int $eventId, int $attendanceId, string $role, string $email): object
    {
        $attendance = $this->repository->findAttendance($attendanceId);
        if (!$attendance || (int) $attendance->sesion_id !== $sessionId) {
            throw new \InvalidArgumentException('attendance_not_found');
        }
        $this->assertCourseAccess((int) $attendance->curso_edicion_id, $role, $email);
        if (!$this->repository->assignUnresolvedEvent($eventId, $attendanceId, $email)) {
            throw new \InvalidArgumentException('unresolved_participant_not_found');
        }
        ApiCache::bumpAttendanceSummary();
        return $this->repository->findAttendance($attendanceId);
    }

    public function syncSession(int $sessionId, ?string $role = null, string $email = ''): array
    {
        $session = $this->repository->sessionContext($sessionId);
        if (!$session) {
            throw new \InvalidArgumentException('session_not_found');
        }
        if ($role !== null) {
            $this->assertCourseAccess((int) $session->curso_edicion_id, $role, $email);
            if (!in_array(strtolower($role), ['admin', 'administrador'], true)) {
                throw new \DomainException('attendance_sync_admin_only');
            }
            if ($this->sessionEnd($session)->gt(CarbonImmutable::now(self::TIMEZONE))) {
                throw new \DomainException('session_not_ended');
            }
        }
        $meeting = $this->meetingService->resolveMeeting($session);
        if (!$meeting) {
            throw new \DomainException('meeting_not_found');
        }
        $this->repository->ensureRoster($session, (int) $meeting->id);
        $externalId = (string) ($meeting->id_reunion ?: $meeting->zoom_meeting_id);
        try {
            if (config('services.attendance.zoom_sync_enabled', false) && $externalId !== '') {
                foreach ($this->zoom->participants($externalId) as $index => $participant) {
                    $this->recordReportParticipant($session, $meeting, $participant, $index);
                }
            }
        } catch (\Throwable $e) {
            $this->repository->markSync($meeting, false, $this->safeErrorCode($e));
            throw $e;
        }
        $start = CarbonImmutable::parse($session->fecha.' '.$session->hora_inicio_prog, self::TIMEZONE);
        $end = CarbonImmutable::parse($session->fecha.' '.$session->hora_fin_prog, self::TIMEZONE);
        if ($end->lte($start)) {
            $end = $end->addDay();
        }
        foreach ($this->repository->sessionAttendance($sessionId) as $attendance) {
            $result = $this->calculator->calculate(
                $attendance->tipo_participante,
                $start,
                $end,
                $this->repository->attendanceIntervals((int) $attendance->id),
                $session->estado_sesion === 'cancelada'
            );
            $this->repository->updateCalculated((int) $attendance->id, $result);
        }
        foreach ($this->repository->sessionAttendance($sessionId) as $attendance) {
            if ($attendance->tipo_participante === 'docente') {
                $this->repository->syncTeacherSummary($sessionId, $attendance);
            }
        }
        $this->repository->markSync($meeting, true);
        ApiCache::bumpAttendanceSummary();
        return ['items' => $this->repository->sessionAttendance($sessionId)];
    }

    public function syncDueSessions(): array
    {
        $result = ['processed' => 0, 'failed' => 0];
        if (!config('services.attendance.enabled', false)
            || !config('services.attendance.zoom_sync_enabled', false)) {
            return $result;
        }
        foreach ($this->repository->dueSessions() as $row) {
            try {
                $this->syncSession((int) $row->id);
                $result['processed']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                Log::warning('Attendance reconciliation failed.', [
                    'session_id' => (int) $row->id,
                    'error_code' => $this->safeErrorCode($e),
                ]);
            }
        }
        return $result;
    }

    private function recordReportParticipant(object $session, object $meeting, array $participant, int $index): void
    {
        $email = strtolower(trim((string) ($participant['user_email'] ?? $participant['email'] ?? '')));
        $participantId = trim((string) ($participant['id'] ?? $participant['user_id'] ?? ''));
        $attendance = $email ? $this->repository->resolveAttendanceByEmail((int) $session->id, $email) : null;
        $attendance ??= $participantId ? $this->repository->resolveAttendanceByParticipantId((int) $session->id, $participantId) : null;
        if ($attendance && $participantId) {
            $this->repository->rememberParticipantIdentity($attendance, $participantId);
        }
        $leaveAt = (string) ($participant['leave_time'] ?? '');
        $joinAt = (string) ($participant['join_time'] ?? '');
        $duration = (int) ($participant['duration'] ?? 0);
        $occurredAt = $leaveAt ?: ($joinAt ?: CarbonImmutable::now(self::TIMEZONE)->toIso8601String());
        $this->repository->insertZoomEvent([
            'attendance_id' => $attendance->id ?? null,
            'meeting_id' => (int) $meeting->id,
            'external_event_id' => 'report:'.hash('sha256', implode('|', [
                $meeting->id, $participantId, $joinAt, $leaveAt, $duration, $index,
            ])),
            'source' => 'zoom_report', 'type' => 'snapshot', 'occurred_at' => $occurredAt,
            'participant_id' => $participantId ?: null, 'email' => $email ?: null,
            'name' => $participant['name'] ?? null, 'duration_seconds' => $duration,
        ]);
    }

    private function assertCourseAccess(int $courseId, string $role, string $email): void
    {
        if (in_array(strtolower($role), ['admin', 'administrador'], true)) {
            return;
        }
        if (!$this->repository->teacherAssignedToCourse($courseId, $email)) {
            throw new \DomainException('course_forbidden');
        }
    }

    private function ensureCourseRoster(int $courseId): void
    {
        foreach ($this->repository->courseSessions($courseId) as $session) {
            $meeting = $this->meetingService->resolveMeeting($session);
            $this->repository->ensureRoster($session, $meeting ? (int) $meeting->id : null);
        }
    }

    private function sessionEnd(object $session): CarbonImmutable
    {
        $start = CarbonImmutable::parse(
            $session->fecha.' '.$session->hora_inicio_prog,
            self::TIMEZONE
        );
        $end = CarbonImmutable::parse(
            $session->fecha.' '.$session->hora_fin_prog,
            self::TIMEZONE
        );

        return $end->lte($start) ? $end->addDay() : $end;
    }

    private function eventDate(array $payload, array $participant): string
    {
        $value = $participant['join_time'] ?? $participant['leave_time'] ?? null;
        if ($value) {
            return CarbonImmutable::parse($value)->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s');
        }
        $milliseconds = (int) ($payload['event_ts'] ?? 0);
        return $milliseconds > 0
            ? CarbonImmutable::createFromTimestampMs($milliseconds, self::TIMEZONE)->format('Y-m-d H:i:s')
            : CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d H:i:s');
    }

    private function assertEnabled(): void
    {
        if (!config('services.attendance.enabled', false)) {
            throw new \RuntimeException('attendance_disabled');
        }
    }

    private function safeErrorCode(\Throwable $e): string
    {
        return strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $e->getMessage()) ?: 'sync_error');
    }
}
