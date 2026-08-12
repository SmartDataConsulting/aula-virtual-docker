<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use App\Helpers\DbSafe;

class CursoAnuncioRepository
{
    protected string $table = 'curso_edicion_anuncios';

    /**
     * Lista anuncios por entidad
     */
    public function listarAnuncios(string $entidadTipo, int $entidadId)
    {
        $sql = "
            SELECT
                id,
                entidad_tipo,
                entidad_id,
                titulo,
                contenido,
                tipo,
                creado_por,
                editado_por,
                creado_en,
                actualizado_en,
                editado_en
            FROM {$this->table}
            WHERE entidad_tipo = ?
              AND entidad_id = ?
              AND activo = 1
            ORDER BY creado_en DESC
        ";

        return DbSafe::select('mysql_cursos', $sql, [
            $entidadTipo,
            $entidadId
        ]);
    }

    /**
     * Obtener anuncio por ID
     */
    public function obtenerPorId(int $anuncioId)
    {
        $sql = "
            SELECT *
            FROM {$this->table}
            WHERE id = ?
              AND activo = 1
            LIMIT 1
        ";

        $result = DbSafe::select('mysql_cursos', $sql, [$anuncioId]);

        return $result[0] ?? null;
    }

    /**
     * Insertar anuncio
     */
    public function insertar(
        string $entidadTipo,
        int $entidadId,
        string $titulo,
        string $contenido,
        string $tipo,
        int $creadoPor
    ): int {

        return DbSafe::execute('mysql_cursos', function () use (
            $entidadTipo,
            $entidadId,
            $titulo,
            $contenido,
            $tipo,
            $creadoPor
        ) {

            return DB::connection('mysql_cursos')
                ->table($this->table)
                ->insertGetId([
                    'entidad_tipo'   => $entidadTipo,
                    'entidad_id'     => $entidadId,
                    'titulo'         => $titulo,
                    'contenido'      => $contenido,
                    'tipo'           => $tipo,
                    'activo'         => 1,
                    'creado_por'     => $creadoPor,
                    'creado_en'      => DB::raw('NOW()'),
                    'actualizado_en' => DB::raw('NOW()')
                ]);
        });
    }

    /**
     * Modificar anuncio (update simple)
     */
    public function modificar(
        int $anuncioId,
        string $titulo,
        string $contenido,
        string $tipo,
        int $editadoPor
    ): void {

        DbSafe::execute('mysql_cursos', function () use (
            $anuncioId,
            $titulo,
            $contenido,
            $tipo,
            $editadoPor
        ) {

            DB::connection('mysql_cursos')
                ->table($this->table)
                ->where('id', $anuncioId)
                ->where('activo', 1)
                ->update([
                    'titulo'         => $titulo,
                    'contenido'      => $contenido,
                    'tipo'           => $tipo,
                    'editado_por'    => $editadoPor,
                    'editado_en'     => DB::raw('NOW()'),
                    'actualizado_en' => DB::raw('NOW()')
                ]);
        });
    }

    /**
     * Eliminar lógico
     */
    public function eliminar(int $anuncioId): void
    {
        DbSafe::execute('mysql_cursos', function () use ($anuncioId) {

            DB::connection('mysql_cursos')
                ->table($this->table)
                ->where('id', $anuncioId)
                ->update([
                    'activo'         => 0,
                    'actualizado_en' => DB::raw('NOW()')
                ]);
        });
    }

    /**
     * Marca anuncio como leído
     */
    public function marcarAnuncioComoLeido(int $anuncioId, string $alumnoCorreo): void
    {
        DbSafe::execute('mysql_cursos', function () use ($anuncioId, $alumnoCorreo) {

            DB::connection('mysql_cursos')
                ->table('curso_edicion_anuncio_lecturas')
                ->insertOrIgnore([
                    'anuncio_id'   => $anuncioId,
                    'alumno_correo'=> $alumnoCorreo,
                    'leido_en'     => DB::raw('NOW()')
                ]);
        });
    }

    /**
     * Lista anuncios con estado lectura
     */
    public function listarConEstadoLectura(
        string $entidadTipo,
        int $entidadId,
        string $alumnoCorreo
    )
    {
        return DbSafe::execute('mysql_cursos', function () use (
            $entidadTipo,
            $entidadId,
            $alumnoCorreo
        ) {

            return DB::connection('mysql_cursos')
                ->table($this->table . ' as a')
                ->leftJoin('curso_edicion_anuncio_lecturas as l', function ($join) use ($alumnoCorreo) {
                    $join->on('a.id', '=', 'l.anuncio_id')
                         ->where('l.alumno_correo', '=', $alumnoCorreo);
                })
                ->where('a.entidad_tipo', $entidadTipo)
                ->where('a.entidad_id', $entidadId)
                ->where('a.activo', 1)
                ->select(
                    'a.*',
                    DB::raw('CASE WHEN l.id IS NULL THEN 0 ELSE 1 END as leido')
                )
                ->orderByDesc('a.creado_en')
                ->get();
        });
    }

    /**
     * Marcar masivamente como leído
     */
    public function marcarMasivamenteComoLeido(
        string $entidadTipo,
        int $entidadId,
        string $correo
    ): int {

        $sql = "
            INSERT INTO curso_edicion_anuncio_lecturas (anuncio_id, alumno_correo, leido_en)
            SELECT a.id,
                   ?,
                   NOW()
            FROM {$this->table} a
            LEFT JOIN curso_edicion_anuncio_lecturas l
              ON l.anuncio_id = a.id
             AND l.alumno_correo = ?
            WHERE a.entidad_tipo = ?
              AND a.entidad_id = ?
              AND a.activo = 1
              AND l.id IS NULL
        ";

        return DbSafe::statement('mysql_cursos', $sql, [
            $correo,
            $correo,
            $entidadTipo,
            $entidadId
        ]);
    }
}