<?php

namespace App\Repositories;

use App\Helpers\DbSafe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatRepository
{
    public function obtenerOCrearSala(string $tipoContexto, string $idContexto, ?string $titulo = null)
    {
        return DbSafe::execute('mysql_cursos', function () use ($tipoContexto, $idContexto, $titulo) {
            $conn = DB::connection('mysql_cursos');

            $sala = $conn->selectOne("
                SELECT *
                FROM sala_chat
                WHERE tipo_contexto = ?
                  AND id_contexto = ?
                LIMIT 1
            ", [$tipoContexto, $idContexto]);

            if ($sala) {
                return $sala;
            }

            $id = Str::uuid()->toString();

            $conn->insert("
                INSERT INTO sala_chat (
                    id, tipo_contexto, id_contexto, titulo, fecha_creacion
                ) VALUES (?, ?, ?, ?, NOW())
            ", [$id, $tipoContexto, $idContexto, $titulo]);

            return $conn->selectOne("
                SELECT *
                FROM sala_chat
                WHERE id = ?
                LIMIT 1
            ", [$id]);
        });
    }

    public function listarMensajes(string $salaId, int $limit = 20, int $offset = 0)
    {
        return DbSafe::select('mysql_cursos', "
            SELECT mensajes_paginados.*, a.foto_url
            FROM (
                SELECT *
                FROM mensaje_chat
                WHERE sala_id = ?
                  AND eliminado = FALSE
                ORDER BY fecha_creacion DESC
                LIMIT ? OFFSET ?
            ) AS mensajes_paginados
            LEFT JOIN alumno a
                ON LOWER(TRIM(a.correo)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(mensajes_paginados.usuario_id)) COLLATE utf8mb4_unicode_ci
            ORDER BY fecha_creacion ASC
        ", [$salaId, $limit, $offset]);
    }

    public function obtenerMensaje(string $mensajeId)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT mc.*, a.foto_url
            FROM mensaje_chat mc
            LEFT JOIN alumno a
                ON LOWER(TRIM(a.correo)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(mc.usuario_id)) COLLATE utf8mb4_unicode_ci
            WHERE mc.id = ?
            LIMIT 1
        ", [$mensajeId]);

        return $rows[0] ?? null;
    }

    public function insertarMensaje(array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($data) {
            $conn = DB::connection('mysql_cursos');
            $id = Str::uuid()->toString();

            $conn->insert("
                INSERT INTO mensaje_chat (
                    id,
                    sala_id,
                    mensaje_padre_id,
                    usuario_id,
                    nombre_usuario,
                    rol_usuario,
                    mensaje,
                    eliminado,
                    fijado,
                    fecha_creacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, FALSE, FALSE, NOW())
            ", [
                $id,
                $data['sala_id'],
                $data['mensaje_padre_id'] ?? null,
                $data['usuario_id'],
                $data['nombre_usuario'],
                $data['rol_usuario'] ?? null,
                $data['mensaje'],
            ]);

            return $this->obtenerMensaje($id);
        });
    }

    public function eliminarMensaje(string $mensajeId)
    {
        return DbSafe::statement('mysql_cursos', "
            UPDATE mensaje_chat
            SET eliminado = TRUE
            WHERE id = ?
        ", [$mensajeId]);
    }

    public function fijarMensaje(string $mensajeId)
    {
        return DbSafe::statement('mysql_cursos', "
            UPDATE mensaje_chat
            SET fijado = TRUE
            WHERE id = ?
        ", [$mensajeId]);
    }

    public function desfijarMensaje(string $mensajeId)
    {
        return DbSafe::statement('mysql_cursos', "
            UPDATE mensaje_chat
            SET fijado = FALSE
            WHERE id = ?
        ", [$mensajeId]);
    }

    public function listarMensajesFijados(string $salaId)
    {
        return DbSafe::select('mysql_cursos', "
            SELECT mc.*, a.foto_url
            FROM mensaje_chat mc
            LEFT JOIN alumno a
                ON LOWER(TRIM(a.correo)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(mc.usuario_id)) COLLATE utf8mb4_unicode_ci
            WHERE mc.sala_id = ?
              AND mc.fijado = TRUE
              AND mc.eliminado = FALSE
            ORDER BY mc.fecha_creacion DESC
        ", [$salaId]);
    }

    public function listarParticipantes(string $salaId)
    {
        return DbSafe::select('mysql_cursos', "
            SELECT
                usuario_id,
                nombre_usuario,
                rol_usuario,
                MAX(fecha_creacion) AS ultima_participacion,
                COUNT(*) AS total_mensajes
            FROM mensaje_chat
            WHERE sala_id = ?
              AND eliminado = FALSE
            GROUP BY usuario_id, nombre_usuario, rol_usuario
            ORDER BY ultima_participacion DESC
        ", [$salaId]);
    }

    public function buscarMensajes(string $salaId, string $texto)
    {
        return DbSafe::select('mysql_cursos', "
            SELECT *
            FROM mensaje_chat
            WHERE sala_id = ?
              AND eliminado = FALSE
              AND mensaje LIKE ?
            ORDER BY fecha_creacion DESC
        ", [
            $salaId,
            '%' . $texto . '%'
        ]);
    }

    public function contarMensajes(string $salaId)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT COUNT(*) AS total
            FROM mensaje_chat
            WHERE sala_id = ?
              AND eliminado = FALSE
        ", [$salaId]);

        return (int) ($rows[0]->total ?? 0);
    }
}
