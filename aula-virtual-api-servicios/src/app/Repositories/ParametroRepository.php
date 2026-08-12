<?php

namespace App\Repositories;

use App\Helpers\DbSafe;

class ParametroRepository
{
    /**
     * Listar todos los parámetros
     */
    public function listar()
    {
        $sql = "
            SELECT
                id_maestro,
                desc_maestro,
                id_valor,
                desc_valor,
                flg_activo,
                fecha_creacion,
                fecha_actualizacion
            FROM parametros
            WHERE flg_activo = 1
            ORDER BY id_maestro, id_valor
        ";

        return DbSafe::select('mysql_cursos', $sql);
    }

    /**
     * Listar por maestro (ej: Sexo, Rol Laboral)
     */
    public function listarPorMaestro(int $idMaestro)
    {
        $sql = "
            SELECT
                id_maestro,
                desc_maestro,
                id_valor,
                desc_valor
            FROM parametros
            WHERE id_maestro = ?
              AND flg_activo = 1
            ORDER BY id_valor
        ";

        return DbSafe::select('mysql_cursos', $sql, [$idMaestro]);
    }

    /**
     * Obtener valor específico
     */
    public function obtener(int $idMaestro, int $idValor)
    {
        $sql = "
            SELECT
                id_maestro,
                desc_maestro,
                id_valor,
                desc_valor
            FROM parametros
            WHERE id_maestro = ?
              AND id_valor = ?
              AND flg_activo = 1
            LIMIT 1
        ";

        $rows = DbSafe::select('mysql_cursos', $sql, [$idMaestro, $idValor]);

        return $rows[0] ?? null;
    }

    /**
     * Obtener solo desc_valor
     */
    public function obtenerDescripcion(int $idMaestro, int $idValor)
    {
        $row = $this->obtener($idMaestro, $idValor);

        return $row->desc_valor ?? null;
    }

    /**
     * Listar por nombre de maestro (ej: 'Sexo')
     */
    public function listarPorNombreMaestro(string $descMaestro)
    {
        $sql = "
            SELECT
                id_maestro,
                desc_maestro,
                id_valor,
                desc_valor
            FROM parametros
            WHERE desc_maestro = ?
              AND flg_activo = 1
            ORDER BY id_valor
        ";

        return DbSafe::select('mysql_cursos', $sql, [$descMaestro]);
    }
}