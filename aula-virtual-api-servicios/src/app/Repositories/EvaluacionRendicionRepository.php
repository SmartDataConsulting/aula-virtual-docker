<?php

namespace App\Repositories;

use App\Helpers\DbSafe;
use Illuminate\Support\Facades\DB;

class EvaluacionRendicionRepository
{
    public function ejecutarTransaccion(callable $callback)
    {
        return DbSafe::execute('mysql_cursos', function () use ($callback) {
            $conn = DB::connection('mysql_cursos');

            return $conn->transaction(function () use ($callback, $conn) {
                return $callback($conn);
            });
        });
    }

    public function alumnoTieneAccesoTrabajo(int $evaluacionId, string $correo): bool
    {
        $row = DbSafe::select('mysql_cursos', "
            SELECT 1
            FROM evaluacion e
            INNER JOIN curso_edicion ce
                ON ce.id = e.curso_id
               AND ce.activo = 1
            INNER JOIN Ficha_inscripcion fi
                ON fi.curso_edicion_id = ce.id
                OR (fi.curso COLLATE utf8mb4_unicode_ci = ce.curso COLLATE utf8mb4_unicode_ci
                AND fi.grupo COLLATE utf8mb4_unicode_ci = ce.edicion COLLATE utf8mb4_unicode_ci)
            WHERE e.id = ?
            AND LOWER(TRIM(COALESCE(NULLIF(fi.CORREO_PERSONAL, ''), fi.correo_corporativo))) = LOWER(TRIM(?))
            LIMIT 1
        ", [$evaluacionId, $correo]);

        return !empty($row);
    }

    public function obtener(int $rendicionId)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                ri.*
            FROM evaluacion_rendicion ri
            WHERE ri.id = ?
            LIMIT 1
        ", [$rendicionId]);

        return $rows[0] ?? null;
    }

    public function obtenerEnProgreso(int $evaluacionId, string $alumnoCorreo)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                ri.*
            FROM evaluacion_rendicion ri
            WHERE ri.evaluacion_id = ?
            AND ri.alumno_correo = ?
            AND ri.estado = 'en_progreso'
            ORDER BY ri.id DESC
            LIMIT 1
        ", [$evaluacionId, $alumnoCorreo]);

        return $rows[0] ?? null;
    }

    public function obtenerUltimaPorAlumno(int $evaluacionId, string $alumnoCorreo)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                ri.*
            FROM evaluacion_rendicion ri
            WHERE ri.evaluacion_id = ?
            AND ri.alumno_correo = ?
            ORDER BY ri.id DESC
            LIMIT 1
        ", [$evaluacionId, $alumnoCorreo]);

        return $rows[0] ?? null;
    }

    public function obtenerUltimaRendicionPorAlumno(int $evaluacionId, string $correo): ?array
    {
        $rendicion = $this->obtenerUltimaPorAlumno($evaluacionId, $correo);

        return $rendicion ? (array) $rendicion : null;
    }

    public function insertar(array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($data) {
            $conn = DB::connection('mysql_cursos');

            $conn->insert("
                INSERT INTO evaluacion_rendicion (
                    evaluacion_id,
                    alumno_correo,
                    estado,
                    fecha_inicio,
                    fecha_fin,
                    puntaje_total,
                    aprobado,
                    created_at,
                    updated_at
                ) VALUES (
                    ?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW()
                )
            ", [
                $data['evaluacion_id'],
                $data['alumno_correo'],
                $data['estado'] ?? 'en_progreso',
                $data['fecha_fin'] ?? null,
                $data['puntaje_total'] ?? null,
                $data['aprobado'] ?? null,
            ]);

            return (int) $conn->getPdo()->lastInsertId();
        });
    }

    public function finalizar(int $rendicionId, array $data)
    {
        return DbSafe::statement('mysql_cursos', "
            UPDATE evaluacion_rendicion
            SET
                estado = 'finalizado',
                fecha_fin = NOW(),
                puntaje_total = ?,
                aprobado = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [
            $data['puntaje_total'] ?? 0,
            $data['aprobado'] ?? 0,
            $rendicionId
        ]);
    }

    public function insertarRendicionFinalizadaSubsanacion(array $data, $conn = null): int
    {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $conn->insert("
            INSERT INTO evaluacion_rendicion (
                evaluacion_id,
                alumno_correo,
                estado,
                fecha_inicio,
                fecha_fin,
                puntaje_total,
                aprobado,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, 'finalizado', NOW(), NOW(), ?, ?, NOW(), NOW()
            )
        ", [
            $data['evaluacion_id'],
            $data['alumno_correo'],
            $data['puntaje_total'] ?? 0,
            $data['aprobado'] ?? 0,
        ]);

        return (int) $conn->getPdo()->lastInsertId();
    }

    public function actualizarRendicionFinalizadaSubsanacion(
        int $rendicionId,
        array $data,
        $conn = null
    ): void {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $conn->update("
            UPDATE evaluacion_rendicion
            SET
                estado = 'finalizado',
                fecha_inicio = COALESCE(fecha_inicio, NOW()),
                fecha_fin = NOW(),
                puntaje_total = ?,
                aprobado = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [
            $data['puntaje_total'] ?? 0,
            $data['aprobado'] ?? 0,
            $rendicionId,
        ]);
    }

    public function guardarRespuesta(array $data)
    {
        return DbSafe::statement('mysql_cursos', "
            INSERT INTO evaluacion_rendicion_respuesta (
                rendicion_id,
                pregunta_id,
                opcion_id,
                es_correcta,
                puntaje_obtenido,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                opcion_id = VALUES(opcion_id),
                es_correcta = VALUES(es_correcta),
                puntaje_obtenido = VALUES(puntaje_obtenido),
                updated_at = NOW()
        ", [
            $data['rendicion_id'],
            $data['pregunta_id'],
            $data['opcion_id'] ?? null,
            $data['es_correcta'] ?? null,
            $data['puntaje_obtenido'] ?? 0,
        ]);
    }

    public function listarRespuestas(int $rendicionId)
    {
        return DbSafe::select('mysql_cursos', "
            SELECT
                rir.*
            FROM evaluacion_rendicion_respuesta rir
            WHERE rir.rendicion_id = ?
            ORDER BY rir.id
        ", [$rendicionId]);
    }

    public function obtenerRespuesta(int $rendicionId, int $preguntaId)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                rir.*
            FROM evaluacion_rendicion_respuesta rir
            WHERE rir.rendicion_id = ?
            AND rir.pregunta_id = ?
            LIMIT 1
        ", [$rendicionId, $preguntaId]);

        return $rows[0] ?? null;
    }

    public function contarRespondidas(int $rendicionId): int
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT COUNT(*) AS total
            FROM evaluacion_rendicion_respuesta
            WHERE rendicion_id = ?
            AND opcion_id IS NOT NULL
        ", [$rendicionId]);

        return (int) ($rows[0]->total ?? 0);
    }

    public function sumarPuntaje(int $rendicionId): float
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT COALESCE(SUM(puntaje_obtenido), 0) AS total
            FROM evaluacion_rendicion_respuesta
            WHERE rendicion_id = ?
        ", [$rendicionId]);

        return (float) ($rows[0]->total ?? 0);
    }

    public function obtenerConRespuestas(int $rendicionId): ?array
    {
        $rendicion = $this->obtener($rendicionId);

        if (!$rendicion) {
            return null;
        }

        return [
            'rendicion' => (array) $rendicion,
            'respuestas' => array_map(function ($row) {
                return (array) $row;
            }, $this->listarRespuestas($rendicionId)),
        ];
    }

    public function obtenerEntregaTrabajoPorEvaluacionYCorreo(
        int $evaluacionId,
        string $correo,
        $conn = null
    ): ?array {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $entrega = $conn->selectOne("
            SELECT
                e.id AS entrega_id,
                e.evaluacion_id,
                e.alumno_correo,
                e.estado,
                e.fecha_entrega,
                e.observacion_alumno,
                tc.id AS calificacion_id,
                tc.puntaje_total,
                tc.aprobado,
                tc.observacion_docente,
                tc.fecha_correccion,
                e.created_at,
                e.updated_at
            FROM evaluacion_rendicion e
            LEFT JOIN evaluacion_rendicion_calificacion tc
                ON tc.rendicion_id = e.id
            WHERE e.evaluacion_id = ?
            AND e.alumno_correo = ?
            ORDER BY e.id DESC
            LIMIT 1
        ", [$evaluacionId, $correo]);

        if (!$entrega) {
            return null;
        }

        $archivos = $conn->select("
            SELECT
                archivo_id,
                rendicion_id AS entrega_id,
                nombre_original,
                ruta_archivo,
                peso_bytes,
                mime_type,
                activo,
                created_at,
                updated_at
            FROM evaluacion_rendicion_trabajo
            WHERE rendicion_id = ?
            AND activo = 1
            ORDER BY archivo_id
        ", [$entrega->entrega_id]);

        $entregaData = (array) $entrega;
        $entregaData['archivos'] = array_map(function ($archivo) {
            return (array) $archivo;
        }, $archivos);

        return $entregaData;
    }

    public function obtenerEntregaTrabajoPorId(
        int $evaluacionId,
        int $entregaId,
        $conn = null
    ): ?array {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $entrega = $conn->selectOne("
            SELECT
                e.id AS entrega_id,
                e.evaluacion_id,
                e.alumno_correo,
                e.estado,
                e.fecha_entrega,
                e.observacion_alumno,
                e.created_at,
                e.updated_at,
                tc.id AS calificacion_id,
                tc.puntaje_total,
                tc.aprobado,
                tc.observacion_docente,
                tc.fecha_correccion
            FROM evaluacion_rendicion e
            LEFT JOIN evaluacion_rendicion_calificacion tc
                ON tc.rendicion_id = e.id
            WHERE e.evaluacion_id = ?
            AND e.id = ?
            LIMIT 1
        ", [$evaluacionId, $entregaId]);

        if (!$entrega) {
            return null;
        }

        $archivos = $conn->select("
            SELECT
                archivo_id,
                rendicion_id AS entrega_id,
                nombre_original,
                ruta_archivo,
                peso_bytes,
                mime_type,
                activo,
                created_at,
                updated_at
            FROM evaluacion_rendicion_trabajo
            WHERE rendicion_id = ?
            AND activo = 1
            ORDER BY archivo_id
        ", [$entregaId]);

        $entregaData = (array) $entrega;
        $entregaData['archivos'] = array_map(function ($archivo) {
            return (array) $archivo;
        }, $archivos);

        return $entregaData;
    }

    public function crearEntregaTrabajoSubsanacion(
        int $evaluacionId,
        string $correo,
        $conn = null
    ): int {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $conn->insert("
            INSERT INTO evaluacion_rendicion (
                evaluacion_id,
                alumno_correo,
                estado,
                fecha_inicio,
                fecha_fin,
                fecha_entrega,
                observacion_alumno,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, 'corregido', NOW(), NOW(), NOW(), NULL, NOW(), NOW()
            )
        ", [$evaluacionId, $correo]);

        return (int) $conn->getPdo()->lastInsertId();
    }

    public function obtenerRubricaEntrega(
        int $evaluacionId,
        int $entregaId,
        $conn = null
    ): ?array {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $entrega = $conn->selectOne("
            SELECT
                e.id AS entrega_id,
                e.evaluacion_id,
                e.alumno_correo,
                tc.id AS calificacion_id,
                tc.puntaje_total,
                tc.aprobado,
                tc.observacion_docente,
                tc.fecha_correccion
            FROM evaluacion_rendicion e
            LEFT JOIN evaluacion_rendicion_calificacion tc
                ON tc.rendicion_id = e.id
            WHERE e.evaluacion_id = ?
            AND e.id = ?
            LIMIT 1
        ", [$evaluacionId, $entregaId]);

        if (!$entrega) {
            return null;
        }

        $rubrica = $conn->selectOne("
            SELECT
                rubrica_id,
                evaluacion_id,
                nombre,
                orden
            FROM evaluacion_trabajo_rubrica
            WHERE evaluacion_id = ?
            ORDER BY orden, rubrica_id
            LIMIT 1
        ", [$evaluacionId]);

        $rubricaData = [
            'entrega_id' => (int) $entrega->entrega_id,
            'evaluacion_id' => (int) $entrega->evaluacion_id,
            'alumno_correo' => $entrega->alumno_correo,
            'calificacion_id' => isset($entrega->calificacion_id)
                ? (int) $entrega->calificacion_id
                : null,
            'puntaje_total' => isset($entrega->puntaje_total)
                ? (float) $entrega->puntaje_total
                : null,
            'aprobado' => isset($entrega->aprobado)
                ? (int) $entrega->aprobado
                : null,
            'observacion_docente' => $entrega->observacion_docente,
            'fecha_correccion' => $entrega->fecha_correccion,
            'rubrica_id' => $rubrica ? (int) $rubrica->rubrica_id : null,
            'nombre' => $rubrica->nombre ?? null,
            'criterios' => [],
        ];

        if (!$rubrica) {
            return $rubricaData;
        }

        $criterios = $conn->select("
            SELECT
                rc.criterio_id,
                rc.rubrica_id,
                rc.nombre,
                rc.descripcion,
                rc.puntaje_max,
                rc.orden,
                cd.id AS detalle_id,
                cd.puntaje_obtenido,
                cd.comentario
            FROM evaluacion_trabajo_rubrica_criterio rc
            LEFT JOIN evaluacion_rendicion_calificacion_detalle cd
                ON cd.criterio_id = rc.criterio_id
                AND cd.calificacion_id = ?
            WHERE rc.rubrica_id = ?
            ORDER BY rc.orden, rc.criterio_id
        ", [
            $entrega->calificacion_id,
            $rubrica->rubrica_id,
        ]);

        $rubricaData['criterios'] = array_map(function ($criterio) {
            return [
                'criterio_id' => (int) $criterio->criterio_id,
                'rubrica_id' => (int) $criterio->rubrica_id,
                'nombre' => $criterio->nombre,
                'descripcion' => $criterio->descripcion,
                'puntaje_max' => isset($criterio->puntaje_max)
                    ? (float) $criterio->puntaje_max
                    : null,
                'orden' => isset($criterio->orden) ? (int) $criterio->orden : null,
                'detalle_id' => isset($criterio->detalle_id)
                    ? (int) $criterio->detalle_id
                    : null,
                'puntaje_obtenido' => isset($criterio->puntaje_obtenido)
                    ? (float) $criterio->puntaje_obtenido
                    : null,
                'comentario' => $criterio->comentario,
            ];
        }, $criterios);

        return $rubricaData;
    }

    public function guardarDetalleRevision(
        int $evaluacionId,
        int $entregaId,
        array $data
    ): array {
        return DbSafe::execute('mysql_cursos', function () use ($evaluacionId, $entregaId, $data) {
            $conn = DB::connection('mysql_cursos');

            return $conn->transaction(function () use ($conn, $evaluacionId, $entregaId, $data) {
                $calificacion = $conn->selectOne("
                    SELECT id AS calificacion_id
                    FROM evaluacion_rendicion_calificacion
                    WHERE rendicion_id = ?
                    LIMIT 1
                ", [$entregaId]);

                if (!$calificacion) {
                    $conn->insert("
                        INSERT INTO evaluacion_rendicion_calificacion (
                            rendicion_id,
                            usuario_id,
                            puntaje_total,
                            aprobado,
                            observacion_docente,
                            fecha_correccion,
                            created_at,
                            updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, NOW(), NOW(), NOW()
                        )
                    ", [
                        $entregaId,
                        $data['usuario_id'],
                        $data['puntaje_total'] ?? 0,
                        $data['aprobado'] ?? 0,
                        $data['observacion_docente'] ?? null,
                    ]);

                    $calificacionId = (int) $conn->getPdo()->lastInsertId();
                } else {
                    $calificacionId = (int) $calificacion->calificacion_id;

                    $conn->update("
                        UPDATE evaluacion_rendicion_calificacion
                        SET
                            usuario_id = ?,
                            puntaje_total = ?,
                            aprobado = ?,
                            observacion_docente = ?,
                            fecha_correccion = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ", [
                        $data['usuario_id'],
                        $data['puntaje_total'] ?? 0,
                        $data['aprobado'] ?? 0,
                        $data['observacion_docente'] ?? null,
                        $calificacionId,
                    ]);
                }

                $conn->delete("
                    DELETE FROM evaluacion_rendicion_calificacion_detalle
                    WHERE calificacion_id = ?
                ", [$calificacionId]);

                foreach ($data['criterios'] ?? [] as $criterio) {
                    $conn->insert("
                        INSERT INTO evaluacion_rendicion_calificacion_detalle (
                            calificacion_id,
                            criterio_id,
                            puntaje_obtenido,
                            comentario,
                            created_at,
                            updated_at
                        ) VALUES (
                            ?, ?, ?, ?, NOW(), NOW()
                        )
                    ", [
                        $calificacionId,
                        $criterio['criterio_id'],
                        $criterio['puntaje_obtenido'] ?? 0,
                        $criterio['comentario'] ?? null,
                    ]);
                }

                $this->marcarTrabajoCorregido($entregaId, $conn);

                return $this->obtenerRubricaEntrega($evaluacionId, $entregaId, $conn);
            });
        });
    }

    public function guardarCalificacionTrabajoSubsanacion(
        int $evaluacionId,
        int $entregaId,
        array $data,
        $conn = null
    ): array {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $calificacion = $conn->selectOne("
            SELECT id AS calificacion_id
            FROM evaluacion_rendicion_calificacion
            WHERE rendicion_id = ?
            LIMIT 1
        ", [$entregaId]);

        if (!$calificacion) {
            $conn->insert("
                INSERT INTO evaluacion_rendicion_calificacion (
                    rendicion_id,
                    usuario_id,
                    puntaje_total,
                    aprobado,
                    observacion_docente,
                    fecha_correccion,
                    created_at,
                    updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, NOW(), NOW(), NOW()
                )
            ", [
                $entregaId,
                $data['usuario_id'],
                $data['puntaje_total'] ?? 0,
                $data['aprobado'] ?? 0,
                $data['observacion_docente'] ?? null,
            ]);

            $calificacionId = (int) $conn->getPdo()->lastInsertId();
        } else {
            $calificacionId = (int) $calificacion->calificacion_id;

            $conn->update("
                UPDATE evaluacion_rendicion_calificacion
                SET
                    usuario_id = ?,
                    puntaje_total = ?,
                    aprobado = ?,
                    observacion_docente = ?,
                    fecha_correccion = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ", [
                $data['usuario_id'],
                $data['puntaje_total'] ?? 0,
                $data['aprobado'] ?? 0,
                $data['observacion_docente'] ?? null,
                $calificacionId,
            ]);

            // La subsanacion reemplaza la nota vigente; si no llegan criterios, limpiamos el detalle previo.
            $conn->delete("
                DELETE FROM evaluacion_rendicion_calificacion_detalle
                WHERE calificacion_id = ?
            ", [$calificacionId]);
        }

        foreach ($data['criterios'] ?? [] as $criterio) {
            $conn->insert("
                INSERT INTO evaluacion_rendicion_calificacion_detalle (
                    calificacion_id,
                    criterio_id,
                    puntaje_obtenido,
                    comentario,
                    created_at,
                    updated_at
                ) VALUES (
                    ?, ?, ?, ?, NOW(), NOW()
                )
            ", [
                $calificacionId,
                $criterio['criterio_id'],
                $criterio['puntaje_obtenido'] ?? 0,
                $criterio['comentario'] ?? null,
            ]);
        }

        $this->marcarTrabajoCorregido($entregaId, $conn);

        return $this->obtenerRubricaEntrega($evaluacionId, $entregaId, $conn) ?? [];
    }

    public function obtenerCalificacionTrabajoPorId(int $calificacionId, $conn = null): ?array
    {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $row = $conn->selectOne("
            SELECT
                c.*,
                c.id AS calificacion_id,
                c.rendicion_id AS entrega_id,
                r.evaluacion_id,
                r.alumno_correo
            FROM evaluacion_rendicion_calificacion c
            INNER JOIN evaluacion_rendicion r
                ON r.id = c.rendicion_id
            WHERE c.id = ?
            LIMIT 1
        ", [$calificacionId]);

        return $row ? (array) $row : null;
    }

    public function actualizarCalificacionTrabajoSubsanacion(
        int $calificacionId,
        array $data,
        bool $actualizarCriterios,
        $conn = null
    ): void {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $fields = ['updated_at = NOW()'];
        $params = [];

        if (array_key_exists('usuario_id', $data)) {
            $fields[] = 'usuario_id = ?';
            $params[] = $data['usuario_id'];
        }

        if (array_key_exists('puntaje_total', $data)) {
            $fields[] = 'puntaje_total = ?';
            $params[] = $data['puntaje_total'];
        }

        if (array_key_exists('aprobado', $data)) {
            $fields[] = 'aprobado = ?';
            $params[] = $data['aprobado'];
        }

        if (array_key_exists('observacion_docente', $data)) {
            $fields[] = 'observacion_docente = ?';
            $params[] = $data['observacion_docente'];
        }

        if (count($fields) > 1) {
            $fields[] = 'fecha_correccion = NOW()';
        }

        $params[] = $calificacionId;

        $conn->update("
            UPDATE evaluacion_rendicion_calificacion
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ", $params);

        if ($actualizarCriterios) {
            $conn->delete("
                DELETE FROM evaluacion_rendicion_calificacion_detalle
                WHERE calificacion_id = ?
            ", [$calificacionId]);

            foreach ($data['criterios'] ?? [] as $criterio) {
                $conn->insert("
                    INSERT INTO evaluacion_rendicion_calificacion_detalle (
                        calificacion_id,
                        criterio_id,
                        puntaje_obtenido,
                        comentario,
                        created_at,
                        updated_at
                    ) VALUES (
                        ?, ?, ?, ?, NOW(), NOW()
                    )
                ", [
                    $calificacionId,
                    $criterio['criterio_id'],
                    $criterio['puntaje_obtenido'] ?? 0,
                    $criterio['comentario'] ?? null,
                ]);
            }
        }

        if (count($fields) > 1 || $actualizarCriterios) {
            $conn->update("
                UPDATE evaluacion_rendicion r
                INNER JOIN evaluacion_rendicion_calificacion c
                    ON c.rendicion_id = r.id
                SET
                    r.estado = 'corregido',
                    r.fecha_fin = NOW(),
                    r.updated_at = NOW()
                WHERE c.id = ?
            ", [$calificacionId]);
        }
    }

    private function marcarTrabajoCorregido(int $rendicionId, $conn): void
    {
        $conn->update("
            UPDATE evaluacion_rendicion
            SET
                estado = 'corregido',
                fecha_fin = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ", [$rendicionId]);
    }

    public function guardarEntregaAlumno(
        int $evaluacionId,
        string $correo,
        array $payload,
        array $nuevosArchivos,
        array $archivoIdsEliminar,
        bool $finalizar
    ): array {
        return DbSafe::execute('mysql_cursos', function () use (
            $evaluacionId,
            $correo,
            $payload,
            $nuevosArchivos,
            $archivoIdsEliminar,
            $finalizar
        ) {
            $conn = DB::connection('mysql_cursos');

            return $conn->transaction(function () use (
                $conn,
                $evaluacionId,
                $correo,
                $payload,
                $nuevosArchivos,
                $archivoIdsEliminar,
                $finalizar
            ) {
                $entrega = $conn->selectOne("
                    SELECT id AS entrega_id, estado
                    FROM evaluacion_rendicion
                    WHERE evaluacion_id = ?
                    AND alumno_correo = ?
                    ORDER BY id DESC
                    LIMIT 1
                ", [$evaluacionId, $correo]);

                if (!$entrega) {
                    $conn->insert("
                        INSERT INTO evaluacion_rendicion (
                            evaluacion_id,
                            alumno_correo,
                            estado,
                            fecha_inicio,
                            fecha_entrega,
                            observacion_alumno,
                            created_at,
                            updated_at
                        ) VALUES (
                            ?, ?, 'en_progreso', NOW(), NULL, ?, NOW(), NOW()
                        )
                    ", [
                        $evaluacionId,
                        $correo,
                        $payload['observacion_alumno'] ?? null,
                    ]);

                    $entregaId = (int) $conn->getPdo()->lastInsertId();
                } else {
                    $entregaId = (int) $entrega->entrega_id;
                }

                $archivosEliminados = [];

                if (!empty($archivoIdsEliminar)) {
                    $placeholders = implode(',', array_fill(0, count($archivoIdsEliminar), '?'));

                    $archivosEliminados = $conn->select("
                        SELECT archivo_id, ruta_archivo
                        FROM evaluacion_rendicion_trabajo
                        WHERE rendicion_id = ?
                        AND activo = 1
                        AND archivo_id IN ($placeholders)
                    ", array_merge([$entregaId], $archivoIdsEliminar));

                    if (!empty($archivosEliminados)) {
                        $conn->update("
                            UPDATE evaluacion_rendicion_trabajo
                            SET activo = 0, updated_at = NOW()
                            WHERE rendicion_id = ?
                            AND archivo_id IN ($placeholders)
                        ", array_merge([$entregaId], $archivoIdsEliminar));
                    }
                }

                foreach ($nuevosArchivos as $archivo) {
                    $conn->insert("
                        INSERT INTO evaluacion_rendicion_trabajo (
                            rendicion_id,
                            nombre_original,
                            ruta_archivo,
                            peso_bytes,
                            mime_type,
                            activo,
                            created_at,
                            updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, 1, NOW(), NOW()
                        )
                    ", [
                        $entregaId,
                        $archivo['nombre_original'],
                        $archivo['ruta_archivo'],
                        $archivo['peso_bytes'],
                        $archivo['mime_type'],
                    ]);
                }

                $fields = [
                    'estado = ?',
                    'observacion_alumno = ?',
                    'updated_at = NOW()',
                ];

                $params = [
                    $finalizar ? 'entregado' : 'en_progreso',
                    $payload['observacion_alumno'] ?? null,
                ];

                if ($finalizar) {
                    $fields[] = 'fecha_entrega = NOW()';
                }

                $params[] = $entregaId;

                $conn->update("
                    UPDATE evaluacion_rendicion
                    SET " . implode(', ', $fields) . "
                    WHERE id = ?
                ", $params);

                return [
                    'entrega' => $this->obtenerEntregaTrabajoPorEvaluacionYCorreo(
                        $evaluacionId,
                        $correo,
                        $conn
                    ),
                    'archivos_eliminados' => array_map(function ($archivo) {
                        return (array) $archivo;
                    }, $archivosEliminados),
                ];
            });
        });
    }

    public function obtenerArchivoEntregaPorIdYCorreo(int $archivoId, string $correo)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                a.archivo_id,
                a.rendicion_id AS entrega_id,
                a.nombre_original,
                a.ruta_archivo,
                a.peso_bytes,
                a.mime_type,
                e.evaluacion_id,
                e.alumno_correo,
                e.estado
            FROM evaluacion_rendicion_trabajo a
            INNER JOIN evaluacion_rendicion e
                ON e.id = a.rendicion_id
            WHERE a.archivo_id = ?
            AND a.activo = 1
            AND e.alumno_correo = ?
            LIMIT 1
        ", [$archivoId, $correo]);

        return $rows[0] ?? null;
    }

    public function insertarSubsanacion(array $data, $conn = null): int
    {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $conn->insert("
            INSERT INTO evaluacion_subsanacion (
                evaluacion_id,
                rendicion_id,
                calificacion_id,
                motivo,
                observacion,
                evidencia_archivo,
                usuario_id,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ", [
            $data['evaluacion_id'],
            $data['rendicion_id'] ?? null,
            $data['calificacion_id'] ?? null,
            $data['motivo'] ?? null,
            $data['observacion'] ?? null,
            $data['evidencia_archivo'] ?? null,
            $data['usuario_id'],
        ]);

        return (int) $conn->getPdo()->lastInsertId();
    }

    public function obtenerSubsanacionPorId(int $subsanacionId, $conn = null): ?array
    {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $row = $conn->selectOne("
            SELECT
                s.*,
                s.id AS subsanacion_id
            FROM evaluacion_subsanacion s
            WHERE s.id = ?
            LIMIT 1
        ", [$subsanacionId]);

        return $row ? (array) $row : null;
    }

    public function actualizarSubsanacion(int $subsanacionId, array $data, $conn = null): void
    {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $fields = ['updated_at = NOW()'];
        $params = [];

        if (array_key_exists('motivo', $data)) {
            $fields[] = 'motivo = ?';
            $params[] = $data['motivo'];
        }

        if (array_key_exists('observacion', $data)) {
            $fields[] = 'observacion = ?';
            $params[] = $data['observacion'];
        }

        if (array_key_exists('evidencia_archivo', $data)) {
            $fields[] = 'evidencia_archivo = ?';
            $params[] = $data['evidencia_archivo'];
        }

        if (array_key_exists('usuario_id', $data)) {
            $fields[] = 'usuario_id = ?';
            $params[] = $data['usuario_id'];
        }

        $params[] = $subsanacionId;

        $conn->update("
            UPDATE evaluacion_subsanacion
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ", $params);
    }

    public function obtenerSubsanacionPorRendicion(int $rendicionId, $conn = null): ?array
    {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $row = $conn->selectOne("
            SELECT
                s.*,
                s.id AS subsanacion_id
            FROM evaluacion_subsanacion s
            WHERE s.rendicion_id = ?
            ORDER BY s.id DESC
            LIMIT 1
        ", [$rendicionId]);

        return $row ? (array) $row : null;
    }

    public function obtenerSubsanacionPorCalificacion(int $calificacionId, $conn = null): ?array
    {
        $conn = $conn ?: DB::connection('mysql_cursos');

        $row = $conn->selectOne("
            SELECT
                s.*,
                s.id AS subsanacion_id
            FROM evaluacion_subsanacion s
            WHERE s.calificacion_id = ?
            ORDER BY s.id DESC
            LIMIT 1
        ", [$calificacionId]);

        return $row ? (array) $row : null;
    }

    public function listarSubsanacionesPorEvaluacion(int $evaluacionId): array
    {
        return array_map(function ($row) {
            return (array) $row;
        }, DbSafe::select('mysql_cursos', "
            SELECT
                s.id AS subsanacion_id,
                s.evaluacion_id,
                s.rendicion_id,
                s.calificacion_id,
                s.motivo,
                s.observacion,
                s.evidencia_archivo,
                s.usuario_id,
                s.created_at,
                s.updated_at,
                CASE
                    WHEN s.rendicion_id IS NOT NULL THEN 'examen'
                    ELSE 'trabajo'
                END AS tipo_subsanacion,
                r.alumno_correo AS examen_alumno_correo,
                r.puntaje_total AS examen_puntaje_total,
                r.aprobado AS examen_aprobado,
                r.fecha_fin AS examen_fecha_fin,
                tc.puntaje_total AS trabajo_puntaje_total,
                tc.aprobado AS trabajo_aprobado,
                tc.fecha_correccion AS trabajo_fecha_correccion,
                e.alumno_correo AS trabajo_alumno_correo,
                e.id AS entrega_id
            FROM evaluacion_subsanacion s
            LEFT JOIN evaluacion_rendicion r
                ON r.id = s.rendicion_id
            LEFT JOIN evaluacion_rendicion_calificacion tc
                ON tc.id = s.calificacion_id
            LEFT JOIN evaluacion_rendicion e
                ON e.id = tc.rendicion_id
            WHERE s.evaluacion_id = ?
            ORDER BY s.id DESC
        ", [$evaluacionId]));
    }

     public function listarNotasCabeceraAlumno(
    int $cursoId,
    string $correo
    ): array {
        $sql = "
            SELECT
                e.id AS evaluacion_id,
                e.nombre AS evaluacion,
                p.desc_valor AS tipo_evaluacion,

                COALESCE(
                    r.fecha_fin,
                    tc.fecha_correccion,
                    te.fecha_entrega,
                    cese.fecha_limite,
                    e.created_at
                ) AS fecha,

                CASE
                    WHEN e.tipo_param_id IN (1,2)
                        THEN r.puntaje_total
                    WHEN e.tipo_param_id IN (3,4)
                        THEN tc.puntaje_total
                    ELSE NULL
                END AS nota,

                e.peso

            FROM curso_edicion_sesion_evaluaciones cese

            INNER JOIN curso_edicion_sesiones s
                ON s.id = cese.sesion_id

            INNER JOIN evaluacion e
                ON e.id = cese.evaluacion_id

            LEFT JOIN parametros p
                ON p.id_maestro = 21
            AND p.id_valor = e.tipo_param_id
            AND p.flg_activo = 1

            LEFT JOIN (
                SELECT er1.*
                FROM evaluacion_rendicion er1
                INNER JOIN (
                    SELECT
                        evaluacion_id,
                        alumno_correo,
                        MAX(id) AS max_id
                    FROM evaluacion_rendicion
                    WHERE estado = 'finalizado'
                    GROUP BY evaluacion_id, alumno_correo
                ) ult
                    ON ult.max_id = er1.id
            ) r
                ON r.evaluacion_id = e.id
            AND r.alumno_correo COLLATE utf8mb4_unicode_ci =
                CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci

            LEFT JOIN evaluacion_rendicion te
                ON te.evaluacion_id = e.id
            AND te.alumno_correo COLLATE utf8mb4_unicode_ci =
                CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci
            AND te.estado = 'corregido'

            LEFT JOIN evaluacion_rendicion_calificacion tc
                ON tc.rendicion_id = te.id

            WHERE s.curso_edicion_id = ?
            AND e.activo = 1

            ORDER BY fecha ASC, e.id ASC
        ";

        return DbSafe::select(
            'mysql_cursos',
            $sql,
            [$correo, $correo, $cursoId]
        );
    }

    public function listarCriteriosTrabajoAlumno(
    int $cursoId,
    string $correo
    ): array {
        $sql = "
            SELECT
                e.id AS evaluacion_id,
                rc.criterio_id,
                rc.nombre AS criterio,
                rc.puntaje_max,

                d.puntaje_obtenido,
                d.comentario

            FROM curso_edicion_sesion_evaluaciones cese

            INNER JOIN curso_edicion_sesiones s
                ON s.id = cese.sesion_id

            INNER JOIN evaluacion e
                ON e.id = cese.evaluacion_id

            INNER JOIN evaluacion_trabajo_rubrica r
                ON r.evaluacion_id = e.id

            INNER JOIN evaluacion_trabajo_rubrica_criterio rc
                ON rc.rubrica_id = r.rubrica_id

            LEFT JOIN evaluacion_rendicion te
                ON te.evaluacion_id = e.id
            AND te.alumno_correo COLLATE utf8mb4_unicode_ci =
                CAST(? AS CHAR) COLLATE utf8mb4_unicode_ci
            AND te.estado = 'corregido'

            LEFT JOIN evaluacion_rendicion_calificacion tc
                ON tc.rendicion_id = te.id

            LEFT JOIN evaluacion_rendicion_calificacion_detalle d
                ON d.calificacion_id = tc.id
            AND d.criterio_id = rc.criterio_id

            WHERE s.curso_edicion_id = ?
            AND e.activo = 1
            AND e.tipo_param_id IN (3,4)

            ORDER BY
                e.id ASC,
                r.orden ASC,
                rc.orden ASC
        ";

        return DbSafe::select(
            'mysql_cursos',
            $sql,
            [$correo, $cursoId]
        );
    }

    public function obtenerArchivoEntregaPorId(int $archivoId)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                a.archivo_id,
                a.rendicion_id AS entrega_id,
                a.nombre_original,
                a.ruta_archivo,
                a.peso_bytes,
                a.mime_type,
                e.evaluacion_id,
                e.alumno_correo,
                e.estado
            FROM evaluacion_rendicion_trabajo a
            INNER JOIN evaluacion_rendicion e
                ON e.id = a.rendicion_id
            WHERE a.archivo_id = ?
            AND a.activo = 1
            LIMIT 1
        ", [$archivoId]);

        return $rows[0] ?? null;
    }

}
