<?php

namespace App\Repositories;

use App\Helpers\DbSafe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AttendanceRepository
{
    private const CONNECTION = 'mysql_cursos';

    public function sessionContext(int $sessionId): ?object
    {
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT s.*, s.nro_sesion AS numero, s.hora_inicio_prog AS hora_inicio,
                   ce.curso AS curso_nombre, ce.edicion AS curso_edicion,
                   ce.docente AS curso_docente,
                   COALESCE(zh.email, ce.cta_zoom) AS zoom_host_email
            FROM curso_edicion_sesiones s
            INNER JOIN curso_edicion ce ON ce.id = s.curso_edicion_id
            LEFT JOIN zoom_hosts zh ON zh.id = s.zoom_host_id
            WHERE s.id = ?
            LIMIT 1
        SQL, [$sessionId]);

        return $rows[0] ?? null;
    }

    public function sessionForMeeting(object $meeting): ?object
    {
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT s.*, ce.curso AS curso_nombre, ce.edicion AS curso_edicion,
                   ce.docente AS curso_docente,
                   COALESCE(zh.email, ce.cta_zoom) AS zoom_host_email
            FROM curso_edicion_sesiones s
            INNER JOIN curso_edicion ce ON ce.id = s.curso_edicion_id
            LEFT JOIN zoom_hosts zh ON zh.id = s.zoom_host_id
            WHERE ce.edicion = ? AND s.nro_sesion = ?
              AND ABS(TIMESTAMPDIFF(MINUTE, CONCAT(s.fecha, ' ', s.hora_inicio_prog), ?)) <= 5
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, CONCAT(s.fecha, ' ', s.hora_inicio_prog), ?))
            LIMIT 2
        SQL, [$meeting->edicion, (int) $meeting->sesion, $meeting->date, $meeting->date]);

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function enrolledStudent(int $courseId, string $email): ?object
    {
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT LOWER(TRIM(COALESCE(NULLIF(fi.CORREO_PERSONAL, ''), fi.correo_corporativo))) AS correo,
                   LOWER(TRIM(fi.correo_corporativo)) AS correo_corporativo,
                   CONCAT_WS(' ', fi.NOMBRES, fi.APELLIDOS) AS nombre
            FROM Ficha_inscripcion fi
            INNER JOIN curso_edicion ce
              ON ce.id = ?
             AND ((fi.curso_edicion_id = ce.id)
               OR (fi.CURSO COLLATE utf8mb4_unicode_ci = ce.curso COLLATE utf8mb4_unicode_ci
              AND fi.grupo COLLATE utf8mb4_unicode_ci = ce.edicion COLLATE utf8mb4_unicode_ci))
           WHERE LOWER(TRIM(fi.CORREO_PERSONAL)) = LOWER(TRIM(?))
              OR LOWER(TRIM(fi.correo_corporativo)) = LOWER(TRIM(?))
           ORDER BY fi.id DESC
           LIMIT 1
        SQL, [$courseId, $email, $email]);

        return $rows[0] ?? null;
    }

    public function assignedTeacher(int $sessionId, string $email): ?object
    {
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT c.id_colaborador AS colaborador_id,
                   LOWER(TRIM(COALESCE(c.correo_corporativo, c.correo_personal, u.email))) AS correo,
                   CONCAT_WS(' ', c.nombres, c.apellidos) AS nombre
            FROM curso_edicion_sesiones s
            INNER JOIN colaborador c ON c.id_colaborador = s.docente_id
            LEFT JOIN usuario u ON u.colaborador_id = c.id_colaborador AND u.activo = 1
            WHERE s.id = ?
              AND (LOWER(TRIM(c.correo_corporativo)) = LOWER(TRIM(?))
                OR LOWER(TRIM(c.correo_personal)) = LOWER(TRIM(?))
                OR LOWER(TRIM(u.email)) = LOWER(TRIM(?)))
            LIMIT 1
        SQL, [$sessionId, $email, $email, $email]);

        return $rows[0] ?? null;
    }

    public function teacherAssignedToCourse(int $courseId, string $email): bool
    {
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT 1
            FROM curso_edicion_sesiones s
            INNER JOIN colaborador c ON c.id_colaborador = s.docente_id
            LEFT JOIN usuario u ON u.colaborador_id = c.id_colaborador AND u.activo = 1
            WHERE s.curso_edicion_id = ?
              AND (LOWER(TRIM(c.correo_corporativo)) = LOWER(TRIM(?))
                OR LOWER(TRIM(c.correo_personal)) = LOWER(TRIM(?))
                OR LOWER(TRIM(u.email)) = LOWER(TRIM(?)))
            LIMIT 1
        SQL, [$courseId, $email, $email, $email]);

        return isset($rows[0]);
    }

    public function courseSessions(int $courseId): array
    {
        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT s.*, ce.curso AS curso_nombre, ce.edicion AS curso_edicion,
                   ce.docente AS curso_docente,
                   COALESCE(zh.email, ce.cta_zoom) AS zoom_host_email
            FROM curso_edicion_sesiones s
            INNER JOIN curso_edicion ce ON ce.id = s.curso_edicion_id
            LEFT JOIN zoom_hosts zh ON zh.id = s.zoom_host_id
            WHERE s.curso_edicion_id = ?
            ORDER BY s.nro_sesion, s.fecha, s.hora_inicio_prog
        SQL, [$courseId]);
    }

    public function accessibleCourseSummaries(string $role, string $email): array
    {
        $isAdmin = in_array(strtolower(trim($role)), ['admin', 'administrador'], true) ? 1 : 0;

        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT ce.id AS course_id,
                   COUNT(DISTINCT s.id) AS sessions_total,
                   SUM(CASE WHEN s.id IS NOT NULL
                                  AND COALESCE(s.estado_sesion, '') NOT IN ('cancelada', 'cancelado')
                                  AND DATE_ADD(TIMESTAMP(s.fecha, s.hora_fin_prog),
                                      INTERVAL IF(s.hora_fin_prog <= s.hora_inicio_prog, 1, 0) DAY) <= NOW()
                            THEN 1 ELSE 0 END) AS sessions_finished,
                   SUM(CASE WHEN s.id IS NOT NULL
                                  AND COALESCE(s.estado_sesion, '') NOT IN ('cancelada', 'cancelado')
                                  AND DATE_ADD(TIMESTAMP(s.fecha, s.hora_fin_prog),
                                      INTERVAL IF(s.hora_fin_prog <= s.hora_inicio_prog, 1, 0) DAY) <= NOW()
                                  AND COALESCE(ar.records_count, 0) > 0
                                  AND COALESCE(ar.pending_count, 0) = 0
                            THEN 1 ELSE 0 END) AS sessions_reconciled,
                   SUM(CASE WHEN s.id IS NOT NULL
                                  AND COALESCE(s.estado_sesion, '') NOT IN ('cancelada', 'cancelado')
                                  AND DATE_ADD(TIMESTAMP(s.fecha, s.hora_fin_prog),
                                      INTERVAL IF(s.hora_fin_prog <= s.hora_inicio_prog, 1, 0) DAY) <= NOW()
                                  AND (COALESCE(ar.records_count, 0) = 0 OR COALESCE(ar.pending_count, 0) > 0)
                            THEN 1 ELSE 0 END) AS sessions_pending,
                   COALESCE(SUM(ar.records_count), 0) AS records_total,
                   COALESCE(MAX(ur.unresolved_count), 0) AS unresolved_count,
                   MAX(ar.last_finalized_at) AS last_sync_at
            FROM curso_edicion ce
            LEFT JOIN curso_edicion_sesiones s ON s.curso_edicion_id = ce.id
            LEFT JOIN (
                SELECT curso_edicion_sesion_id,
                       COUNT(*) AS records_count,
                       SUM(CASE WHEN COALESCE(estado_manual, estado_automatico, 'pendiente') = 'pendiente' THEN 1 ELSE 0 END) AS pending_count,
                       MAX(finalizado_at) AS last_finalized_at
                FROM curso_edicion_sesion_asistencias
                GROUP BY curso_edicion_sesion_id
            ) ar ON ar.curso_edicion_sesion_id = s.id
            LEFT JOIN (
                SELECT ce2.id AS course_id, COUNT(DISTINCT e.id) AS unresolved_count
                FROM curso_edicion_sesion_asistencia_eventos e
                INNER JOIN meetings m ON m.id = e.meeting_id
                INNER JOIN curso_edicion ce2 ON ce2.edicion COLLATE utf8mb4_unicode_ci = m.edicion COLLATE utf8mb4_unicode_ci
                INNER JOIN curso_edicion_sesiones s2
                    ON s2.curso_edicion_id = ce2.id AND s2.nro_sesion = m.sesion
                WHERE e.asistencia_id IS NULL AND e.fuente IN ('zoom_webhook', 'zoom_report')
                GROUP BY ce2.id
            ) ur ON ur.course_id = ce.id
            WHERE (? = 1 OR EXISTS (
                SELECT 1
                FROM curso_edicion_sesiones sx
                INNER JOIN colaborador c ON c.id_colaborador = sx.docente_id
                LEFT JOIN usuario u ON u.colaborador_id = c.id_colaborador AND u.activo = 1
                WHERE sx.curso_edicion_id = ce.id
                  AND (LOWER(TRIM(c.correo_corporativo COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(?))
                    OR LOWER(TRIM(c.correo_personal COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(?))
                    OR LOWER(TRIM(u.email COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(?)))
            ))
            GROUP BY ce.id
        SQL, [$isAdmin, $email, $email, $email]);
    }

    public function courseSessionSummaries(int $courseId): array
    {
        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT s.id AS session_id, s.nro_sesion AS session_number, s.fecha,
                   s.hora_inicio_prog AS start_time, s.hora_fin_prog AS end_time,
                   s.estado_sesion,
                   COALESCE(ar.records_count, 0) AS records_total,
                   COALESCE(ar.students_count, 0) AS students_count,
                   COALESCE(ar.present_count, 0) AS present_count,
                   COALESCE(ar.absent_count, 0) AS absent_count,
                   COALESCE(ar.pending_count, 0) AS pending_count,
                   ar.teacher_status,
                   COALESCE(ur.unresolved_count, 0) AS unresolved_count,
                   ar.last_finalized_at AS last_sync_at
            FROM curso_edicion_sesiones s
            LEFT JOIN (
                SELECT curso_edicion_sesion_id,
                       COUNT(*) AS records_count,
                       SUM(CASE WHEN tipo_participante = 'alumno' THEN 1 ELSE 0 END) AS students_count,
                       SUM(CASE WHEN tipo_participante = 'alumno'
                                  AND COALESCE(estado_manual, estado_automatico) IN ('asistio', 'presente') THEN 1 ELSE 0 END) AS present_count,
                       SUM(CASE WHEN tipo_participante = 'alumno'
                                  AND COALESCE(estado_manual, estado_automatico) = 'falta' THEN 1 ELSE 0 END) AS absent_count,
                       SUM(CASE WHEN COALESCE(estado_manual, estado_automatico, 'pendiente') = 'pendiente' THEN 1 ELSE 0 END) AS pending_count,
                       MAX(CASE WHEN tipo_participante = 'docente'
                                THEN COALESCE(estado_manual, estado_automatico, 'pendiente') END) AS teacher_status,
                       MAX(finalizado_at) AS last_finalized_at
                FROM curso_edicion_sesion_asistencias
                GROUP BY curso_edicion_sesion_id
            ) ar ON ar.curso_edicion_sesion_id = s.id
            LEFT JOIN (
                SELECT s2.id AS session_id, COUNT(DISTINCT e.id) AS unresolved_count
                FROM curso_edicion_sesion_asistencia_eventos e
                INNER JOIN meetings m ON m.id = e.meeting_id
                INNER JOIN curso_edicion ce2 ON ce2.edicion COLLATE utf8mb4_unicode_ci = m.edicion COLLATE utf8mb4_unicode_ci
                INNER JOIN curso_edicion_sesiones s2
                    ON s2.curso_edicion_id = ce2.id AND s2.nro_sesion = m.sesion
                WHERE e.asistencia_id IS NULL AND e.fuente IN ('zoom_webhook', 'zoom_report')
                GROUP BY s2.id
            ) ur ON ur.session_id = s.id
            WHERE s.curso_edicion_id = ?
            ORDER BY s.nro_sesion, s.fecha, s.hora_inicio_prog
        SQL, [$courseId]);
    }

    public function ensureRoster(object $session, ?int $meetingId): void
    {
        $connection = DB::connection(self::CONNECTION);
        $students = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT LOWER(TRIM(COALESCE(NULLIF(fi.CORREO_PERSONAL, ''), fi.correo_corporativo))) AS correo,
                   CONCAT_WS(' ', fi.NOMBRES, fi.APELLIDOS) AS nombre
            FROM Ficha_inscripcion fi
            INNER JOIN curso_edicion ce ON ce.id = ?
            WHERE fi.curso_edicion_id = ce.id
               OR (fi.CURSO COLLATE utf8mb4_unicode_ci = ce.curso COLLATE utf8mb4_unicode_ci
               AND fi.grupo COLLATE utf8mb4_unicode_ci = ce.edicion COLLATE utf8mb4_unicode_ci)
            GROUP BY LOWER(TRIM(COALESCE(NULLIF(fi.CORREO_PERSONAL, ''), fi.correo_corporativo))), fi.NOMBRES, fi.APELLIDOS
        SQL, [(int) $session->curso_edicion_id]);

        foreach ($students as $student) {
            if (!$student->correo) {
                continue;
            }
            $this->upsertPerson(
                (int) $session->id,
                $meetingId,
                'alumno',
                'alumno:'.$student->correo,
                $student->correo,
                null,
                $student->nombre
            );
        }

        if (!empty($session->docente_id)) {
            $teachers = DbSafe::select(self::CONNECTION, <<<'SQL'
                SELECT c.id_colaborador, CONCAT_WS(' ', c.nombres, c.apellidos) AS nombre,
                       LOWER(TRIM(COALESCE(c.correo_corporativo, c.correo_personal))) AS correo
                FROM colaborador c WHERE c.id_colaborador = ? LIMIT 1
            SQL, [(int) $session->docente_id]);
            if ($teacher = ($teachers[0] ?? null)) {
                $this->upsertPerson(
                    (int) $session->id,
                    $meetingId,
                    'docente',
                    'docente:'.$teacher->id_colaborador,
                    null,
                    (int) $teacher->id_colaborador,
                    $teacher->nombre
                );
            }
        }
    }

    public function upsertPerson(
        int $sessionId,
        ?int $meetingId,
        string $type,
        string $identityKey,
        ?string $studentEmail,
        ?int $collaboratorId,
        ?string $name
    ): object {
        DB::connection(self::CONNECTION)->statement(<<<'SQL'
            INSERT INTO curso_edicion_sesion_asistencias
                (curso_edicion_sesion_id, meeting_id, tipo_participante, identity_key,
                 alumno_correo, colaborador_id, nombre_mostrado, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE meeting_id = COALESCE(VALUES(meeting_id), meeting_id),
                nombre_mostrado = COALESCE(VALUES(nombre_mostrado), nombre_mostrado), updated_at = NOW()
        SQL, [$sessionId, $meetingId, $type, $identityKey, $studentEmail, $collaboratorId, $name]);

        return $this->findByIdentity($sessionId, $identityKey);
    }

    public function findByIdentity(int $sessionId, string $identityKey): ?object
    {
        $rows = DbSafe::select(self::CONNECTION,
            'SELECT * FROM curso_edicion_sesion_asistencias WHERE curso_edicion_sesion_id = ? AND identity_key = ? LIMIT 1',
            [$sessionId, $identityKey]
        );
        return $rows[0] ?? null;
    }

    public function resolveAttendanceByEmail(int $sessionId, string $email): ?object
    {
        $email = strtolower(trim($email));
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT a.*
            FROM curso_edicion_sesion_asistencias a
            LEFT JOIN Ficha_inscripcion fi
              ON a.tipo_participante = 'alumno'
             AND LOWER(TRIM(fi.CORREO_PERSONAL)) = LOWER(TRIM(a.alumno_correo))
            LEFT JOIN colaborador c ON c.id_colaborador = a.colaborador_id
            LEFT JOIN usuario u ON u.colaborador_id = c.id_colaborador AND u.activo = 1
            WHERE a.curso_edicion_sesion_id = ?
              AND (LOWER(TRIM(a.alumno_correo)) = ?
                OR LOWER(TRIM(fi.correo_corporativo)) = ?
                OR LOWER(TRIM(c.correo_personal)) = ?
                OR LOWER(TRIM(c.correo_corporativo)) = ?
                OR LOWER(TRIM(u.email)) = ?)
            LIMIT 1
        SQL, [$sessionId, $email, $email, $email, $email, $email]);
        return $rows[0] ?? null;
    }

    public function resolveAttendanceByParticipantId(int $sessionId, string $participantId): ?object
    {
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT a.* FROM zoom_participant_identities z
            INNER JOIN curso_edicion_sesion_asistencias a ON a.identity_key = z.identity_key
            WHERE z.zoom_participant_id = ? AND a.curso_edicion_sesion_id = ? LIMIT 1
        SQL, [$participantId, $sessionId]);
        return $rows[0] ?? null;
    }

    public function rememberParticipantIdentity(object $attendance, string $participantId, string $source = 'correo_exacto'): void
    {
        if ($participantId === '') {
            return;
        }
        DB::connection(self::CONNECTION)->statement(<<<'SQL'
            INSERT INTO zoom_participant_identities
                (zoom_participant_id, identity_key, tipo_participante, correo, fuente, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE identity_key = VALUES(identity_key), tipo_participante = VALUES(tipo_participante),
                correo = VALUES(correo), fuente = VALUES(fuente), updated_at = NOW()
        SQL, [
            $participantId, $attendance->identity_key, $attendance->tipo_participante,
            $attendance->alumno_correo, $source,
        ]);
    }

    public function findAttendance(int $attendanceId): ?object
    {
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT a.*, s.curso_edicion_id, s.id AS sesion_id
            FROM curso_edicion_sesion_asistencias a
            INNER JOIN curso_edicion_sesiones s ON s.id = a.curso_edicion_sesion_id
            WHERE a.id = ? LIMIT 1
        SQL, [$attendanceId]);
        return $rows[0] ?? null;
    }

    public function unresolvedParticipants(int $courseId): array
    {
        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT e.id, e.meeting_id, e.zoom_participant_id, e.participante_correo,
                   e.participante_nombre, e.ocurrido_at, e.tipo_evento, s.id AS sesion_id, s.nro_sesion
            FROM curso_edicion_sesion_asistencia_eventos e
            INNER JOIN meetings m ON m.id = e.meeting_id
            INNER JOIN curso_edicion ce ON ce.edicion COLLATE utf8mb4_unicode_ci = m.edicion COLLATE utf8mb4_unicode_ci
            INNER JOIN curso_edicion_sesiones s ON s.curso_edicion_id = ce.id AND s.nro_sesion = m.sesion
            WHERE ce.id = ? AND e.asistencia_id IS NULL AND e.fuente IN ('zoom_webhook','zoom_report')
            ORDER BY e.ocurrido_at DESC
        SQL, [$courseId]);
    }

    public function unresolvedParticipantsBySession(int $sessionId): array
    {
        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT e.id, e.meeting_id, e.zoom_participant_id, e.participante_correo,
                   e.participante_nombre, e.ocurrido_at, e.tipo_evento,
                   s.id AS sesion_id, s.nro_sesion
            FROM curso_edicion_sesion_asistencia_eventos e
            INNER JOIN meetings m ON m.id = e.meeting_id
            INNER JOIN curso_edicion ce ON ce.edicion COLLATE utf8mb4_unicode_ci = m.edicion COLLATE utf8mb4_unicode_ci
            INNER JOIN curso_edicion_sesiones s
              ON s.curso_edicion_id = ce.id AND s.nro_sesion = m.sesion
            WHERE s.id = ? AND e.asistencia_id IS NULL
              AND e.fuente IN ('zoom_webhook','zoom_report')
            ORDER BY e.ocurrido_at DESC
        SQL, [$sessionId]);
    }

    public function attendanceSync(?int $meetingId): ?object
    {
        if (!$meetingId) {
            return null;
        }

        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT estado, intentos, ultimo_error_codigo, proximo_intento_at, sincronizado_at
            FROM meeting_attendance_syncs
            WHERE meeting_id = ?
            LIMIT 1
        SQL, [$meetingId]);

        return $rows[0] ?? null;
    }

    public function unresolvedEvent(int $eventId): ?object
    {
        $rows = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT e.*, s.id AS sesion_id, s.curso_edicion_id
            FROM curso_edicion_sesion_asistencia_eventos e
            INNER JOIN meetings m ON m.id = e.meeting_id
            INNER JOIN curso_edicion ce ON ce.edicion COLLATE utf8mb4_unicode_ci = m.edicion COLLATE utf8mb4_unicode_ci
            INNER JOIN curso_edicion_sesiones s
              ON s.curso_edicion_id = ce.id AND s.nro_sesion = m.sesion
            WHERE e.id = ? AND e.asistencia_id IS NULL
            LIMIT 1
        SQL, [$eventId]);

        return $rows[0] ?? null;
    }

    public function assignUnresolvedEvent(int $eventId, int $attendanceId, string $actorEmail): bool
    {
        $event = $this->unresolvedEvent($eventId);
        $attendance = $this->findAttendance($attendanceId);
        if (!$event || !$attendance || (int) $event->sesion_id !== (int) $attendance->sesion_id) {
            return false;
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($event, $attendance, $actorEmail) {
            $participantId = trim((string) ($event->zoom_participant_id ?? ''));
            $email = strtolower(trim((string) ($event->participante_correo ?? '')));

            $updated = DB::connection(self::CONNECTION)->update(<<<'SQL'
                UPDATE curso_edicion_sesion_asistencia_eventos
                SET asistencia_id = ?
                WHERE meeting_id = ? AND asistencia_id IS NULL
                  AND ((? <> '' AND zoom_participant_id = ?)
                    OR (? <> '' AND LOWER(TRIM(participante_correo)) = ?)
                    OR id = ?)
            SQL, [
                $attendance->id, $event->meeting_id,
                $participantId, $participantId,
                $email, $email,
                $event->id,
            ]);

            if ($participantId !== '') {
                DB::connection(self::CONNECTION)->statement(<<<'SQL'
                    INSERT INTO zoom_participant_identities
                        (zoom_participant_id, identity_key, tipo_participante, correo, fuente,
                         creado_por_correo, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'manual', ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE identity_key = VALUES(identity_key),
                        tipo_participante = VALUES(tipo_participante), correo = VALUES(correo),
                        fuente = 'manual', creado_por_correo = VALUES(creado_por_correo), updated_at = NOW()
                SQL, [
                    $participantId, $attendance->identity_key, $attendance->tipo_participante,
                    $attendance->alumno_correo, $actorEmail,
                ]);
            }

            $this->insertZoomEvent([
                'attendance_id' => (int) $attendance->id,
                'meeting_id' => (int) $event->meeting_id,
                'external_event_id' => 'manual-identify:'.$event->id.':'.$attendance->id,
                'source' => 'manual',
                'type' => 'override',
                'occurred_at' => CarbonImmutable::now('America/Lima')->format('Y-m-d H:i:s'),
                'participant_id' => $participantId ?: null,
                'email' => $email ?: null,
                'name' => $event->participante_nombre,
                'metadata' => ['actor_email' => $actorEmail, 'action' => 'identify_participant'],
            ]);

            return $updated > 0;
        });
    }

    public function recordClick(int $attendanceId, int $meetingId, string $occurredAt, string $eventId): void
    {
        DB::connection(self::CONNECTION)->transaction(function () use ($attendanceId, $meetingId, $occurredAt, $eventId) {
            DB::connection(self::CONNECTION)->statement(<<<'SQL'
                INSERT IGNORE INTO curso_edicion_sesion_asistencia_eventos
                    (asistencia_id, meeting_id, external_event_id, fuente, tipo_evento, ocurrido_at)
                VALUES (?, ?, ?, 'portal_click', 'click', ?)
            SQL, [$attendanceId, $meetingId, $eventId, $occurredAt]);
            DB::connection(self::CONNECTION)->statement(<<<'SQL'
                UPDATE curso_edicion_sesion_asistencias
                SET primer_click_at = COALESCE(primer_click_at, ?), click_count = click_count + 1, updated_at = NOW()
                WHERE id = ?
            SQL, [$occurredAt, $attendanceId]);
        });
    }

    public function insertZoomEvent(array $event): bool
    {
        return DB::connection(self::CONNECTION)->affectingStatement(<<<'SQL'
            INSERT IGNORE INTO curso_edicion_sesion_asistencia_eventos
                (asistencia_id, meeting_id, external_event_id, fuente, tipo_evento, ocurrido_at,
                 zoom_participant_id, participante_correo, participante_nombre, duracion_segundos, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL, [
            $event['attendance_id'] ?? null, $event['meeting_id'] ?? null,
            $event['external_event_id'], $event['source'], $event['type'], $event['occurred_at'],
            $event['participant_id'] ?? null, $event['email'] ?? null, $event['name'] ?? null,
            $event['duration_seconds'] ?? null,
            isset($event['metadata']) ? json_encode($event['metadata'], JSON_UNESCAPED_UNICODE) : null,
        ]) > 0;
    }

    public function updateLiveEvent(int $attendanceId, string $type, string $occurredAt): void
    {
        $column = $type === 'join' ? 'primer_ingreso_at' : 'ultima_salida_at';
        $expression = $type === 'join'
            ? "COALESCE(primer_ingreso_at, ?)"
            : "GREATEST(COALESCE(ultima_salida_at, ?), ?)";
        $params = $type === 'join'
            ? [$occurredAt, $attendanceId]
            : [$occurredAt, $occurredAt, $attendanceId];
        DB::connection(self::CONNECTION)->update(
            "UPDATE curso_edicion_sesion_asistencias SET {$column} = {$expression}, zoom_verificado_at = NOW(), updated_at = NOW() WHERE id = ?",
            $params
        );
    }

    public function attendanceIntervals(int $attendanceId): array
    {
        $events = DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT tipo_evento, ocurrido_at, zoom_participant_id, duracion_segundos
            FROM curso_edicion_sesion_asistencia_eventos
            WHERE asistencia_id = ? AND tipo_evento IN ('join','leave','snapshot')
            ORDER BY ocurrido_at, id
        SQL, [$attendanceId]);

        $intervals = [];
        $open = [];
        foreach ($events as $event) {
            if ($event->tipo_evento === 'snapshot' && $event->duracion_segundos) {
                $leave = new \DateTimeImmutable($event->ocurrido_at);
                $intervals[] = [
                    'join_at' => $leave->modify('-'.(int) $event->duracion_segundos.' seconds')->format('Y-m-d H:i:s'),
                    'leave_at' => $leave->format('Y-m-d H:i:s'),
                ];
                continue;
            }
            $key = $event->zoom_participant_id ?: 'unknown';
            if ($event->tipo_evento === 'join') {
                $open[$key][] = $event->ocurrido_at;
            } elseif (!empty($open[$key])) {
                $join = array_shift($open[$key]);
                $intervals[] = ['join_at' => $join, 'leave_at' => $event->ocurrido_at];
            }
        }
        foreach ($open as $joins) {
            foreach ($joins as $join) {
                $intervals[] = ['join_at' => $join, 'leave_at' => null];
            }
        }
        return $intervals;
    }

    public function sessionAttendance(int $sessionId): array
    {
        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT a.*, COALESCE(a.estado_manual, a.estado_automatico) AS estado,
                   CASE WHEN a.segundos_asistencia > 0 THEN ROUND(a.segundos_asistencia / 60, 1) ELSE 0 END AS minutos_asistencia
            FROM curso_edicion_sesion_asistencias a
            WHERE a.curso_edicion_sesion_id = ?
            ORDER BY FIELD(a.tipo_participante, 'docente', 'alumno'), a.nombre_mostrado
        SQL, [$sessionId]);
    }

    public function courseAttendance(int $courseId): array
    {
        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT a.*, s.nro_sesion, s.fecha, s.hora_inicio_prog, s.hora_fin_prog,
                   COALESCE(a.estado_manual, a.estado_automatico) AS estado,
                   ROUND(a.segundos_asistencia / 60, 1) AS minutos_asistencia
            FROM curso_edicion_sesion_asistencias a
            INNER JOIN curso_edicion_sesiones s ON s.id = a.curso_edicion_sesion_id
            WHERE s.curso_edicion_id = ?
            ORDER BY s.nro_sesion, FIELD(a.tipo_participante, 'docente', 'alumno'), a.nombre_mostrado
        SQL, [$courseId]);
    }

    public function studentCourseAttendance(int $courseId, string $email): array
    {
        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT DISTINCT a.*, s.nro_sesion, s.fecha, s.hora_inicio_prog, s.hora_fin_prog,
                   COALESCE(a.estado_manual, a.estado_automatico) AS estado
            FROM curso_edicion_sesion_asistencias a
            INNER JOIN curso_edicion_sesiones s ON s.id = a.curso_edicion_sesion_id
            LEFT JOIN Ficha_inscripcion fi
              ON a.tipo_participante = 'alumno'
             AND LOWER(TRIM(COALESCE(NULLIF(fi.CORREO_PERSONAL, ''), fi.correo_corporativo))) = LOWER(TRIM(a.alumno_correo))
            WHERE s.curso_edicion_id = ? AND a.tipo_participante = 'alumno'
              AND (LOWER(TRIM(a.alumno_correo)) = LOWER(TRIM(?))
                OR LOWER(TRIM(fi.CORREO_PERSONAL)) = LOWER(TRIM(?))
                OR LOWER(TRIM(fi.correo_corporativo)) = LOWER(TRIM(?)))
            ORDER BY s.nro_sesion
        SQL, [$courseId, $email, $email, $email]);
    }

    public function updateCalculated(int $attendanceId, array $result): void
    {
        DB::connection(self::CONNECTION)->update(<<<'SQL'
            UPDATE curso_edicion_sesion_asistencias
            SET estado_automatico = ?, primer_ingreso_at = ?, ultima_salida_at = ?,
                segundos_asistencia = ?, porcentaje_permanencia = ?, zoom_verificado_at = NOW(),
                finalizado_at = NOW(), updated_at = NOW()
            WHERE id = ?
        SQL, [
            $result['status'], $result['first_join_at'], $result['last_leave_at'],
            $result['attended_seconds'], $result['attendance_percentage'], $attendanceId,
        ]);
    }

    public function manualOverride(int $attendanceId, string $status, string $reason, string $email): bool
    {
        return DB::connection(self::CONNECTION)->transaction(function () use ($attendanceId, $status, $reason, $email) {
            $updated = DB::connection(self::CONNECTION)->update(<<<'SQL'
                UPDATE curso_edicion_sesion_asistencias
                SET estado_manual = ?, motivo_manual = ?, modificado_por_correo = ?,
                    modificado_at = NOW(), updated_at = NOW() WHERE id = ?
            SQL, [$status, $reason, $email, $attendanceId]);
            if ($updated < 1) {
                return false;
            }

            $attendance = $this->findAttendance($attendanceId);
            $this->insertZoomEvent([
                'attendance_id' => $attendanceId,
                'meeting_id' => $attendance->meeting_id ?? null,
                'external_event_id' => 'manual-override:'.$attendanceId.':'.hash('sha256', $status.'|'.$reason.'|'.microtime(true)),
                'source' => 'manual',
                'type' => 'override',
                'occurred_at' => CarbonImmutable::now('America/Lima')->format('Y-m-d H:i:s'),
                'metadata' => ['status' => $status, 'reason' => $reason, 'actor_email' => $email],
            ]);

            return true;
        });
    }

    public function syncTeacherSummary(int $sessionId, object $attendance): void
    {
        $status = $attendance->estado_manual ?: $attendance->estado_automatico;
        DB::connection(self::CONNECTION)->update(<<<'SQL'
            UPDATE curso_edicion_sesiones
            SET asistencia_docente = ?, docente_entrada_at = ?, docente_salida_at = ?, updated_at = NOW()
            WHERE id = ?
        SQL, [$status, $attendance->primer_ingreso_at, $attendance->ultima_salida_at, $sessionId]);
    }

    public function dueSessions(): array
    {
        return DbSafe::select(self::CONNECTION, <<<'SQL'
            SELECT s.id
            FROM curso_edicion_sesiones s
            LEFT JOIN meeting_attendance_syncs ms ON ms.meeting_id = (
                SELECT m.id FROM meetings m
                INNER JOIN curso_edicion ce ON ce.id = s.curso_edicion_id
                WHERE m.status = 'activo' AND m.edicion COLLATE utf8mb4_unicode_ci = ce.edicion COLLATE utf8mb4_unicode_ci AND m.sesion = s.nro_sesion
                ORDER BY ABS(TIMESTAMPDIFF(SECOND, m.date, CONCAT(s.fecha, ' ', s.hora_inicio_prog))) LIMIT 1
            )
            WHERE TIMESTAMP(s.fecha, s.hora_fin_prog) <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
              AND TIMESTAMP(s.fecha, s.hora_fin_prog) >= DATE_SUB(NOW(), INTERVAL 2 DAY)
              AND s.estado_sesion <> 'cancelada'
              AND (ms.id IS NULL OR (ms.estado = 'error' AND ms.proximo_intento_at <= NOW()))
            ORDER BY s.fecha, s.hora_fin_prog
            LIMIT 30
        SQL);
    }

    public function markSync(object $meeting, bool $success, ?string $errorCode = null): void
    {
        $delays = [15, 30, 60, 180];
        $existing = DbSafe::select(self::CONNECTION,
            'SELECT intentos FROM meeting_attendance_syncs WHERE meeting_id = ? LIMIT 1',
            [(int) $meeting->id]
        );
        $attempts = (int) ($existing[0]->intentos ?? 0) + 1;
        $delay = $delays[min($attempts - 1, count($delays) - 1)];
        DB::connection(self::CONNECTION)->statement(<<<'SQL'
            INSERT INTO meeting_attendance_syncs
                (meeting_id, estado, intentos, ultimo_error_codigo, proximo_intento_at, sincronizado_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE estado = VALUES(estado), intentos = VALUES(intentos),
                ultimo_error_codigo = VALUES(ultimo_error_codigo), proximo_intento_at = VALUES(proximo_intento_at),
                sincronizado_at = VALUES(sincronizado_at), updated_at = NOW()
        SQL, [
            (int) $meeting->id, $success ? 'completado' : 'error', $attempts,
            $success ? null : mb_substr((string) $errorCode, 0, 100),
            $success ? null : CarbonImmutable::now('America/Lima')->addMinutes($delay)->format('Y-m-d H:i:s'),
            $success ? CarbonImmutable::now('America/Lima')->format('Y-m-d H:i:s') : null,
        ]);
    }
}
