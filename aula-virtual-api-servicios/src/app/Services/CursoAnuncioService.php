<?php

namespace App\Services;

use App\Repositories\CursoAnuncioRepository;
use Illuminate\Support\Facades\Cache;

class CursoAnuncioService
{
    protected CursoAnuncioRepository $repo;

    public function __construct(CursoAnuncioRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Listar anuncios por entidad
     */
    public function listarAnuncios(string $entidadTipo, int $entidadId)
    {
        return $this->repo->listarAnuncios($entidadTipo, $entidadId);
    }

    /**
     * Listar anuncios con estado de lectura
     */
    public function listarConEstadoLectura(
        string $entidadTipo,
        int $entidadId,
        string $correo
    ) {
        return $this->repo->listarConEstadoLectura(
            $entidadTipo,
            $entidadId,
            $correo
        );
    }

    /**
     * Marcar anuncio individual como leído
     */
    public function marcarLeido(int $anuncioId, string $correoAlumno): void
    {
        $this->repo->marcarAnuncioComoLeido($anuncioId, $correoAlumno);
    }

    /**
     * Marcar masivamente como leído
     */
    public function marcarMasivamenteComoLeido(
        string $entidadTipo,
        int $entidadId,
        string $correo
    ): int {
        return $this->repo->marcarMasivamenteComoLeido(
            $entidadTipo,
            $entidadId,
            $correo
        );
    }

    /**
     * Crear anuncio
     */
    public function crear(
        string $entidadTipo,
        int $entidadId,
        string $titulo,
        string $contenido,
        string $tipo,
        int $creadoPor
    ): int {
        return $this->repo->insertar(
            $entidadTipo,
            $entidadId,
            $titulo,
            $contenido,
            $tipo,
            $creadoPor
        );
    }

    /**
     * Editar anuncio
     */
   public function editar(
    int $anuncioId,
    string $titulo,
    string $contenido,
    string $tipo,
    int $editadoPor
): void {

    $anuncio = $this->repo->obtenerPorId($anuncioId);

    if (!$anuncio) {
        throw new \Exception('ANUNCIO_NO_ENCONTRADO');
    }

    $this->repo->modificar(
        $anuncioId,
        $titulo,
        $contenido,
        $tipo,
        $editadoPor
    );

    $this->invalidarCacheEntidad(
        $anuncio->entidad_tipo,
        $anuncio->entidad_id
    );
}

    /**
     * Eliminar anuncio (lógico)
     */
    public function eliminar(int $anuncioId): void
{
    $anuncio = $this->repo->obtenerPorId($anuncioId);

    if (!$anuncio) {
        throw new \Exception('ANUNCIO_NO_ENCONTRADO');
    }

    $this->repo->eliminar($anuncioId);

    // 🔥 invalidar cache aquí  
    $this->invalidarCacheEntidad(
        $anuncio->entidad_tipo,
        $anuncio->entidad_id
    );
}

private function invalidarCacheEntidad(string $entidadTipo, int $entidadId): void
{
    Cache::forget("anuncios_{$entidadTipo}_{$entidadId}");
}
}