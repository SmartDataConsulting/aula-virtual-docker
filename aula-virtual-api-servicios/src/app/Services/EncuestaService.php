<?php

namespace App\Services;

use App\Repositories\EncuestaRepository;

class EncuestaService
{
    protected EncuestaRepository $repo;

    public function __construct(EncuestaRepository $repo)
    {
        $this->repo = $repo;
    }

    public function listar()
    {
        return $this->repo->listar();
    }

    public function obtener(int $id)
    {
        return $this->repo->buscarPorId($id);
    }

    public function crear(array $data)
    {
        return $this->repo->insertar($data);
    }

    public function actualizar(int $id, array $data)
    {
        return $this->repo->actualizar($id, $data);
    }

    public function eliminar(int $id)
    {
        return $this->repo->eliminar($id);
    }

    public function listarPreguntas(int $encuestaId)
    {
        return $this->repo->listarPreguntas($encuestaId);
    }

    public function crearPregunta(array $data)
    {
        return $this->repo->insertarPregunta($data);
    }

    public function eliminarPregunta(int $id)
    {
        return $this->repo->eliminarPregunta($id);
    }

    public function obtenerEncuestaCompleta(int $encuestaTipoId)
    {
        $encuesta = $this->repo
            ->obtenerEncuestaActivaPorTipo($encuestaTipoId);
        
        $encuestaId = $encuesta->id ?? null;

        if (!$encuesta) {
            return null;
        }

        $preguntas = $this->repo->listarPreguntas($encuestaId);

        $data = [
            'id'=>$encuesta->id,
            'nombre'=>$encuesta->nombre,
            'tipo'=>$encuesta->tipo,
            'preguntas'=>[]
        ];

        foreach ($preguntas as $p) {

            $item = [
                'id'=>$p->id,
                'pregunta'=>$p->pregunta,
                'tipo_respuesta'=>$p->tipo_respuesta,
                'obligatoria'=>(bool)$p->obligatoria
            ];

            if ($p->tipo_respuesta == 1) {

                $escala = $this->repo->obtenerEscala($p->escala_id);

                $item['escala'] = [
                    'min' => (int) ($escala->min_valor ?? 1),
                    'max' => (int) ($escala->max_valor ?? 5),
                    'label_min' => (string) ($escala->label_min ?? 'Muy bajo'),
                    'label_max' => (string) ($escala->label_max ?? 'Excelente'),
                ];
            }

            if ($p->tipo_respuesta == 3) {

                $opciones = $this->repo->listarOpciones($p->id);

                $item['opciones'] = $opciones;
            }

            $data['preguntas'][] = $item;
        }

        return $data;
    }
}
