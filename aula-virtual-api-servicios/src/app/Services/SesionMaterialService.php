<?php

namespace App\Services;

use App\Repositories\SesionMaterialRepository;
use Illuminate\Support\Facades\Storage;
 

class SesionMaterialService
{
    protected SesionMaterialRepository $repo;

    public function __construct(SesionMaterialRepository $repo)
    {
        $this->repo = $repo;
    }

    public function listarPorSesion(int $sesionId)
    {
        return $this->repo->listarPorSesion($sesionId);
    }

    public function crear(array $data)
    {
        return $this->repo->insertar($data);
    }

    public function actualizar(int $id, array $data)
    {
        return $this->repo->actualizar($id, $data);
    }

    public function eliminar(int $id): void
    {
        $material = $this->repo->buscarPorId($id);

        if (!$material) {
            return;
        }

        // eliminar archivo físico si existe
        if (!empty($material->ruta_archivo)) {
            Storage::disk('files')->delete($material->ruta_archivo);
        }

        // soft delete en DB
        $this->repo->eliminar($id);
    }

    public function obtenerArchivoParaDescarga(int $id)
{
    $material = $this->repo->buscarPorId($id);

    if (!$material) {
        abort(404, 'Material no encontrado');
    }

    if (!$material->ruta_archivo) {
        abort(400, 'Material sin archivo');
    }

    return [
        'ruta'   => $material->ruta_archivo,
        'nombre' => $material->nombre_archivo,
    ];
}
}