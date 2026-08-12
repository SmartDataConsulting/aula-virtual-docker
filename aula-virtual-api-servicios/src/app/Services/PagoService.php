<?php

namespace App\Services;

use App\Repositories\PagoRepository;

class PagoService
{
    protected PagoRepository $repo;

    public function __construct(PagoRepository $repo)
    {
        $this->repo = $repo;
    }

    public function listarPagosPorCorreo(string $email): array
    {
        return $this->repo->listarPagosPorCorreo($email);
    }
}