<?php

namespace App\Repositories;

use App\Helpers\DbSafe;

class CursoRepository
{

public function obtener(int $id)
{
    $sql = "
        SELECT *
        FROM curso_edicion
        WHERE id = ?
        LIMIT 1
    ";

    $rows = DbSafe::select('mysql_cursos', $sql, [$id]);

    return $rows[0] ?? null;
}

public function listarCursosAlumno(string $correo)
{
    $sql = "
        SELECT 
            c.id,
            c.curso AS nombre,
            c.edicion,
            c.docente,
            c.horario,
            c.imagen,
            c.estadocurso,

            COUNT(DISTINCT s.id) AS total_sesiones,

            COUNT(DISTINCT CASE
                WHEN s.estado_sesion = 'realizada' THEN s.id
                ELSE NULL
            END) AS sesiones_realizadas,

            (
                SELECT COUNT(DISTINCT se.evaluacion_id)
                FROM curso_edicion_sesiones ces
                INNER JOIN curso_edicion_sesion_evaluaciones se
                    ON se.sesion_id = ces.id
                INNER JOIN evaluacion ev
                    ON ev.id = se.evaluacion_id
                   AND ev.activo = 1
                LEFT JOIN evaluacion_rendicion ri
                    ON ri.id = (
                        SELECT ri2.id
                        FROM evaluacion_rendicion ri2
                        WHERE ri2.evaluacion_id = ev.id
                          AND LOWER(TRIM(ri2.alumno_correo)) = LOWER(TRIM(?))
                        ORDER BY ri2.id DESC
                        LIMIT 1
                    )
                WHERE ces.curso_edicion_id = c.id
                  AND (
                        ri.id IS NULL
                        OR COALESCE(ri.estado, '') NOT IN ('finalizada', 'finalizado', 'entregada', 'entregado', 'corregida', 'corregido')
                  )
            ) AS pending_evaluations_count,

            (
                SELECT COUNT(DISTINCT el.id)
                FROM curso_edicion_sesiones ces
                INNER JOIN encuesta_links el
                    ON el.curso_edicion_sesion_id = ces.id
                INNER JOIN encuesta_formularios f
                    ON f.id = el.formulario_id
                   AND f.activo = 1
                LEFT JOIN encuesta_respuestas er
                    ON er.curso_edicion_sesion_id = ces.id
                   AND er.formulario_id = el.formulario_id
                   AND LOWER(TRIM(er.email)) = LOWER(TRIM(?))
                WHERE ces.curso_edicion_id = c.id
                  AND COALESCE(el.estado, 'activo') IN ('activo', 'abierto', 'publicado')
                  AND TIMESTAMP(ces.fecha, COALESCE(ces.hora_fin_prog, '23:59:59')) <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                  AND er.id IS NULL
            ) AS pending_surveys_count

        FROM Ficha_inscripcion e

        JOIN curso_edicion c 
          ON c.curso COLLATE utf8mb4_general_ci = e.curso COLLATE utf8mb4_general_ci 
         AND c.edicion COLLATE utf8mb4_general_ci = e.grupo COLLATE utf8mb4_general_ci

        LEFT JOIN curso_edicion_sesiones s 
          ON s.curso_edicion_id = c.id

        WHERE e.CORREO_PERSONAL COLLATE utf8mb4_general_ci = ?
          AND c.estadocurso IN ('en curso','programado','finalizado')

        GROUP BY 
            c.id,
            c.curso,
            c.edicion,
            c.docente,
            c.horario,
            c.imagen,
            c.estadocurso
    ";

    return DbSafe::select('mysql_cursos', $sql, [$correo, $correo, $correo]);
}

public function listarCursosSugeridosAlumno(string $correo): array
{
    $historialSql = "
        SELECT DISTINCT
            c.id,
            c.curso AS nombre,
            c.edicion,
            c.estadocurso
        FROM Ficha_inscripcion e
        JOIN curso_edicion c
          ON c.curso COLLATE utf8mb4_general_ci = e.curso COLLATE utf8mb4_general_ci
         AND c.edicion COLLATE utf8mb4_general_ci = e.grupo COLLATE utf8mb4_general_ci
        WHERE e.CORREO_PERSONAL COLLATE utf8mb4_general_ci = ?
          AND c.estadocurso IN ('en curso','programado','finalizado')
    ";

    $historial = DbSafe::select('mysql_cursos', $historialSql, [$correo]);

    if ($historial === []) {
        return [];
    }

    $candidatosSql = "
        SELECT
            c.id,
            c.curso AS nombre,
            c.edicion,
            c.docente,
            c.horario,
            c.imagen,
            c.estadocurso,
            COUNT(DISTINCT s.id) AS total_sesiones,
            COUNT(DISTINCT CASE WHEN s.estado_sesion = 'realizada' THEN s.id ELSE NULL END) AS sesiones_realizadas
        FROM curso_edicion c
        LEFT JOIN curso_edicion_sesiones s
          ON s.curso_edicion_id = c.id
        WHERE c.estadocurso IN ('programado','programada')
          AND NOT EXISTS (
            SELECT 1
            FROM Ficha_inscripcion e
            WHERE e.CORREO_PERSONAL COLLATE utf8mb4_general_ci = ?
              AND e.curso COLLATE utf8mb4_general_ci = c.curso COLLATE utf8mb4_general_ci
              AND e.grupo COLLATE utf8mb4_general_ci = c.edicion COLLATE utf8mb4_general_ci
          )
        GROUP BY
            c.id,
            c.curso,
            c.edicion,
            c.docente,
            c.horario,
            c.imagen,
            c.estadocurso
        ORDER BY c.id DESC
        LIMIT 40
    ";

    $candidatos = DbSafe::select('mysql_cursos', $candidatosSql, [$correo]);

    if ($candidatos === []) {
        return [];
    }

    $historialTemas = [];
    foreach ($historial as $curso) {
        $temas = $this->courseTopics((string) ($curso->nombre ?? ''));
        foreach ($temas as $tema) {
            $historialTemas[] = [
                'topic' => $tema,
                'title' => (string) ($curso->nombre ?? ''),
                'completed' => in_array($this->normalizeStatus((string) ($curso->estadocurso ?? '')), ['finalizado', 'completado', 'completed'], true),
            ];
        }
    }

    $sugeridos = [];
    foreach ($candidatos as $curso) {
        $topics = $this->courseTopics((string) ($curso->nombre ?? ''));
        [$score, $reason] = $this->suggestionScore($topics, $historialTemas);

        $curso->sugerido = true;
        $curso->suggestion_score = $score;
        $curso->suggestion_reason = $reason;
        $sugeridos[] = $curso;
    }

    usort($sugeridos, function ($a, $b) {
        $score = ((int) ($b->suggestion_score ?? 0)) <=> ((int) ($a->suggestion_score ?? 0));
        return $score !== 0 ? $score : ((int) ($b->id ?? 0) <=> (int) ($a->id ?? 0));
    });

    return array_slice($sugeridos, 0, 6);
}

private function normalizeStatus(string $status): string
{
    return mb_strtolower(trim($status), 'UTF-8');
}

private function courseTopics(string $title): array
{
    $text = mb_strtolower($title, 'UTF-8');
    $map = [
        'power_bi' => ['power bi', 'storytelling'],
        'python' => ['python', 'ciencia de datos'],
        'ia' => [' ia ', 'inteligencia artificial', 'generativa', 'llm', 'agentes'],
        'azure' => ['azure'],
        'sql' => ['sql'],
        'ciberseguridad' => ['ciberseguridad', 'seguridad'],
        'databricks' => ['databricks'],
        'datos' => ['datos', 'data'],
    ];

    $topics = [];
    $normalized = ' ' . $text . ' ';
    foreach ($map as $topic => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                $topics[] = $topic;
                break;
            }
        }
    }

    return array_values(array_unique($topics));
}

private function suggestionScore(array $candidateTopics, array $historyTopics): array
{
    $complements = [
        'sql' => ['power_bi', 'python'],
        'power_bi' => ['sql', 'python', 'datos'],
        'python' => ['databricks', 'ia', 'datos'],
        'ia' => ['python', 'databricks', 'power_bi'],
        'azure' => ['ciberseguridad', 'datos'],
        'ciberseguridad' => ['azure'],
        'databricks' => ['python', 'ia'],
        'datos' => ['power_bi', 'python', 'databricks'],
    ];

    $best = [5, 'Tambien te puede interesar'];

    foreach ($historyTopics as $history) {
        $topic = $history['topic'];
        $source = trim((string) ($history['title'] ?? ''));
        $completed = (bool) ($history['completed'] ?? false);

        if (in_array($topic, $candidateTopics, true)) {
            $score = $completed ? 90 : 70;
            $reason = $completed && $source !== ''
                ? "Relacionado con tu curso completado de {$source}"
                : ($source !== '' ? "Relacionado con tu curso actual de {$source}" : 'Relacionado con tus cursos');
            if ($score > $best[0]) {
                $best = [$score, $reason];
            }
        }

        foreach ($complements[$topic] ?? [] as $complement) {
            if (in_array($complement, $candidateTopics, true)) {
                $score = $completed ? 60 : 45;
                $reason = $source !== '' ? "Complementa lo aprendido en {$source}" : 'Complementa tu ruta de aprendizaje';
                if ($score > $best[0]) {
                    $best = [$score, $reason];
                }
            }
        }
    }

    return $best;
}

public function listarCursosBackoffice(string $correo, string $rol)
{
    $sql = "
        SELECT 
            ce.id,
            ce.curso AS nombre,
            ce.edicion,
            ce.docente,
            ce.horario,
            ce.imagen,
            ce.estadocurso,
            COALESCE(ins.inscritos, 0) AS alumnos_inscritos,

            COUNT(s.id) AS total_sesiones,

            SUM(
                CASE 
                    WHEN s.estado_sesion = 'realizada' THEN 1 
                    ELSE 0 
                END
            ) AS sesiones_realizadas,

            SUM(
                CASE 
                    WHEN s.fecha = CURDATE() 
                    AND s.tiene_material = 0 THEN 1
                    ELSE 0
                END
            ) AS sesiones_hoy_sin_material,

            SUM(
                CASE 
                    WHEN s.fecha < CURDATE() 
                    AND s.tiene_material = 0 THEN 1
                    ELSE 0
                END
            ) AS sesiones_pasadas_sin_material,

            (
                SELECT COUNT(*)
                FROM curso_edicion_sesiones ces
                INNER JOIN curso_edicion_sesion_evaluaciones cese
                    ON cese.sesion_id = ces.id
                WHERE ces.curso_edicion_id = ce.id
            ) AS total_evaluaciones

        FROM curso_edicion ce

        LEFT JOIN colaborador c 
        ON ce.docente_id_colaborador = c.id_colaborador

        LEFT JOIN usuario u
        ON u.colaborador_id = c.id_colaborador

        LEFT JOIN (
            SELECT
                curso COLLATE utf8mb4_general_ci AS curso,
                grupo COLLATE utf8mb4_general_ci AS edicion,
                COUNT(DISTINCT CORREO_PERSONAL) AS inscritos
            FROM Ficha_inscripcion
            GROUP BY curso COLLATE utf8mb4_general_ci, grupo COLLATE utf8mb4_general_ci
        ) ins
        ON ins.curso = ce.curso COLLATE utf8mb4_general_ci
        AND ins.edicion = ce.edicion COLLATE utf8mb4_general_ci

        LEFT JOIN (
            SELECT 
                ces.id,
                ces.curso_edicion_id,
                ces.fecha,
                ces.estado_sesion,

                CASE 
                    WHEN COUNT(DISTINCT m.id) > 0 THEN 1 ELSE 0 
                END AS tiene_material

            FROM curso_edicion_sesiones ces

            LEFT JOIN curso_edicion_sesion_materiales m
            ON m.curso_edicion_sesion_id = ces.id

            GROUP BY 
                ces.id,
                ces.curso_edicion_id,
                ces.fecha,
                ces.estado_sesion
        ) s ON s.curso_edicion_id = ce.id

        WHERE (? = 'admin' OR ? = '' OR u.email = ?)
        AND ce.estadocurso IN ('en curso','programado','finalizado')

        GROUP BY 
            ce.id,
            ce.curso,
            ce.edicion,
            ce.docente,
            ce.horario,
            ce.imagen,
            ce.estadocurso,
            ins.inscritos
    ";

     
    return DbSafe::select('mysql_cursos', $sql, [$rol, $correo, $correo]); 
 
}


public function listarCursosParaEvaluaciones(string $correo, string $rol)
{
    $sql = "
SELECT 
    ce.id AS curso_id,
    ce.edicion,
    ce.curso AS nombre,
    ce.docente,
    ce.horario,
    COALESCE(ins.inscritos, 0) AS alumnos_inscritos,
    COUNT(DISTINCT e.id) AS nro_evaluaciones,
    COUNT(DISTINCT CASE WHEN e.publicada = 1 THEN e.id END) AS evaluaciones_publicadas,
    COUNT(DISTINCT CASE WHEN COALESCE(e.publicada, 0) = 0 THEN e.id END) AS evaluaciones_borrador
FROM curso_edicion ce

LEFT JOIN evaluacion e 
    ON e.curso_id = ce.id
   AND e.activo = 1

LEFT JOIN colaborador col
    ON col.id_colaborador = ce.docente_id_colaborador

LEFT JOIN usuario u
    ON u.colaborador_id = col.id_colaborador

LEFT JOIN (
    SELECT
        curso COLLATE utf8mb4_general_ci AS curso,
        grupo COLLATE utf8mb4_general_ci AS edicion,
        COUNT(DISTINCT CORREO_PERSONAL) AS inscritos
    FROM Ficha_inscripcion
    GROUP BY curso COLLATE utf8mb4_general_ci, grupo COLLATE utf8mb4_general_ci
) ins
    ON ins.curso = ce.curso COLLATE utf8mb4_general_ci
   AND ins.edicion = ce.edicion COLLATE utf8mb4_general_ci

WHERE ce.activo = 1
  AND ce.estadocurso = 'en curso'
  AND (
        ? = 'admin'
        OR (
            ? = 'operador'
            AND u.email = ?
        )
      )

GROUP BY 
    ce.id,
    ce.edicion,
    ce.curso,
    ce.docente,
    ce.horario,
    ins.inscritos

ORDER BY ce.curso ASC, ce.edicion ASC
    ";

    return DbSafe::select('mysql_cursos', $sql, [
        $rol,
        $rol,
        $correo
    ]);
}

public function listarCursosParaCalificaciones(string $correo, string $rol, bool $includeFinished = false)
{
    $statusFilter = $includeFinished
        ? "ce.estadocurso IN ('en curso', 'programado', 'finalizado')"
        : "ce.estadocurso = 'en curso'";

    $sql = "
SELECT
    ce.id AS curso_id,
    ce.id AS curso_edicion_id,
    CONCAT('Edicion ', ce.edicion) AS codigo,
    ce.curso AS nombre,
    ce.docente,
    ce.horario,
    ce.imagen,
    ce.estadocurso,
    COALESCE(ins.inscritos, 0) AS alumnos_inscritos,
    COALESCE(s.total_sesiones, 0) AS total_sesiones,
    COALESCE(s.sesiones_realizadas, 0) AS sesiones_realizadas,
    COALESCE(ev.exam_count, 0) AS exam_count,
    COALESCE(ev.work_count, 0) AS work_count,
    COALESCE(survey.respuestas_encuesta, 0) AS survey_response_count,
    COALESCE(cert.certificados_total, 0) AS certificados_total,
    COALESCE(cert.certificados_pendientes, 0) AS certificados_pendientes,
    COALESCE(cert.certificados_adjuntados, 0) AS certificados_adjuntados,
    COALESCE(cert.certificados_enviados, 0) AS certificados_enviados
FROM curso_edicion ce

LEFT JOIN colaborador col
  ON col.id_colaborador = ce.docente_id_colaborador

LEFT JOIN usuario u
  ON u.colaborador_id = col.id_colaborador

LEFT JOIN (
    SELECT
        curso COLLATE utf8mb4_general_ci AS curso,
        grupo COLLATE utf8mb4_general_ci AS edicion,
        COUNT(DISTINCT CORREO_PERSONAL) AS inscritos
    FROM Ficha_inscripcion
    GROUP BY curso COLLATE utf8mb4_general_ci, grupo COLLATE utf8mb4_general_ci
) ins
  ON ins.curso = ce.curso COLLATE utf8mb4_general_ci
 AND ins.edicion = ce.edicion COLLATE utf8mb4_general_ci

LEFT JOIN (
    SELECT
        ces.curso_edicion_id,
        COUNT(*) AS total_sesiones,
        SUM(CASE WHEN ces.estado_sesion = 'realizada' THEN 1 ELSE 0 END) AS sesiones_realizadas
    FROM curso_edicion_sesiones ces
    GROUP BY ces.curso_edicion_id
) s
  ON s.curso_edicion_id = ce.id

LEFT JOIN (
    SELECT
        e.curso_id,
        COUNT(DISTINCT CASE WHEN e.tipo_param_id IN (1, 2) THEN e.id END) AS exam_count,
        COUNT(DISTINCT CASE WHEN e.tipo_param_id IN (3, 4) THEN e.id END) AS work_count
    FROM evaluacion e
    WHERE e.activo = 1
    GROUP BY e.curso_id
) ev
  ON ev.curso_id = ce.id

LEFT JOIN (
    SELECT
        ces.curso_edicion_id,
        COUNT(DISTINCT COALESCE(er.submission_uuid, CONCAT('row-', er.id))) AS respuestas_encuesta
    FROM curso_edicion_sesiones ces
    LEFT JOIN encuesta_respuestas er
      ON er.curso_edicion_sesion_id = ces.id
    GROUP BY ces.curso_edicion_id
) survey
  ON survey.curso_edicion_id = ce.id

LEFT JOIN (
    SELECT
        ac.curso_edicion_id,
        COUNT(*) AS certificados_total,
        SUM(CASE WHEN ac.estado = 'pendiente' THEN 1 ELSE 0 END) AS certificados_pendientes,
        SUM(CASE WHEN ac.estado = 'adjuntado' THEN 1 ELSE 0 END) AS certificados_adjuntados,
        SUM(CASE WHEN ac.estado = 'enviado' THEN 1 ELSE 0 END) AS certificados_enviados
    FROM alumno_certificado ac
    GROUP BY ac.curso_edicion_id
) cert
  ON cert.curso_edicion_id = ce.id
WHERE ce.activo = 1
  AND {$statusFilter}
  AND (
        ? = 'admin'
        OR (
            ? = 'operador'
            AND u.email = ?
        )
      )
ORDER BY ce.curso ASC, ce.edicion ASC
    ";

    return DbSafe::select('mysql_cursos', $sql, [
        $rol,
        $rol,
        $correo,
    ]);
}

    public function obtenerCurso(int $cursoId)
    {
        $sql = "
            SELECT 
                curso_id,
                codigo,
                nombre
            FROM curso
            WHERE curso_id = ?
            AND activo = 1
            LIMIT 1
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [$cursoId]);

        return $rows[0] ?? null;
    }

    public function listarAlumnosCurso(int $cursoEdicionId, string $solicitanteCorreo = '')
    {
        $sql = "
            SELECT
                ce.id AS curso_edicion_id,
                ce.curso,
                ce.edicion,
                ce.docente,
                ce.horario,

                CRC32(CONCAT_WS('|', fi.CORREO_PERSONAL, fi.DNI, fi.NOMBRES, fi.APELLIDOS)) AS id,
                fi.NOMBRES,
                fi.APELLIDOS,
                CONCAT(fi.NOMBRES, ' ', fi.APELLIDOS) AS alumno,
                fi.CORREO_PERSONAL,
                fi.correo_corporativo,
                fi.TELEFONO,
                fi.DNI,
                fi.estado_pago,
                COALESCE(a.contacto_publico, 0) AS contacto_publico,
                a.foto_url,
                (
                    SELECT sc.estado
                    FROM solicitud_contacto sc
                    WHERE sc.curso_edicion_id = ce.id
                      AND LOWER(TRIM(sc.solicitante_correo)) = LOWER(TRIM(?))
                      AND LOWER(TRIM(sc.destinatario_correo)) = LOWER(TRIM(fi.CORREO_PERSONAL))
                    ORDER BY sc.fecha_solicitud DESC
                    LIMIT 1
                ) AS solicitud_contacto_estado

            FROM curso_edicion ce

            INNER JOIN Ficha_inscripcion fi
                ON fi.CURSO COLLATE utf8mb4_general_ci = ce.curso COLLATE utf8mb4_general_ci
            AND fi.grupo COLLATE utf8mb4_general_ci = ce.edicion COLLATE utf8mb4_general_ci

            LEFT JOIN alumno a
                ON LOWER(TRIM(a.correo)) = LOWER(TRIM(fi.CORREO_PERSONAL))

            WHERE ce.id = ?

            ORDER BY  fi.NOMBRES,fi.APELLIDOS
        ";

        return DbSafe::select('mysql_cursos', $sql, [
            strtolower(trim($solicitanteCorreo)),
            $cursoEdicionId,
        ]);
    }
}
