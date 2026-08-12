<?php

namespace App\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GenDocsSurveyRepository
{
    private const CONNECTION = 'mysql_cursos';
    private const SESSION_SLUG = 'evaluacion_sesion_v1';
    private const FINAL_SLUG = 'evaluacion_curso_final_v1';

    public function summariesForSessions(array $sessions, string $email): array
    {
        $sessions = array_values(array_filter($sessions, fn ($session) => isset($session->id)));
        if ($sessions === []) {
            return [];
        }

        $ids = array_map(fn ($session) => (int) $session->id, $sessions);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT el.id AS link_id, el.curso_edicion_sesion_id AS session_id,
                    el.formulario_id AS form_id, el.estado AS link_status,
                    f.slug, f.nombre, f.version, f.activo AS form_active,
                    EXISTS(
                        SELECT 1 FROM encuesta_respuestas er
                        WHERE er.curso_edicion_sesion_id = el.curso_edicion_sesion_id
                          AND er.formulario_id = el.formulario_id
                          AND LOWER(TRIM(er.email)) = ?
                    ) AS answered
             FROM encuesta_links el
             INNER JOIN encuesta_formularios f ON f.id = el.formulario_id
             WHERE el.curso_edicion_sesion_id IN ($placeholders)
             ORDER BY el.curso_edicion_sesion_id, f.slug, f.version",
            array_merge([self::normalizeEmail($email)], $ids)
        );

        $sessionMap = [];
        foreach ($sessions as $session) {
            $sessionMap[(int) $session->id] = $session;
        }

        $result = [];
        foreach ($rows as $row) {
            $session = $sessionMap[(int) $row->session_id] ?? null;
            if (!$session) {
                continue;
            }
            $result[(int) $row->session_id][] = $this->summary($row, $session);
        }

        return $result;
    }

    public function findContext(int $courseId, int $sessionId, int $linkId, string $email): ?array
    {
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT el.id AS link_id, el.formulario_id AS form_id, el.estado AS link_status,
                    f.slug, f.nombre, f.version, f.activo AS form_active,
                    s.id AS session_id, s.curso_edicion_id AS course_id, s.nro_sesion,
                    s.fecha, s.hora_inicio_prog, s.hora_fin_prog, s.estado_sesion,
                    s.docente_id AS session_teacher_id,
                    ce.curso, ce.edicion, ce.docente_id_colaborador, ce.docente2_id_colaborador
             FROM encuesta_links el
             INNER JOIN encuesta_formularios f ON f.id = el.formulario_id
             INNER JOIN curso_edicion_sesiones s ON s.id = el.curso_edicion_sesion_id
             INNER JOIN curso_edicion ce ON ce.id = s.curso_edicion_id
             WHERE el.id = ? AND s.id = ? AND s.curso_edicion_id = ?
             LIMIT 1",
            [$linkId, $sessionId, $courseId]
        );
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $row->answered = $this->hasAnswered($courseId, $sessionId, (int) $row->form_id, $email, (string) $row->slug);

        return [
            'row' => $row,
            'summary' => $this->summary($row, $row),
            'questions' => $this->questions((int) $row->form_id),
            'teachers' => $this->teachersForContext($row, (string) $row->slug === self::FINAL_SLUG),
        ];
    }

    public function questions(int $formId): array
    {
        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT id, codigo, etiqueta, tipo, orden, requerido, opciones_json
             FROM encuesta_preguntas
             WHERE formulario_id = ? AND activo = 1
             ORDER BY orden, id',
            [$formId]
        );

        return array_map(function ($row) {
            $options = $row->opciones_json ? json_decode($row->opciones_json, true) : [];
            return [
                'id' => (int) $row->id,
                'code' => (string) $row->codigo,
                'label' => (string) $row->etiqueta,
                'type' => (string) $row->tipo,
                'order' => (int) $row->orden,
                'required' => (bool) $row->requerido,
                'options' => array_values($options['options'] ?? []),
                'scale' => $row->tipo === 'scale' ? [
                    'min' => (int) ($options['min'] ?? 1),
                    'max' => (int) ($options['max'] ?? 5),
                ] : null,
                'contextual' => in_array($row->codigo, ['email', 'nro_sesion', 'tipo_encuesta', 'docente'], true),
                'scope' => $options['scope'] ?? $this->questionScope((string) $row->codigo),
            ];
        }, $rows);
    }

    public function studentEnrolled(int $courseId, string $email): bool
    {
        return DB::connection(self::CONNECTION)->selectOne(
            "SELECT 1
             FROM curso_edicion ce
             INNER JOIN Ficha_inscripcion fi
               ON fi.curso_edicion_id = ce.id
               OR (fi.curso COLLATE utf8mb4_general_ci = ce.curso COLLATE utf8mb4_general_ci
               AND fi.grupo COLLATE utf8mb4_general_ci = ce.edicion COLLATE utf8mb4_general_ci)
             WHERE ce.id = ?
               AND LOWER(TRIM(COALESCE(NULLIF(fi.CORREO_PERSONAL, ''), fi.correo_corporativo))) = ?
             LIMIT 1",
            [$courseId, self::normalizeEmail($email)]
        ) !== null;
    }

    public function hasAnswered(int $courseId, int $sessionId, int $formId, string $email, string $slug): bool
    {
        $where = $slug === self::FINAL_SLUG
            ? 'curso_edicion_id = ?'
            : 'curso_edicion_sesion_id = ?';
        $scopeId = $slug === self::FINAL_SLUG ? $courseId : $sessionId;

        return DB::connection(self::CONNECTION)->selectOne(
            "SELECT 1 FROM encuesta_respuestas
             WHERE $where AND formulario_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1",
            [$scopeId, $formId, self::normalizeEmail($email)]
        ) !== null;
    }

    public function connection()
    {
        return DB::connection(self::CONNECTION);
    }

    public function resultsForCourse(int $courseId, bool $includeIdentity): array
    {
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT ce.curso, er.id AS respuesta_id, er.curso_edicion_sesion_id AS sesion_id,
                    er.nro_sesion, er.formulario_id, er.docente_id,
                    f.slug, f.nombre AS formulario, f.version AS formulario_version, er.submission_uuid,
                    er.score_puntualidad AS Puntualidad,
                    er.score_dudas AS Entendimiento,
                    er.score_laboratorios AS Laboratorios,
                    er.score_satisfaccion AS Satisfaccion,
                    er.score_promedio AS Promedio,
                    er.comentario AS Observacion,
                    er.email, er.submitted_at,
                    TRIM(CONCAT(COALESCE(c.nombres, ''), ' ', COALESCE(c.apellidos, ''))) AS docente
             FROM encuesta_respuestas er
             INNER JOIN encuesta_formularios f ON f.id = er.formulario_id
             INNER JOIN curso_edicion ce ON ce.id = er.curso_edicion_id
             LEFT JOIN colaborador c ON c.id_colaborador = er.docente_id
             WHERE er.curso_edicion_id = ?
             ORDER BY er.nro_sesion, er.submitted_at, er.id",
            [$courseId]
        );

        $detailsByResponse = [];
        $responseIds = array_map(fn ($row) => (int) $row->respuesta_id, $rows);
        if ($responseIds !== []) {
            $placeholders = implode(',', array_fill(0, count($responseIds), '?'));
            $details = DB::connection(self::CONNECTION)->select(
                "SELECT erd.respuesta_id, ep.id AS pregunta_id, ep.codigo, ep.etiqueta, ep.tipo, ep.opciones_json,
                        erd.valor_texto, erd.valor_numero
                 FROM encuesta_respuesta_detalles erd
                 INNER JOIN encuesta_preguntas ep ON ep.id = erd.pregunta_id
                 WHERE erd.respuesta_id IN ($placeholders)
                 ORDER BY ep.orden, ep.id",
                $responseIds
            );
            foreach ($details as $detail) {
                if (in_array((string) $detail->codigo, ['email', 'docente', 'nro_sesion', 'tipo_encuesta'], true)) {
                    continue;
                }
                $options = $detail->opciones_json ? json_decode((string) $detail->opciones_json, true) : [];
                $detailsByResponse[(int) $detail->respuesta_id][] = [
                    'question_id' => (int) $detail->pregunta_id,
                    'code' => (string) $detail->codigo,
                    'label' => (string) $detail->etiqueta,
                    'type' => (string) $detail->tipo,
                    'scope' => $options['scope'] ?? $this->questionScope((string) $detail->codigo),
                    'value' => $detail->valor_numero !== null
                        ? (float) $detail->valor_numero
                        : (string) ($detail->valor_texto ?? ''),
                ];
            }
        }

        return array_map(function ($row, $index) use ($includeIdentity, $detailsByResponse) {
            $item = (array) $row;
            $item['answers'] = $detailsByResponse[(int) $row->respuesta_id] ?? [];
            $item['kind'] = (string) $row->slug === self::FINAL_SLUG ? 'final' : 'session';
            $item['participant_key'] = hash('sha256', self::normalizeEmail((string) ($row->email ?? '')));
            if (!$includeIdentity) {
                unset($item['email']);
                $item['respondent'] = 'Participante '.($index + 1);
            } else {
                $item['respondent'] = $item['email'] ?: 'Sin correo registrado';
            }
            return $item;
        }, $rows, array_keys($rows));
    }

    public function enrolledStudentsCount(int $courseId): int
    {
        $row = DB::connection(self::CONNECTION)->selectOne(
            "SELECT COUNT(DISTINCT LOWER(TRIM(COALESCE(NULLIF(fi.CORREO_PERSONAL, ''), fi.correo_corporativo)))) AS total
             FROM curso_edicion ce
             INNER JOIN Ficha_inscripcion fi
               ON fi.curso_edicion_id = ce.id
               OR (fi.curso COLLATE utf8mb4_general_ci = ce.curso COLLATE utf8mb4_general_ci
               AND fi.grupo COLLATE utf8mb4_general_ci = ce.edicion COLLATE utf8mb4_general_ci)
             WHERE ce.id = ?
               AND NULLIF(TRIM(COALESCE(NULLIF(fi.CORREO_PERSONAL, ''), fi.correo_corporativo)), '') IS NOT NULL",
            [$courseId]
        );

        return (int) ($row->total ?? 0);
    }

    public function collaboratorIdByEmail(string $email): ?int
    {
        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT colaborador_id FROM usuario WHERE LOWER(TRIM(email)) = ? LIMIT 1',
            [self::normalizeEmail($email)]
        );

        $id = (int) ($row->colaborador_id ?? 0);

        return $id > 0 ? $id : null;
    }

    private function teachersForContext(object $row, bool $final): array
    {
        $ids = [];
        foreach (['session_teacher_id', 'docente_id_colaborador', 'docente2_id_colaborador'] as $field) {
            $id = (int) ($row->{$field} ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($final) {
            $sessionTeachers = DB::connection(self::CONNECTION)->select(
                'SELECT DISTINCT docente_id FROM curso_edicion_sesiones
                 WHERE curso_edicion_id = ? AND docente_id IS NOT NULL AND docente_id > 0',
                [(int) $row->course_id]
            );
            foreach ($sessionTeachers as $teacher) {
                $ids[(int) $teacher->docente_id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $teachers = DB::connection(self::CONNECTION)->select(
            "SELECT id_colaborador AS id,
                    TRIM(CONCAT(COALESCE(nombres, ''), ' ', COALESCE(apellidos, ''))) AS name
             FROM colaborador WHERE id_colaborador IN ($placeholders)",
            array_keys($ids)
        );
        return array_map(fn ($teacher) => ['id' => (int) $teacher->id, 'name' => trim((string) $teacher->name)], $teachers);
    }

    private function summary(object $row, object $session): array
    {
        $availableAt = $this->availableAt($session);
        $closed = ($row->link_status ?? '') !== 'activo' || !(bool) ($row->form_active ?? true)
            || in_array(strtolower((string) ($session->estado_sesion ?? $session->estado ?? '')), ['cancelada', 'cancelado'], true);
        $available = !$closed && $availableAt !== null && Carbon::now('America/Lima')->greaterThanOrEqualTo($availableAt);
        $kind = (string) $row->slug === self::FINAL_SLUG ? 'final' : 'session';

        return [
            'link_id' => (int) $row->link_id,
            'form_id' => (int) $row->form_id,
            'kind' => $kind,
            'title' => $kind === 'final' ? 'Encuesta final del curso' : 'Encuesta de la sesión',
            'form_name' => (string) ($row->nombre ?? ''),
            'version' => (int) ($row->version ?? 1),
            'status' => $closed ? 'closed' : ((bool) ($row->answered ?? false) ? 'answered' : ($available ? 'pending' : 'upcoming')),
            'available' => $available,
            'available_at' => $availableAt?->toIso8601String(),
            'answered' => (bool) ($row->answered ?? false),
        ];
    }

    private function availableAt(object $session): ?Carbon
    {
        if (empty($session->fecha)) {
            return null;
        }
        try {
            $end = $session->hora_fin_prog ?? $session->hora_fin ?? '23:59:59';
            return Carbon::parse($session->fecha.' '.$end, 'America/Lima')->subMinutes(15);
        } catch (\Throwable) {
            return null;
        }
    }

    private function questionScope(string $code): string
    {
        return in_array($code, ['dudas', 'feedback_tareas', 'material_adicional', 'puntualidad', 'conocimiento', 'laboratorios'], true)
            ? 'teacher'
            : 'course';
    }

    private static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }
}
