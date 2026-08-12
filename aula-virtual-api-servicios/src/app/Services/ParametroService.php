<?php

namespace App\Services;

use App\Repositories\ParametroRepository;

class ParametroService
{
    protected ParametroRepository $repo;

    public function __construct(ParametroRepository $repo)
    {
        $this->repo = $repo;
    }

    public function listar()
    {
        return $this->repo->listar();
    }

    public function listarPorMaestro(int $idMaestro)
    {
        return $this->repo->listarPorMaestro($idMaestro);
    }

    public function listarPorNombreMaestro(string $descMaestro)
    {
        return $this->repo->listarPorNombreMaestro($descMaestro);
    }

    public function obtener(int $idMaestro, int $idValor)
    {
        return $this->repo->obtener($idMaestro, $idValor);
    }

    public function obtenerDescripcion(int $idMaestro, int $idValor)
    {
        return $this->repo->obtenerDescripcion($idMaestro, $idValor);
    }

   
}