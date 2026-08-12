<?php

namespace App\Repositories;

use App\Helpers\DbSafe;
use Illuminate\Support\Facades\DB;

class SesionRepository
{

    /**
     * Lista sesiones por curso (vista alumno)
     */
    public function listarPorCursoAlumno(int $cursoId)
    {
        $sql = "
            SELECT
                s.id,
                s.curso_edicion_id,
                s.docente_id,
                s.nro_sesion AS numero,
                s.fecha,
                s.hora_inicio_prog AS hora_inicio,
                s.hora_fin_prog AS hora_fin,
                s.dur_min AS duracion,
                s.estado_sesion AS estado,
                ce.curso AS curso_nombre,
                ce.docente AS curso_docente,
                ce.edicion AS curso_edicion,
                COALESCE(zh.email, ce.cta_zoom) AS zoom_host_email,

                s.video_status,
                s.video_drive_file_id,
                s.video_uploaded_at,
                s.video_filesize,
                s.video_chat_drive_file_id,
                s.video_chat_titulo,
                s.video_chat_filesize,
                s.video_chat_uploaded_at
                
            FROM curso_edicion_sesiones s
            INNER JOIN curso_edicion ce
                ON ce.id = s.curso_edicion_id
            LEFT JOIN zoom_hosts zh
                ON zh.id = s.zoom_host_id
            WHERE s.curso_edicion_id = ?
            ORDER BY s.nro_sesion
        ";

        return DbSafe::select('mysql_cursos', $sql, [$cursoId]);
    }

    public function listarPorCursoAlumnoLight(int $cursoId)
    {
        $sql = "
            SELECT
                s.id,
                s.curso_edicion_id,
                s.nro_sesion AS numero,
                s.fecha,
                s.hora_inicio_prog AS hora_inicio,
                s.hora_fin_prog AS hora_fin,
                s.dur_min AS duracion,
                s.estado_sesion AS estado,
                ce.curso AS curso_nombre,
                ce.docente AS curso_docente,
                ce.edicion AS curso_edicion,
                COALESCE(zh.email, ce.cta_zoom) AS zoom_host_email
            FROM curso_edicion_sesiones s
            INNER JOIN curso_edicion ce
                ON ce.id = s.curso_edicion_id
            LEFT JOIN zoom_hosts zh
                ON zh.id = s.zoom_host_id
            WHERE s.curso_edicion_id = ?
            ORDER BY s.nro_sesion
        ";

        return DbSafe::select('mysql_cursos', $sql, [$cursoId]);
    }

    /**
     * Lista sesiones por curso (vista profesor)
     */
    public function listarPorCursoProfesor(int $cursoId)
    {
        $sql = "
            SELECT
            s.id,
            s.curso_edicion_id,
            s.docente_id,
            s.nro_sesion AS numero,
            s.fecha,
            s.hora_inicio_prog AS hora_inicio,
            s.hora_fin_prog AS hora_fin,
            s.dur_min AS duracion,
            s.estado_sesion AS estado,

            s.video_status,
            s.video_drive_file_id,
            s.video_uploaded_at,
            s.video_filesize,
            s.video_chat_drive_file_id,
            s.video_chat_titulo,
            s.video_chat_filesize,
            s.video_chat_uploaded_at,

            ce.curso AS curso_nombre,
            ce.docente AS curso_docente,
            ce.edicion AS curso_edicion,
            COALESCE(zh.email, ce.cta_zoom) AS zoom_host_email,

            CASE
                WHEN s.fecha <= CURDATE()
                AND COUNT(DISTINCT m.id) = 0
                THEN 1 ELSE 0
            END AS falta_material,

            CASE
                WHEN COUNT(DISTINCT ev.id) > 0
                THEN 1 ELSE 0
            END AS existe_evaluacion

        FROM curso_edicion_sesiones s

        INNER JOIN curso_edicion ce
            ON ce.id = s.curso_edicion_id

        LEFT JOIN zoom_hosts zh
            ON zh.id = s.zoom_host_id

        LEFT JOIN curso_edicion_sesion_materiales m
            ON m.curso_edicion_sesion_id = s.id
            AND m.activo = 1

        LEFT JOIN curso_edicion_sesion_evaluaciones ev
            ON ev.sesion_id = s.id

        WHERE s.curso_edicion_id = ?

        GROUP BY
            s.id,
            s.curso_edicion_id,
            s.nro_sesion,
            s.fecha,
            s.hora_inicio_prog,
            s.hora_fin_prog,
            s.dur_min,
            s.estado_sesion,
            ce.curso,
            ce.docente,
            ce.edicion,
            zh.email,
            ce.cta_zoom

        ORDER BY s.nro_sesion
        ";

        return DbSafe::select('mysql_cursos', $sql, [$cursoId]);
    }

    /**
     * Obtiene una sesión por ID
     */
    public function obtenerPorId(int $sesionId)
    {
        $sql = "
            SELECT
                s.id,
                s.curso_edicion_id,
                s.docente_id,
                s.nro_sesion AS numero,
                s.fecha,
                s.hora_inicio_prog AS hora_inicio,
                s.hora_fin_prog AS hora_fin,
                s.dur_min AS duracion,
                s.estado_sesion AS estado,
                
                s.video_status,
                s.video_drive_file_id,
                s.video_uploaded_at,
                s.video_filesize,
                s.video_chat_drive_file_id,
                s.video_chat_titulo,
                s.video_chat_filesize,
                s.video_chat_uploaded_at,

                ce.curso AS curso_nombre,
                ce.docente AS curso_docente,
                ce.edicion AS curso_edicion,
                COALESCE(zh.email, ce.cta_zoom) AS zoom_host_email

            FROM curso_edicion_sesiones s
            INNER JOIN curso_edicion ce
                ON ce.id = s.curso_edicion_id
            LEFT JOIN zoom_hosts zh
                ON zh.id = s.zoom_host_id
            WHERE s.id = ?
            LIMIT 1
        ";

        $result = DbSafe::select('mysql_cursos', $sql, [$sesionId]);

        return $result[0] ?? null;
    }

    /**
     * Actualiza información del video de la sesión
     */
    public function updateVideoStatus(int $sesionId, array $data): void
    {
        $fields = [];
        $params = [];

        foreach ($data as $column => $value) {
            $fields[] = "$column = ?";
            $params[] = $value;
        }

        $params[] = $sesionId;

        $sql = "
            UPDATE curso_edicion_sesiones
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ";

        DbSafe::update('mysql_cursos', $sql, $params);
    }

    /**
     * Obtiene información del video de una sesión
     */
    public function obtenerVideoSesion(int $sesionId)
    {
        $sql = "
            SELECT
                video_drive_file_id,
                video_titulo,
                video_status,
                video_filesize,
                video_uploaded_at,
                video_chat_drive_file_id,
                video_chat_titulo,
                video_chat_filesize,
                video_chat_uploaded_at
            FROM curso_edicion_sesiones
            WHERE id = ?
            LIMIT 1
        ";

        $result = DbSafe::select('mysql_cursos', $sql, [$sesionId]);

        return $result[0] ?? null;
    }

    public function clearVideo(int $sesionId): void
    {
        $sql = "
            UPDATE curso_edicion_sesiones
            SET
                video_drive_file_id = NULL,
                video_status = NULL,
                video_uploaded_at = NULL,
                video_filesize = NULL,
                video_chat_drive_file_id = NULL,
                video_chat_titulo = NULL,
                video_chat_filesize = NULL,
                video_chat_uploaded_at = NULL
            WHERE id = ?
        ";

        DbSafe::update('mysql_cursos', $sql, [$sesionId]);
    }

    public function obtenerEvaluacionesPorSesiones(array $sesionIds, ?string $alumnoCorreo = null)
    {
        if (empty($sesionIds)) return [];

        $placeholders = implode(',', array_fill(0, count($sesionIds), '?'));
        $params = $sesionIds;
        $selectUltimaRendicion = '';
        $joinUltimaRendicion = '';

        if ($alumnoCorreo !== null && $alumnoCorreo !== '') {
            $selectUltimaRendicion = ",
                ri.id AS rendicion_id,
                ri.estado AS rendicion_estado,
                CASE
                    WHEN e.tipo_param_id IN (3, 4) THEN tc.puntaje_total
                    ELSE ri.puntaje_total
                END AS puntaje_total,
                CASE
                    WHEN e.tipo_param_id IN (3, 4) THEN tc.aprobado
                    ELSE ri.aprobado
                END AS aprobado,
                tc.id AS calificacion_id,
                tc.fecha_correccion";
            $joinUltimaRendicion = "
            LEFT JOIN evaluacion_rendicion ri
                ON ri.id = (
                    SELECT ri2.id
                    FROM evaluacion_rendicion ri2
                    WHERE ri2.evaluacion_id = e.id
                    AND ri2.alumno_correo = ?
                    ORDER BY ri2.id DESC
                    LIMIT 1
                )
            LEFT JOIN evaluacion_rendicion_calificacion tc
                ON tc.rendicion_id = ri.id";
            array_unshift($params, $alumnoCorreo);
        }

        $sql = "
            SELECT
                se.sesion_id,
                e.id,
                e.nombre,
                e.tipo_param_id,
                e.puntaje_aprobacion,
                CASE
                    WHEN e.tipo_param_id IN (1, 2) THEN (
                        SELECT COALESCE(SUM(ep.puntaje), 0)
                        FROM evaluacion_pregunta ep
                        WHERE ep.evaluacion_id = e.id
                    )
                    ELSE NULL
                END AS puntaje_maximo,
                p.desc_valor AS tipo
                ,
                se.fecha_limite,
                se.hito_nombre,
                se.hito_orden,
                se.grupo_nombre,
                se.plazo_dias
                $selectUltimaRendicion
            FROM curso_edicion_sesion_evaluaciones se
            INNER JOIN evaluacion e 
                ON e.id = se.evaluacion_id
            LEFT JOIN parametros p
                ON p.id_maestro = 21
                AND p.id_valor = e.tipo_param_id
            $joinUltimaRendicion
            WHERE se.sesion_id IN ($placeholders)
            ORDER BY se.sesion_id, e.nombre
        ";

        return DbSafe::select('mysql_cursos', $sql, $params);
    }

public function listarEvaluacionesSesion(int $sesionId)
{
    $sql = "
        SELECT
            e.id,
            e.nombre,
            e.tipo_param_id,
            p.desc_valor AS tipo,
            se.fecha_limite,
            se.hito_nombre,
            se.hito_orden,
            se.grupo_nombre,
            se.plazo_dias
        FROM curso_edicion_sesion_evaluaciones se
        INNER JOIN evaluacion e
            ON e.id = se.evaluacion_id
        LEFT JOIN parametros p
            ON p.id_maestro = 21
            AND p.id_valor = e.tipo_param_id
        WHERE se.sesion_id = ?
        ORDER BY e.nombre
    ";

    return DbSafe::select(
        'mysql_cursos',
        $sql,
        [$sesionId]
    );
}

public function listarEvaluacionesDisponibles(
    int $cursoEdicionId,
    int $sesionId
){
    $sql = "
        SELECT
            e.id,
            e.nombre,
            e.tipo_param_id,
            p.desc_valor AS tipo
        FROM curso_edicion ce
        INNER JOIN evaluacion e
            ON e.curso_id = ce.id
        LEFT JOIN parametros p
            ON p.id_maestro = 21
            AND p.id_valor = e.tipo_param_id
        WHERE ce.id = ?
        AND e.publicada = 1
        AND NOT EXISTS (
            SELECT 1
            FROM curso_edicion_sesion_evaluaciones se
            INNER JOIN curso_edicion_sesiones s
                ON s.id = se.sesion_id
            WHERE s.curso_edicion_id = ce.id
            AND se.evaluacion_id = e.id
        )
        ORDER BY e.nombre
    ";

    return DbSafe::select(
        'mysql_cursos',
        $sql,
        [$cursoEdicionId]
    );
}

    public function addEvaluacion(
        int $sesionId,
        int $evaluacionId,
        ?string $fechaLimite = null,
        ?string $hitoNombre = null,
        ?int $hitoOrden = null,
        ?string $grupoNombre = null,
        ?int $plazoDias = null
    ): void
{
    DbSafe::execute('mysql_cursos', function () use ($sesionId, $evaluacionId, $fechaLimite, $hitoNombre, $hitoOrden, $grupoNombre, $plazoDias) {

        DB::connection('mysql_cursos')
            ->table('curso_edicion_sesion_evaluaciones')
            ->insertOrIgnore([
                'sesion_id' => $sesionId,
                'evaluacion_id' => $evaluacionId,
                'fecha_limite' => $fechaLimite,
                'hito_nombre' => $hitoNombre,
                'hito_orden' => $hitoOrden,
                'grupo_nombre' => $grupoNombre,
                'plazo_dias' => $plazoDias,
            ]);
    });
}

public function existsEvaluacionSesion(int $sesionId, int $evaluacionId): bool
{
    $sql = "
        SELECT 1
        FROM curso_edicion_sesion_evaluaciones
        WHERE sesion_id = ?
        AND evaluacion_id = ?
        LIMIT 1
    ";

    $rows = DbSafe::select(
        'mysql_cursos',
        $sql,
        [$sesionId, $evaluacionId]
    );

    return !empty($rows);
}

public function updateFechaLimiteEvaluacion(
    int $sesionId,
    int $evaluacionId,
    ?string $fechaLimite
): void {
    $sql = "
        UPDATE curso_edicion_sesion_evaluaciones
        SET fecha_limite = ?
        WHERE sesion_id = ?
        AND evaluacion_id = ?
    ";

    DbSafe::update(
        'mysql_cursos',
        $sql,
        [$fechaLimite, $sesionId, $evaluacionId]
    );
}

public function updateEvaluacionMetadata(
    int $sesionId,
    int $evaluacionId,
    array $metadata
): void {
    $allowed = ['fecha_limite', 'hito_nombre', 'hito_orden', 'grupo_nombre', 'plazo_dias'];
    $updates = [];
    $params = [];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $metadata)) {
            $updates[] = "{$field} = ?";
            $params[] = $metadata[$field];
        }
    }

    if (empty($updates)) {
        return;
    }

    $params[] = $sesionId;
    $params[] = $evaluacionId;

    DbSafe::update(
        'mysql_cursos',
        'UPDATE curso_edicion_sesion_evaluaciones SET '.implode(', ', $updates).' WHERE sesion_id = ? AND evaluacion_id = ?',
        $params
    );
}

public function obtenerEvaluacionPlanCurso(int $cursoEdicionId): array
{
    $sql = "
        SELECT
            s.id AS sesion_id,
            s.nro_sesion AS sesion_numero,
            s.fecha,
            s.hora_inicio_prog AS hora_inicio,
            s.hora_fin_prog AS hora_fin,
            s.estado_sesion,
            se.id AS curso_sesion_evaluacion_id,
            se.fecha_limite,
            se.hito_nombre,
            se.hito_orden,
            se.grupo_nombre,
            se.plazo_dias,
            e.id AS evaluacion_id,
            e.nombre,
            e.tipo_param_id,
            e.peso,
            e.publicada,
            p.desc_valor AS tipo,
            COUNT(DISTINCT er.id) AS entregas_total,
            COUNT(DISTINCT c.id) AS calificaciones_total
        FROM curso_edicion_sesiones s
        LEFT JOIN curso_edicion_sesion_evaluaciones se
            ON se.sesion_id = s.id
        LEFT JOIN evaluacion e
            ON e.id = se.evaluacion_id
        LEFT JOIN parametros p
            ON p.id_maestro = 21
            AND p.id_valor = e.tipo_param_id
        LEFT JOIN evaluacion_rendicion er
            ON er.evaluacion_id = e.id
        LEFT JOIN evaluacion_rendicion_calificacion c
            ON c.rendicion_id = er.id
        WHERE s.curso_edicion_id = ?
        GROUP BY
            s.id,
            s.nro_sesion,
            s.fecha,
            s.hora_inicio_prog,
            s.hora_fin_prog,
            s.estado_sesion,
            se.id,
            se.fecha_limite,
            se.hito_nombre,
            se.hito_orden,
            se.grupo_nombre,
            se.plazo_dias,
            e.id,
            e.nombre,
            e.tipo_param_id,
            e.peso,
            e.publicada,
            p.desc_valor
        ORDER BY s.nro_sesion, COALESCE(se.hito_orden, 999), e.nombre
    ";

    return DbSafe::select('mysql_cursos', $sql, [$cursoEdicionId]);
}

public function deleteEvaluacion(int $sesionId, int $evaluacionId): void
{
    $sql = "
        DELETE FROM curso_edicion_sesion_evaluaciones
        WHERE sesion_id = ?
        AND evaluacion_id = ?
    ";

    DbSafe::statement(
        'mysql_cursos',
        $sql,
        [$sesionId, $evaluacionId]
    );
}


}
