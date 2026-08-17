<?php

namespace App\Repositories;

use App\Helpers\DbSafe;
use Illuminate\Support\Facades\DB;

class EvaluacionRepository
{
    public function listarPorCurso(int $cursoId)
    {
        $sql = "
            SELECT
                e.*,
                p.desc_valor AS tipo_descripcion,
                c.curso AS curso_nombre
            FROM curso_edicion c
            LEFT JOIN evaluacion e
                ON e.curso_id = c.id
                AND e.activo = 1
            LEFT JOIN parametros p
                ON p.id_valor = e.tipo_param_id
                AND p.id_maestro = 21
                AND p.flg_activo = 1
            WHERE c.id = ?
            ORDER BY e.id DESC
        ";

        return DbSafe::select('mysql_cursos', $sql, [$cursoId]);
    }

public function obtenerDashboardCalificacionesCurso(int $cursoEdicionId)
{
    $sql = "
        SELECT
            ce.id AS curso_id,
            ce.id AS curso_edicion_id,
            cse.id AS curso_sesion_evaluacion_id,
            ces.id AS sesion_id,
            ce.curso AS curso_nombre,

            e.id AS evaluacion_id,
            e.nombre AS evaluacion_nombre,
            e.tipo_param_id,
            p.desc_valor AS tipo_descripcion,
            e.publicada,
            e.peso,
            e.puntaje_aprobacion,

            cse.fecha_limite,
            e.created_at,

            COALESCE(al.alumnos_total, 0) AS alumnos_total,

            COALESCE(ex.rindieron, 0) AS rindieron,
            COALESCE(ex.promedio, 0) AS promedio,
            COALESCE(ex.maximo, 0) AS maximo,
            COALESCE(ex.minimo, 0) AS minimo,
            COALESCE(ex.desaprobados, 0) AS desaprobados,

            COALESCE(tr.entregaron, 0) AS entregaron,
            COALESCE(tr.corregidos, 0) AS corregidos

        FROM curso_edicion ce

        LEFT JOIN curso_edicion_sesiones ces
            ON ces.curso_edicion_id = ce.id

        LEFT JOIN curso_edicion_sesion_evaluaciones cse
            ON cse.sesion_id = ces.id

        LEFT JOIN evaluacion e
            ON e.id = cse.evaluacion_id
           AND e.activo = 1

        LEFT JOIN parametros p
            ON p.id_valor = e.tipo_param_id
           AND p.id_maestro = 21
           AND p.flg_activo = 1

        LEFT JOIN (
            SELECT
                ce2.id AS curso_edicion_id,
                COUNT(DISTINCT fi.CORREO_PERSONAL) AS alumnos_total
            FROM curso_edicion ce2
            INNER JOIN Ficha_inscripcion fi
                ON fi.curso COLLATE utf8mb4_unicode_ci =
                   ce2.curso COLLATE utf8mb4_unicode_ci
               AND fi.grupo COLLATE utf8mb4_unicode_ci =
                   ce2.edicion COLLATE utf8mb4_unicode_ci
            WHERE ce2.activo = 1
              AND ce2.estadocurso IN ('en curso', 'programado')
            GROUP BY ce2.id
        ) al
            ON al.curso_edicion_id = ce.id

        LEFT JOIN (
            SELECT
                ri.evaluacion_id,
                COUNT(*) AS rindieron,
                ROUND(AVG(COALESCE(ri.puntaje_total,0)),1) AS promedio,
                ROUND(MAX(COALESCE(ri.puntaje_total,0)),1) AS maximo,
                ROUND(MIN(COALESCE(ri.puntaje_total,0)),1) AS minimo,
                SUM(CASE WHEN COALESCE(ri.aprobado,0)=0 THEN 1 ELSE 0 END) AS desaprobados
            FROM evaluacion_rendicion ri
            WHERE ri.estado = 'finalizado'
            GROUP BY ri.evaluacion_id
        ) ex
            ON ex.evaluacion_id = e.id

        LEFT JOIN (
            SELECT
                et.evaluacion_id,
                SUM(CASE WHEN et.estado IN ('entregado', 'corregido') THEN 1 ELSE 0 END) AS entregaron,
                SUM(
                    CASE
                        WHEN et.estado = 'corregido'
                         AND tc.id IS NOT NULL
                        THEN 1
                        ELSE 0
                    END
                ) AS corregidos
            FROM evaluacion_rendicion et
            LEFT JOIN evaluacion_rendicion_calificacion tc
                ON tc.rendicion_id = et.id
            GROUP BY et.evaluacion_id
        ) tr
            ON tr.evaluacion_id = e.id

        WHERE ce.id = ?
          AND ce.activo = 1

        ORDER BY
            CASE
                WHEN e.tipo_param_id IN (1,2) THEN 0
                WHEN e.tipo_param_id IN (3,4) THEN 1
                ELSE 2
            END,
            COALESCE(cse.fecha_limite, e.created_at) ASC,
            cse.id ASC
    ";

    return DbSafe::select('mysql_cursos', $sql, [$cursoEdicionId]);
}

    public function obtener(int $evaluacionId)
    {
        $sql = "
            SELECT
                e.*,
                p.desc_valor AS tipo_descripcion
            FROM evaluacion e
            LEFT JOIN parametros p
                ON p.id_valor = e.tipo_param_id
                AND p.id_maestro = 21
                AND p.flg_activo = 1
            WHERE e.id = ?
            LIMIT 1
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [$evaluacionId]);

        return $rows[0] ?? null;
    }

    public function insertar(array $data)
    {
        $sql = "
            INSERT INTO evaluacion (
                curso_id,
                tipo_param_id,
                nombre,
                tiempo_minutos,
                puntaje_aprobacion,
                descripcion,
                peso,
                version,
                activo,
                publicada,
                created_by,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ";

        return DbSafe::execute('mysql_cursos', function () use ($sql, $data) {
            $conn = DB::connection('mysql_cursos');

            $conn->insert($sql, [
                $data['curso_id'],
                $data['tipo_param_id'],
                $data['nombre'],
                $data['tiempo_minutos'] ?? 30,
                $data['puntaje_aprobacion'] ?? 70,
                $data['descripcion'] ?? null,
                $data['peso'] ?? 0,
                $data['version'] ?? 1,
                $data['activo'] ?? 1,
                $data['publicada'] ?? 0,
                $data['created_by'] ?? null,
            ]);

            return (int) $conn->getPdo()->lastInsertId();
        });
    }

    public function actualizar(int $evaluacionId, array $data)
    {
        $fields = [];
        $params = [];

        $map = [
            'nombre',
            'tipo_param_id',
            'tiempo_minutos',
            'puntaje_aprobacion',
            'descripcion',
            'peso',
            'publicada',
            'activo',
            'version',
        ];

        foreach ($map as $campo) {
            if (array_key_exists($campo, $data)) {
                $fields[] = "$campo = ?";
                $params[] = $data[$campo];
            }
        }

        if (empty($fields)) {
            return true;
        }

        $fields[] = "updated_at = NOW()";

        $sql = "
            UPDATE evaluacion
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ";

        $params[] = $evaluacionId;

        return DbSafe::statement('mysql_cursos', $sql, $params);
    }

    public function guardarRubricaTrabajo(int $evaluacionId, array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($evaluacionId, $data) {
            $conn = DB::connection('mysql_cursos');

            return $conn->transaction(function () use ($conn, $evaluacionId, $data) {
                $createdBy = $data['created_by'] ?? null;
                $updatedBy = $data['updated_by'] ?? $createdBy;

                $this->actualizar($evaluacionId, [
                    'peso' => $data['peso'] ?? null,
                    'puntaje_aprobacion' => $data['puntaje_aprobacion'] ?? null,
                    'nombre' => $data['nombre'] ?? null,
                    'descripcion' => $data['descripcion'] ?? null,
                ]);

                $rubricasActuales = $conn->select("
                    SELECT rubrica_id
                    FROM evaluacion_trabajo_rubrica
                    WHERE evaluacion_id = ?
                ", [$evaluacionId]);

                foreach ($rubricasActuales as $rubricaActual) {
                    $conn->delete("
                        DELETE FROM evaluacion_trabajo_rubrica_criterio
                        WHERE rubrica_id = ?
                    ", [$rubricaActual->rubrica_id]);
                }

                $conn->delete("
                    DELETE FROM evaluacion_trabajo_rubrica
                    WHERE evaluacion_id = ?
                ", [$evaluacionId]);

                $rubrica = $data['rubrica'] ?? null;

                if ($rubrica && !empty($rubrica['nombre'])) {
                    $conn->insert("
                        INSERT INTO evaluacion_trabajo_rubrica (
                            evaluacion_id,
                            nombre,
                            orden,
                            created_at,
                            created_by,
                            updated_at,
                            updated_by
                        ) VALUES (
                            ?, ?, ?, NOW(), ?, NOW(), ?
                        )
                    ", [
                        $evaluacionId,
                        $rubrica['nombre'],
                        $rubrica['orden'] ?? 1,
                        $createdBy,
                        $updatedBy
                    ]);

                    $rubricaId = (int) $conn->getPdo()->lastInsertId();

                    foreach ($rubrica['criterios'] ?? [] as $index => $criterio) {
                        if (empty($criterio['descripcion'])) {
                            continue;
                        }

                        $conn->insert("
                            INSERT INTO evaluacion_trabajo_rubrica_criterio (
                                rubrica_id,
                                nombre,
                                descripcion,
                                puntaje_max,
                                orden,
                                created_at,
                                created_by,
                                updated_at,
                                updated_by
                            ) VALUES (
                                ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?
                            )
                        ", [
                            $rubricaId,
                            $criterio['nombre'],
                            $criterio['descripcion'],
                            $criterio['puntaje_max'] ?? 0,
                            $criterio['orden'] ?? ($index + 1),
                            $createdBy,
                            $updatedBy
                        ]);
                    }
                }

                return $this->obtenerConRubrica($evaluacionId, $conn);
            });
        });
    }

    public function guardarTrabajo(array $data)
    {
        $evaluacionId = (int) ($data['evaluacion_id'] ?? 0);

        return $this->guardarRubricaTrabajo($evaluacionId, $data);
    }

    public function obtenerConRubrica(int $evaluacionId, $conn = null)
    {
        $evaluacion = $this->obtener($evaluacionId);

        if (!$evaluacion) {
            return null;
        }

        $conn = $conn ?: DB::connection('mysql_cursos');

        $rubrica = $conn->selectOne("
            SELECT
                rubrica_id,
                evaluacion_id,
                nombre,
                orden,
                created_at,
                created_by,
                updated_at,
                updated_by
            FROM evaluacion_trabajo_rubrica
            WHERE evaluacion_id = ?
            ORDER BY orden, rubrica_id
            LIMIT 1
        ", [$evaluacionId]);

        $evaluacionData = (array) $evaluacion;

        if ($rubrica) {
            $criterios = $conn->select("
                SELECT
                    criterio_id,
                    rubrica_id,
                    nombre,
                    descripcion,
                    puntaje_max,
                    orden,
                    created_at,
                    created_by,
                    updated_at,
                    updated_by
                FROM evaluacion_trabajo_rubrica_criterio
                WHERE rubrica_id = ?
                ORDER BY orden, criterio_id
            ", [$rubrica->rubrica_id]);

            $rubricaData = (array) $rubrica;
            $rubricaData['criterios'] = array_map(function ($criterio) {
                return (array) $criterio;
            }, $criterios);

            $evaluacionData['rubrica'] = $rubricaData;
        } else {
            $evaluacionData['rubrica'] = null;
        }

        return $evaluacionData;
    }

    public function obtenerTrabajoPorEvaluacionId(int $evaluacionId)
    {
        $evaluacion = $this->obtenerConRubrica($evaluacionId);

        if (!$evaluacion) {
            return null;
        }

        $rubrica = $evaluacion['rubrica'] ?? null;
        $evaluacionIdValue = (int) ($evaluacion['id'] ?? $evaluacionId);
        $asignacion = DB::connection('mysql_cursos')->selectOne("
            SELECT fecha_limite
            FROM curso_edicion_sesion_evaluaciones
            WHERE evaluacion_id = ?
            ORDER BY id DESC
            LIMIT 1
        ", [$evaluacionIdValue]);
        $fechaLimite = $asignacion?->fecha_limite;
        $puntajeMaximo = round(array_sum(array_map(
            static fn (array $criterio): float => (float) ($criterio['puntaje_max'] ?? 0),
            $rubrica['criterios'] ?? []
        )), 2);

        $evaluacion['fecha_limite'] = $fechaLimite;
        $evaluacion['puntaje_max'] = $puntajeMaximo;

        return [
            'evaluacion' => $evaluacion,
            'trabajo' => [
                'trabajo_id' => $evaluacionIdValue,
                'evaluacion_id' => $evaluacionIdValue,
                'descripcion' => $evaluacion['descripcion'] ?? null,
                'fecha_limite' => $fechaLimite,
                'puntaje_max' => $puntajeMaximo,
                'rubrica' => $rubrica ? [
                    'rubrica_id' => $rubrica['rubrica_id'] ?? null,
                    'trabajo_id' => $evaluacionIdValue,
                    'nombre' => $rubrica['nombre'] ?? null,
                    'criterios' => $rubrica['criterios'] ?? [],
                ] : null,
            ],
        ];
    }

    public function listarPreguntas(int $evaluacionId)
    {
        return DbSafe::select('mysql_cursos', "
            SELECT *
            FROM evaluacion_pregunta
            WHERE evaluacion_id = ?
            ORDER BY orden
        ", [$evaluacionId]);
    }

    public function insertarPregunta(array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($data) {
            $conn = DB::connection('mysql_cursos');

            $conn->insert("
                INSERT INTO evaluacion_pregunta (
                    evaluacion_id,
                    tipo_param_id,
                    texto,
                    puntaje,
                    feedback,
                    orden,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $data['evaluacion_id'],
                $data['tipo_param_id'],
                $data['texto'],
                $data['puntaje'],
                $data['feedback'] ?? null,
                $data['orden']
            ]);

            return (int) $conn->getPdo()->lastInsertId();
        });
    }

    public function actualizarPregunta(int $preguntaId, array $data)
    {
        return DbSafe::statement('mysql_cursos', "
            UPDATE evaluacion_pregunta
            SET tipo_param_id = ?, texto = ?, puntaje = ?, feedback = ?, orden = ?, updated_at = NOW()
            WHERE pregunta_id = ?
        ", [
            $data['tipo_param_id'],
            $data['texto'],
            $data['puntaje'],
            $data['feedback'] ?? null,
            $data['orden'],
            $preguntaId
        ]);
    }

    public function eliminarPregunta(int $preguntaId)
    {
        return DbSafe::statement('mysql_cursos', "
            DELETE FROM evaluacion_pregunta
            WHERE pregunta_id = ?
        ", [$preguntaId]);
    }

    public function listarOpciones(int $preguntaId)
    {
        return DbSafe::select('mysql_cursos', "
            SELECT *
            FROM evaluacion_pregunta_opcion
            WHERE pregunta_id = ?
            ORDER BY orden
        ", [$preguntaId]);
    }

    public function insertarOpcion(array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($data) {
            $conn = DB::connection('mysql_cursos');

            $conn->insert("
                INSERT INTO evaluacion_pregunta_opcion (
                    pregunta_id,
                    texto,
                    es_correcta,
                    orden,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, NOW(), NOW())
            ", [
                $data['pregunta_id'],
                $data['texto'],
                $data['es_correcta'],
                $data['orden']
            ]);

            return (int) $conn->getPdo()->lastInsertId();
        });
    }

    public function actualizarOpcion(int $opcionId, array $data)
    {
        return DbSafe::statement('mysql_cursos', "
            UPDATE evaluacion_pregunta_opcion
            SET texto = ?, es_correcta = ?, orden = ?, updated_at = NOW()
            WHERE opcion_id = ?
        ", [
            $data['texto'],
            $data['es_correcta'],
            $data['orden'],
            $opcionId
        ]);
    }

    public function eliminarOpcion(int $opcionId)
    {
        return DbSafe::statement('mysql_cursos', "
            DELETE FROM evaluacion_pregunta_opcion
            WHERE opcion_id = ?
        ", [$opcionId]);
    }

    public function listarPublicadasPorCursoYTipo(int $cursoId, int $tipoId)
    {
        $sql = "
            SELECT
                e.id,
                e.nombre,
                e.tipo_param_id,
                p.desc_valor AS tipo_descripcion
            FROM curso_edicion ce
            INNER JOIN evaluacion e
                ON e.curso_id = ce.id
            LEFT JOIN parametros p
                ON p.id_valor = e.tipo_param_id
                AND p.id_maestro = 21
                AND p.flg_activo = 1
            WHERE ce.id = ?
            AND e.tipo_param_id = ?
            AND e.publicada = 1
            AND e.activo = 1
            ORDER BY e.nombre
        ";

        return DbSafe::select('mysql_cursos', $sql, [$cursoId, $tipoId]);
    }

    public function obtenerRespuestasCorrectas(int $evaluacionId)
    {
        return DbSafe::select('mysql_cursos', "
            SELECT 
                p.pregunta_id,
                p.puntaje,
                o.opcion_id
            FROM evaluacion_pregunta p
            JOIN evaluacion_pregunta_opcion o 
                ON o.pregunta_id = p.pregunta_id
            WHERE p.evaluacion_id = ?
            AND o.es_correcta = 1
        ", [$evaluacionId]);
    }

public function listarParticipantesEvaluacion(int $evaluacionId)
{
    $contextRows = DbSafe::select('mysql_cursos', "
        SELECT
            cse.id AS curso_sesion_evaluacion_id,
            ev.id AS evaluacion_id,
            ev.nombre AS evaluacion_nombre,
            ev.tipo_param_id,
            ce.curso,
            ce.edicion
        FROM curso_edicion_sesion_evaluaciones cse
        INNER JOIN curso_edicion_sesiones ces
            ON ces.id = cse.sesion_id
        INNER JOIN curso_edicion ce
            ON ce.id = ces.curso_edicion_id
        INNER JOIN evaluacion ev
            ON ev.id = cse.evaluacion_id
        WHERE cse.evaluacion_id = ?
          AND ce.activo = 1
          AND ce.estadocurso IN ('en curso', 'programado')
        ORDER BY cse.id DESC
        LIMIT 1
    ", [$evaluacionId]);

    $context = $contextRows[0] ?? null;

    if (!$context) {
        return [];
    }

    $sql = "
SELECT
    fi.id,
    fi.NOMBRES,
    fi.APELLIDOS,
    CONCAT(fi.NOMBRES, ' ', fi.APELLIDOS) AS alumno,
    fi.CORREO_PERSONAL,
    fi.TELEFONO,

    CASE
        WHEN er.id IS NOT NULL THEN 1
        ELSE 0
    END AS rindio,

    CASE
        WHEN er.estado = 'corregido' THEN 1
        WHEN er.estado = 'finalizado' THEN 1
        ELSE 0
    END AS corregido,

    er.id AS rendicion_id,
    er.estado AS rendicion_estado,

    CASE
        WHEN ? IN (3,4) THEN etc.puntaje_total
        ELSE er.puntaje_total
    END AS puntaje_total,

    er.fecha_fin,

    er.id AS entrega_id,
    er.estado AS entrega_estado,
    er.fecha_entrega,

    etc.id AS calificacion_id,
    etc.fecha_correccion

FROM Ficha_inscripcion fi

LEFT JOIN evaluacion_rendicion er
    ON er.evaluacion_id = ?
   AND er.alumno_correo COLLATE utf8mb4_unicode_ci =
       fi.CORREO_PERSONAL COLLATE utf8mb4_unicode_ci

LEFT JOIN evaluacion_rendicion_calificacion etc
    ON etc.rendicion_id = er.id

WHERE fi.CURSO = ?
  AND fi.grupo = ?
  AND TRIM(COALESCE(fi.CORREO_PERSONAL, '')) <> ''

ORDER BY fi.APELLIDOS, fi.NOMBRES
    ";

    $rows = DbSafe::select('mysql_cursos', $sql, [
        (int) $context->tipo_param_id,
        $evaluacionId,
        (string) $context->curso,
        (string) $context->edicion,
    ]);
    $participantsByEmail = [];

    foreach ($rows as $row) {
        $row->evaluacion_id = (int) $context->evaluacion_id;
        $row->curso_sesion_evaluacion_id = (int) $context->curso_sesion_evaluacion_id;
        $row->evaluacion_nombre = $context->evaluacion_nombre;
        $row->tipo_param_id = (int) $context->tipo_param_id;
        $emailKey = strtolower(trim((string) ($row->CORREO_PERSONAL ?? '')));

        if ($emailKey === '') {
            $emailKey = 'registration:' . (int) ($row->id ?? 0);
        }

        if (!isset($participantsByEmail[$emailKey])
            || (int) ($row->id ?? 0) > (int) ($participantsByEmail[$emailKey]->id ?? 0)) {
            $participantsByEmail[$emailKey] = $row;
        }
    }

    return array_values($participantsByEmail);
}
}
