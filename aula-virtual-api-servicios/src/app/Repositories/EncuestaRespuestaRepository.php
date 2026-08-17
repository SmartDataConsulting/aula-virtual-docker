<?php

namespace App\Repositories;

use App\Helpers\DbSafe;

class EncuestaRespuestaRepository
{
    /**
     * Verificar si el alumno ya respondió encuesta de sesión
     */
    public function alumnoYaRespondioEncuestaSesion(int $sesionId, string $alumnoHash, ?int $encuestaId = null)
    {
        $sql = "
            SELECT id
            FROM encuesta_respuesta
            WHERE curso_edicion_sesion_id = ?
              AND alumno_hash = ?
              " . ($encuestaId !== null ? 'AND encuesta_id = ?' : '') . "
            LIMIT 1
        ";

        $params = [$sesionId, $alumnoHash];
        if ($encuestaId !== null) {
            $params[] = $encuestaId;
        }

        $rows = DbSafe::select('mysql_cursos', $sql, $params);

        return !empty($rows);
    }

    /**
     * Verificar si el alumno ya respondió encuesta de curso
     */
    public function alumnoYaRespondioEncuestaCurso(int $encuestaId, int $sesionId, string $alumnoHash)
    {
        $sql = "
            SELECT er.id
            FROM encuesta_respuesta er
            INNER JOIN curso_edicion_sesiones sesion_actual
                ON sesion_actual.id = ?
            INNER JOIN curso_edicion_sesiones sesion_respuesta
                ON sesion_respuesta.id = er.curso_edicion_sesion_id
            WHERE er.encuesta_id = ?
              AND er.alumno_hash = ?
              AND sesion_respuesta.curso_edicion_id = sesion_actual.curso_edicion_id
            LIMIT 1
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [
            $sesionId,
            $encuestaId,
            $alumnoHash
        ]);

        return !empty($rows);
    }

    public function alumnoYaRespondioEncuestaCursoPorCurso(int $encuestaId, int $cursoEdicionId, string $alumnoHash): bool
    {
        $sql = "
            SELECT er.id
            FROM encuesta_respuesta er
            INNER JOIN curso_edicion_sesiones ces
                ON ces.id = er.curso_edicion_sesion_id
            WHERE er.encuesta_id = ?
              AND ces.curso_edicion_id = ?
              AND er.alumno_hash = ?
            LIMIT 1
        ";

        return !empty(DbSafe::select('mysql_cursos', $sql, [
            $encuestaId,
            $cursoEdicionId,
            $alumnoHash,
        ]));
    }

    /**
     * Guardar encuesta completa (cabecera + detalle)
     */
    public function guardarEncuestaCompleta(array $data)
{
    return DbSafe::execute('mysql_cursos', function () use ($data) {

        $conn = \DB::connection('mysql_cursos');

        return $conn->transaction(function () use ($conn, $data) {
            $existing = $conn->selectOne("
                SELECT id
                FROM encuesta_respuesta
                WHERE encuesta_id = ?
                  AND scope_type = ?
                  AND scope_id = ?
                  AND alumno_hash = ?
                LIMIT 1
                FOR UPDATE
            ", [
                $data['encuesta_id'],
                $data['scope_type'],
                $data['scope_id'],
                $data['alumno_hash'],
            ]);

            if ($existing) {
                throw new \DomainException('duplicate survey response');
            }

        // 🔵 1. Insert cabecera (100% alineado a tu tabla)
        $sqlCabecera = "
            INSERT INTO encuesta_respuesta (
                encuesta_id,
                curso_edicion_sesion_id,
                scope_type,
                scope_id,
                alumno_hash,
                respondido_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ";

        $conn->insert($sqlCabecera, [
            $data['encuesta_id'],
            $data['curso_edicion_sesion_id'] ?? null,
            $data['scope_type'],
            $data['scope_id'],
            $data['alumno_hash']
        ]);

        $respuestaId = (int) $conn->getPdo()->lastInsertId();

        // 🔵 2. Insert detalle
        $sqlDetalle = "
            INSERT INTO encuesta_respuesta_detalle (
                respuesta_id,
                pregunta_id,
                valor_escala,
                opcion_id,
                texto_respuesta
            ) VALUES (?, ?, ?, ?, ?)
        ";

        foreach ($data['respuestas'] as $preguntaId => $respuesta) {

            // 🔥 FIX DEFINITIVO (sin condicionales)
            $respuesta = (array) $respuesta;

            $valorEscala = $respuesta['valor_escala'] ?? null;
            $opcionId = $respuesta['opcion_id'] ?? null;
            $texto = $respuesta['texto_respuesta'] ?? null;

            $conn->insert($sqlDetalle, [
                $respuestaId,
                $preguntaId,
                $valorEscala,
                $opcionId,
                $texto
            ]);
        }

            return $respuestaId;
        });
    });
}

    public function obtenerContextoSesion(int $sesionId): ?object
    {
        $sql = "
            SELECT
                ces.id AS sesion_id,
                ces.curso_edicion_id,
                ces.fecha,
                ces.estado_sesion,
                ce.curso,
                ce.edicion
            FROM curso_edicion_sesiones ces
            INNER JOIN curso_edicion ce ON ce.id = ces.curso_edicion_id
            WHERE ces.id = ?
            LIMIT 1
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [$sesionId]);

        return $rows[0] ?? null;
    }

    public function alumnoInscritoEnCurso(int $cursoEdicionId, string $correo): bool
    {
        $sql = "
            SELECT 1
            FROM curso_edicion ce
            INNER JOIN Ficha_inscripcion fi
              ON fi.curso COLLATE utf8mb4_unicode_ci = ce.curso COLLATE utf8mb4_unicode_ci
             AND fi.grupo COLLATE utf8mb4_unicode_ci = ce.edicion COLLATE utf8mb4_unicode_ci
            WHERE ce.id = ?
              AND LOWER(TRIM(fi.CORREO_PERSONAL)) = LOWER(TRIM(?))
            LIMIT 1
        ";

        return !empty(DbSafe::select('mysql_cursos', $sql, [$cursoEdicionId, $correo]));
    }

    public function usuarioGestionaCurso(int $cursoEdicionId, string $correo): bool
    {
        $sql = "
            SELECT 1
            FROM curso_edicion ce
            INNER JOIN colaborador c ON c.id_colaborador = ce.docente_id_colaborador
            INNER JOIN usuario u ON u.colaborador_id = c.id_colaborador
            WHERE ce.id = ?
              AND LOWER(TRIM(u.email)) = LOWER(TRIM(?))
            LIMIT 1
        ";

        return !empty(DbSafe::select('mysql_cursos', $sql, [$cursoEdicionId, $correo]));
    }

    public function preguntasEncuesta(int $encuestaId): array
    {
        $sql = "
            SELECT
                p.id,
                p.tipo_respuesta,
                p.obligatoria,
                es.min_valor,
                es.max_valor
            FROM encuesta_pregunta p
            LEFT JOIN encuesta_escala es ON es.id = p.escala_id
            WHERE p.encuesta_id = ?
            ORDER BY p.orden, p.id
        ";

        return DbSafe::select('mysql_cursos', $sql, [$encuestaId]);
    }

    public function opcionPertenecePregunta(int $opcionId, int $preguntaId): bool
    {
        $sql = "
            SELECT 1
            FROM encuesta_pregunta_opcion
            WHERE id = ? AND pregunta_id = ?
            LIMIT 1
        ";

        return !empty(DbSafe::select('mysql_cursos', $sql, [$opcionId, $preguntaId]));
    }

    /**
     * Contar respuestas por sesión
     */
    public function contarRespondidasSesion(int $sesionId)
    {
        $sql = "
            SELECT COUNT(*) as total
            FROM encuesta_respuesta
            WHERE curso_edicion_sesion_id = ?
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [$sesionId]);

        return (int) ($rows[0]->total ?? 0);
    }

    /**
     * Obtener sesiones que el alumno ya respondió
     */
    public function obtenerSesionesRespondidas(array $sesionIds, string $alumnoHash): array
    {
        if (empty($sesionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sesionIds), '?'));

        $sql = "
            SELECT curso_edicion_sesion_id
            FROM encuesta_respuesta
            WHERE curso_edicion_sesion_id IN ($placeholders)
            AND alumno_hash = ?
        ";

        $params = array_merge($sesionIds, [$alumnoHash]);

        $rows = DbSafe::select('mysql_cursos', $sql, $params);

        return array_map(fn($r) => (int)$r->curso_edicion_sesion_id, $rows);
    }

    /**
 * Obtener detalle de resultados de encuesta por sesión (pivot)
 */
public function obtenerDetalleResultadosEncuestaPorSesion(int $cursoEdicionId): array
{
    $sql = "
        SELECT 
            ce.id,
            ce.curso,
            ces.nro_sesion,
            er.id AS respuesta_id,

            MAX(CASE WHEN ep.factor_evaluado = 'Puntualidad' THEN erd.valor_escala END) AS Puntualidad,
            MAX(CASE WHEN ep.factor_evaluado = 'Entendimiento' THEN erd.valor_escala END) AS Entendimiento,
            MAX(CASE WHEN ep.factor_evaluado = 'Laboratorios' THEN erd.valor_escala END) AS Laboratorios,
            MAX(CASE WHEN ep.factor_evaluado = 'Satisfacción' THEN erd.valor_escala END) AS Satisfaccion,

            MAX(CASE 
                WHEN ep.factor_evaluado = 'Observación' 
                THEN erd.texto_respuesta 
            END) AS Observacion

        FROM encuesta_respuesta er
        JOIN encuesta_respuesta_detalle erd ON erd.respuesta_id = er.id 
        JOIN encuesta_pregunta ep ON ep.id = erd.pregunta_id 
        JOIN curso_edicion_sesiones ces ON ces.id = er.curso_edicion_sesion_id
        JOIN curso_edicion ce ON ce.id = ces.curso_edicion_id 

        WHERE ce.id = ?

        GROUP BY 
            ce.id, ce.curso, ces.nro_sesion, er.id

        ORDER BY 
            ces.nro_sesion, er.id
    ";

    $rows = DbSafe::select('mysql_cursos', $sql, [$cursoEdicionId]);

    return $rows;
}
}
