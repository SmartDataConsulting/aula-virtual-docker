<?php

namespace App\Services;

use App\Repositories\CursoRepository;

class CursoService
{
    protected CursoRepository $repo;

    public function __construct(CursoRepository $repo)
    {
        $this->repo = $repo;
    }

    public function listarCursosAlumno(string $correo)
    {
        return $this->repo->listarCursosAlumno($correo);
    }

    public function listarCursosSugeridosAlumno(string $correo)
    {
        return $this->repo->listarCursosSugeridosAlumno($correo);
    }

    public function listarCursosBackoffice(string $correo, string $rol)
    {
        return $this->repo->listarCursosBackoffice($correo, $rol);
    }

    public function obtener(int $id)
    {
        return $this->repo->obtener($id);
    }

    public function listarCursosParaEvaluaciones(string $correo, string $rol)
    {
        return $this->repo->listarCursosParaEvaluaciones($correo, $rol);
    }

    public function listarCursosParaCalificaciones(string $correo, string $rol)
    {
        return $this->repo->listarCursosParaCalificaciones($correo, $rol);
    }

    public function listarCursosParaEncuestas(string $correo, string $rol)
    {
        return $this->repo->listarCursosParaCalificaciones($correo, $rol, true);
    }

    public function obtenerCurso(int $cursoId)
    {
        return $this->repo->obtenerCurso($cursoId);
    }

    public function listarAlumnosCurso(int $cursoEdicionId, string $solicitanteCorreo = '')
    {
        return $this->repo->listarAlumnosCurso($cursoEdicionId, $solicitanteCorreo);
    }
}
