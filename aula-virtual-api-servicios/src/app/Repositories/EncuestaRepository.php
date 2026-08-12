<?php

namespace App\Repositories;

use App\Helpers\DbSafe;
use Illuminate\Support\Facades\Log;

class EncuestaRepository
{

    public function obtener(int $encuestaId)
    {
        $sql = "
            SELECT
                id,
                nombre,
                tipo,
                activa,
                created_at,
                updated_at
            FROM encuesta
            WHERE id = ?
            AND activa = 1
            LIMIT 1
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [$encuestaId]);

        return $rows[0] ?? null;
    }

    /**
     * Listar encuesta activas
     */
    public function listar()
    {
        $sql = "
            SELECT
                e.id,
                e.nombre,
                e.tipo,
                e.activa,
                e.created_at,
                e.updated_at
            FROM encuesta e
            WHERE e.activa = 1
            ORDER BY e.id DESC
        ";

        return DbSafe::select('mysql_cursos', $sql);
    }


    /**
     * Buscar encuesta por ID
     */
    public function buscarPorId(int $id)
    {
        $sql = "
            SELECT *
            FROM encuesta
            WHERE id = ?
              AND activa = 1
            LIMIT 1
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [$id]);

        return $rows[0] ?? null;
    }


    /**
     * Insertar encuesta
     */
    public function insertar(array $data)
    {
        $sql = "
            INSERT INTO encuesta (
                nombre,
                tipo,
                activa,
                created_by,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, 1, ?, NOW(), NOW()
            )
        ";

        return DbSafe::execute('mysql_cursos', function () use ($sql, $data) {

            $conn = \DB::connection('mysql_cursos');

            $conn->insert($sql, [
                $data['nombre'],
                $data['tipo'],
                $data['created_by'] ?? null,
            ]);

            return (int) $conn->getPdo()->lastInsertId();
        });
    }


    /**
     * Actualizar encuesta
     */
    public function actualizar(int $id, array $data)
    {
        $fields = [];
        $params = [];

        if (array_key_exists('nombre', $data)) {
            $fields[] = "nombre = ?";
            $params[] = $data['nombre'];
        }

        if (array_key_exists('tipo', $data)) {
            $fields[] = "tipo = ?";
            $params[] = $data['tipo'];
        }

        if (array_key_exists('activa', $data)) {
            $fields[] = "activa = ?";
            $params[] = $data['activa'];
        }

        $fields[] = "updated_at = NOW()";

        $sql = "
            UPDATE encuesta
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ";

        $params[] = $id;

        return DbSafe::statement('mysql_cursos', $sql, $params);
    }


    /**
     * Eliminado lógico
     */
    public function eliminar(int $id)
    {
        $sql = "
            UPDATE encuesta
            SET activa = 0,
                updated_at = NOW()
            WHERE id = ?
        ";

        return DbSafe::statement('mysql_cursos', $sql, [$id]);
    }


    /**
     * Listar preguntas de una encuesta
     */
    public function listarPreguntas(int $encuestaId)
    {
        $sql = "
            SELECT
                p.id,
                p.encuesta_id,
                p.pregunta,
                p.tipo_respuesta,
                p.escala_id,
                p.orden,
                p.obligatoria
            FROM encuesta_pregunta p
            WHERE p.encuesta_id = ?
            ORDER BY p.orden
        ";

        return DbSafe::select('mysql_cursos', $sql, [$encuestaId]);
    }


    /**
     * Insertar pregunta
     */
    public function insertarPregunta(array $data)
    {
        $sqlOrden = "
            SELECT COALESCE(MAX(orden),0) + 1 AS next_orden
            FROM encuesta_pregunta
            WHERE encuesta_id = ?
        ";

        $sqlInsert = "
            INSERT INTO encuesta_pregunta (
                encuesta_id,
                pregunta,
                tipo_respuesta,
                escala_id,
                orden,
                obligatoria,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ";

        return DbSafe::execute('mysql_cursos', function () use ($sqlOrden, $sqlInsert, $data) {

            $conn = \DB::connection('mysql_cursos');

            $row = $conn->selectOne($sqlOrden, [
                $data['encuesta_id']
            ]);

            $orden = $row->next_orden ?? 1;

            $conn->insert($sqlInsert, [
                $data['encuesta_id'],
                $data['pregunta'],
                $data['tipo_respuesta'],
                $data['escala_id'] ?? null,
                $orden,
                $data['obligatoria'] ?? 1,
            ]);

            return (int) $conn->getPdo()->lastInsertId();
        });
    }


    /**
     * Eliminar pregunta
     */
    public function eliminarPregunta(int $id)
    {
        $sql = "
            DELETE FROM encuesta_pregunta
            WHERE id = ?
        ";

        return DbSafe::statement('mysql_cursos', $sql, [$id]);
    }

    public function obtenerEscala(int $escalaId)
    {
        $sql = "
            SELECT
                id,
                nombre,
                min_valor,
                max_valor,
                label_min,
                label_max
            FROM encuesta_escala
            WHERE id = ?
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [$escalaId]);

        return $rows[0] ?? null;
    }

    public function listarOpciones(int $preguntaId)
    {
        $sql = "
            SELECT
                id,
                valor,
                texto,
                orden
            FROM encuesta_pregunta_opcion
            WHERE pregunta_id = ?
            ORDER BY orden
        ";

        return DbSafe::select('mysql_cursos', $sql, [$preguntaId]);
    }   

    public function obtenerEncuestaCompleta(int $encuestaId)
    {
        $encuesta = $this->buscarPorId($encuestaId);

        if (!$encuesta) {
            return null;
        }

        $preguntas = $this->listarPreguntas($encuestaId);

        foreach ($preguntas as $p) {

            if ($p->tipo_respuesta == 1 && $p->escala_id) {
                $p->escala = $this->obtenerEscala($p->escala_id);
            }

            if ($p->tipo_respuesta == 3) {
                $p->opciones = $this->listarOpciones($p->id);
            }
        }

        return [
            'encuesta' => $encuesta,
            'preguntas' => $preguntas
        ];
    }

    /**
 * Obtener encuesta activa por tipo
 */
 

public function obtenerEncuestaActivaPorTipo(int $tipo)
{
    $sql = "
        SELECT
            e.id,
            e.nombre,
            e.tipo,
            e.activa,
            e.created_at,
            e.updated_at
        FROM encuesta e
        WHERE e.tipo = ?
          AND e.activa = 1
        ORDER BY e.id DESC
        LIMIT 1
    ";

     
    $rows = DbSafe::select('mysql_cursos', $sql, [$tipo]);

     

    return $rows[0] ?? null;
}

}