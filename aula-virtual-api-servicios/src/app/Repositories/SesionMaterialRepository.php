<?php

namespace App\Repositories;

use App\Helpers\DbSafe;

class SesionMaterialRepository
{
    /**
     * Lista materiales activos por sesión
     */
    public function listarPorSesion(int $sesionId)
    {
        $sql = "
            SELECT
                m.id,
                m.curso_edicion_sesion_id,
                m.titulo,
                m.descripcion,
                m.tipo,
                m.nombre_archivo,
                m.ruta_archivo,
                m.mime_type,
                m.tamano_bytes,
                m.url_externa,
                m.orden,
                m.activo
            FROM curso_edicion_sesion_materiales m
            WHERE m.curso_edicion_sesion_id = ?
              AND m.activo = 1
            ORDER BY m.orden
        ";

        return DbSafe::select('mysql_cursos', $sql, [$sesionId]);
    }

    /**
 * Buscar material por ID
 */
public function buscarPorId(int $id)
{
    $sql = "
        SELECT *
        FROM curso_edicion_sesion_materiales
        WHERE id = ?
          AND activo = 1
        LIMIT 1
    ";

    $rows = DbSafe::select('mysql_cursos', $sql, [$id]);

    return $rows[0] ?? null;
}

     /**
 * Insertar nuevo material
 */
public function insertar(array $data)
{
    $sqlOrden = "
        SELECT COALESCE(MAX(orden), 0) + 1 AS next_orden
        FROM curso_edicion_sesion_materiales
        WHERE curso_edicion_sesion_id = ?
          AND activo = 1
    ";

    $sqlInsert = "
        INSERT INTO curso_edicion_sesion_materiales (
            curso_edicion_sesion_id,
            titulo,
            descripcion,
            tipo,
            nombre_archivo,
            ruta_archivo,
            mime_type,
            tamano_bytes,
            url_externa,
            orden,
            activo,
            subido_por,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW()
        )
    ";

    return DbSafe::execute('mysql_cursos', function () use ($sqlOrden, $sqlInsert, $data) {

        $conn = \DB::connection('mysql_cursos');

        // 🔵 calcular siguiente orden
        $row = $conn->selectOne($sqlOrden, [
            $data['curso_edicion_sesion_id']
        ]);

        $orden = $row->next_orden ?? 1;

        // 🔵 insertar
        $conn->insert($sqlInsert, [
            $data['curso_edicion_sesion_id'],
            $data['titulo'],
            $data['descripcion'] ?? null,
            $data['tipo'],
            $data['nombre_archivo'] ?? null,
            $data['ruta_archivo'] ?? null,
            $data['mime_type'] ?? null,
            $data['tamano_bytes'] ?? null,
            $data['url_externa'] ?? null,
            $orden,
            $data['subido_por'] ?? null,
        ]);

        return (int) $conn->getPdo()->lastInsertId();
    });
}
   public function actualizar(int $id, array $data)
{
    $fields = [];
    $params = [];

    // 🔵 Campos básicos (siempre vienen)
    $fields[] = "titulo = ?";
    $params[] = $data['titulo'];

    $fields[] = "descripcion = ?";
    $params[] = $data['descripcion'] ?? null;

    $fields[] = "tipo = ?";
    $params[] = $data['tipo'];

    // 🔵 Solo actualizar archivo si viene en el payload
    if (array_key_exists('nombre_archivo', $data)) {
        $fields[] = "nombre_archivo = ?";
        $params[] = $data['nombre_archivo'];
    }

    if (array_key_exists('ruta_archivo', $data)) {
        $fields[] = "ruta_archivo = ?";
        $params[] = $data['ruta_archivo'];
    }

    if (array_key_exists('mime_type', $data)) {
        $fields[] = "mime_type = ?";
        $params[] = $data['mime_type'];
    }

    if (array_key_exists('tamano_bytes', $data)) {
        $fields[] = "tamano_bytes = ?";
        $params[] = $data['tamano_bytes'];
    }

    // 🔵 URL externa
    if (array_key_exists('url_externa', $data)) {
        $fields[] = "url_externa = ?";
        $params[] = $data['url_externa'];
    }

    if (array_key_exists('orden', $data)) {
        $fields[] = "orden = ?";
        $params[] = $data['orden'];
    }

    $fields[] = "updated_at = NOW()";

    $sql = "
        UPDATE curso_edicion_sesion_materiales
        SET " . implode(', ', $fields) . "
        WHERE id = ?
    ";

    $params[] = $id;

    return DbSafe::statement('mysql_cursos', $sql, $params);
}
    /**
     * Eliminado lógico (soft delete)
     */
    public function eliminar(int $id)
    {
        $sql = "
            UPDATE curso_edicion_sesion_materiales
            SET activo = 0,
                updated_at = NOW()
            WHERE id = ?
        ";

        return DbSafe::statement('mysql_cursos', $sql, [$id]);
    }
}