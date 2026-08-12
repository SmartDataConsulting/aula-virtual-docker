<?php

namespace App\Services;

use App\Helpers\HtmlSanitizer;
use App\Repositories\EvaluacionRepository;
use Illuminate\Support\Carbon;

class EvaluacionService
{
    protected EvaluacionRepository $repo;

    public function __construct(EvaluacionRepository $repo)
    {
        $this->repo = $repo;
    }

    public function listarPorCurso(int $cursoId)
    {
        return $this->repo->listarPorCurso($cursoId);
    }

    public function obtenerDashboardCalificacionesCurso(int $cursoId): array
    {
        $rows = $this->repo->obtenerDashboardCalificacionesCurso($cursoId);

        if (empty($rows)) {
            return [
                'course' => [
                    'id' => $cursoId,
                    'name' => 'Curso',
                    'subtitle' => 'Gestion de evaluaciones - ' . Carbon::now()->format('Y'),
                ],
                'evaluations' => [],
                'pending_actions' => [],
            ];
        }

        $courseName = (string) ($rows[0]->curso_nombre ?? 'Curso');
        $evaluations = [];

        foreach ($rows as $row) {
            if (empty($row->evaluacion_id)) {
                continue;
            }

            $typeId = (int) ($row->tipo_param_id ?? 0);
            $studentsTotal = (int) ($row->alumnos_total ?? 0);
            $isExam = in_array($typeId, [1, 2], true);
            $isWork = in_array($typeId, [3, 4], true);

            $deliveredCount = $isWork ? (int) ($row->entregaron ?? 0) : 0;
            $correctedCount = $isWork ? (int) ($row->corregidos ?? 0) : null;
            $pendingCorrectionCount = $isWork ? max($deliveredCount - $correctedCount, 0) : 0;
            $renderedCount = $isExam ? (int) ($row->rindieron ?? 0) : 0;
            $absentCount = $isExam ? max($studentsTotal - $renderedCount, 0) : 0;

            $status = 'Pendiente';

            if (!(bool) ($row->publicada ?? false)) {
                $status = 'Borrador';
            } elseif ($isExam && $renderedCount > 0) {
                $status = 'Completado';
            } elseif ($isWork && $pendingCorrectionCount <= 0 && $deliveredCount > 0) {
                $status = 'Completado';
            }

            $evaluations[] = [
                'curso_sesion_evaluacion_id' => (int) $row->curso_sesion_evaluacion_id,
                'evaluacion_id' => (int) $row->evaluacion_id,
                'nombre' => (string) ($row->evaluacion_nombre ?? 'Evaluacion'),
                'tipo_param_id' => $typeId,
                'tipo_descripcion' => (string) ($row->tipo_descripcion ?? 'Evaluacion'),
                'publicada' => (bool) ($row->publicada ?? false),
                'peso' => isset($row->peso) ? (float) $row->peso : 0.0,
                'puntaje_aprobacion' => isset($row->puntaje_aprobacion) ? (float) $row->puntaje_aprobacion : 0.0,
                'created_at' => $row->created_at ?? null,
                'fecha_limite' => $row->fecha_limite ?? null,
                'students_total' => $studentsTotal,
                'rendered_count' => $renderedCount,
                'absent_count' => $absentCount,
                'average_score' => isset($row->promedio) ? (float) $row->promedio : 0.0,
                'max_score' => isset($row->maximo) ? (float) $row->maximo : 0.0,
                'min_score' => isset($row->minimo) ? (float) $row->minimo : 0.0,
                'failed_count' => (int) ($row->desaprobados ?? 0),
                'delivered_count' => $deliveredCount,
                'missing_count' => $isWork ? max($studentsTotal - $deliveredCount, 0) : 0,
                'corrected_count' => $correctedCount,
                'pending_correction_count' => $pendingCorrectionCount,
                'progress_percent' => $isWork && $deliveredCount > 0
                    ? round(($correctedCount / $deliveredCount) * 100, 1)
                    : 0,
                'status' => $status,
                'is_exam' => $isExam,
                'is_work' => $isWork,
                'auto_corrected' => $isExam,
            ];
        }

        $pendingActions = collect($evaluations)
            ->filter(fn (array $evaluation) => $evaluation['is_work'] && $evaluation['pending_correction_count'] > 0)
            ->sort(function (array $left, array $right) {
                $leftRank = $this->urgencyRank($left['fecha_limite'] ?? null);
                $rightRank = $this->urgencyRank($right['fecha_limite'] ?? null);

                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }

                $leftDeadline = $left['fecha_limite'] ?? '9999-12-31 23:59:59';
                $rightDeadline = $right['fecha_limite'] ?? '9999-12-31 23:59:59';

                if ($leftDeadline !== $rightDeadline) {
                    return strcmp($leftDeadline, $rightDeadline);
                }

                return $right['pending_correction_count'] <=> $left['pending_correction_count'];
            })
            ->values()
            ->map(function (array $evaluation) {
                return [
                    'evaluacion_id' => $evaluation['evaluacion_id'],
                    'nombre' => $evaluation['nombre'],
                    'tipo_descripcion' => $evaluation['tipo_descripcion'],
                    'pending_correction_count' => $evaluation['pending_correction_count'],
                    'fecha_limite' => $evaluation['fecha_limite'],
                    'urgency' => $this->urgencyLabel($evaluation['fecha_limite'] ?? null),
                ];
            })
            ->all();

        return [
            'course' => [
                'id' => $cursoId,
                'name' => $courseName,
                'subtitle' => 'Gestion de evaluaciones - ' . Carbon::now()->format('Y'),
            ],
            'evaluations' => $evaluations,
            'pending_actions' => $pendingActions,
        ];
    }

    public function crear(array $data)
    {
        if (!empty($data['descripcion'])) {
            $data['descripcion'] = HtmlSanitizer::sanitizeQuillHtml($data['descripcion']);
        }

        return $this->repo->insertar($data);
    }

    public function autosave(int $evaluacionId, array $data)
    {
        if (isset($data['evaluacion'])) {
            if (!empty($data['evaluacion']['descripcion'])) {
                $data['evaluacion']['descripcion'] =
                    HtmlSanitizer::sanitizeQuillHtml($data['evaluacion']['descripcion']);
            }

            $this->repo->actualizar($evaluacionId, $data['evaluacion']);
        }

        $preguntasRequest = $data['preguntas'] ?? [];
        $preguntasDb = $this->repo->listarPreguntas($evaluacionId);

        $idsRequest = [];
        $ordenPregunta = 1;

        foreach ($preguntasRequest as $pregunta) {
            if (empty($pregunta['texto'])) {
                continue;
            }

            $preguntaPayload = [
                'tipo_param_id' => $pregunta['tipo_param_id'],
                'texto' => $pregunta['texto'],
                'puntaje' => $pregunta['puntaje'] ?? 1,
                'feedback' => $pregunta['feedback'] ?? null,
                'orden' => $ordenPregunta++,
            ];

            if (!empty($pregunta['pregunta_id'])) {
                $idsRequest[] = $pregunta['pregunta_id'];

                $this->repo->actualizarPregunta(
                    $pregunta['pregunta_id'],
                    $preguntaPayload
                );

                $this->sincronizarOpciones(
                    $pregunta['pregunta_id'],
                    $pregunta['opciones'] ?? []
                );
            } else {
                $preguntaPayload['evaluacion_id'] = $evaluacionId;

                $preguntaId = $this->repo->insertarPregunta($preguntaPayload);

                $this->sincronizarOpciones(
                    $preguntaId,
                    $pregunta['opciones'] ?? []
                );
            }
        }

        foreach ($preguntasDb as $p) {
            if (!in_array($p->pregunta_id, $idsRequest)) {
                $this->repo->eliminarPregunta($p->pregunta_id);
            }
        }
    }

    protected function sincronizarOpciones(int $preguntaId, array $opcionesRequest)
    {
        $opcionesDb = $this->repo->listarOpciones($preguntaId);

        $idsRequest = [];
        $orden = 1;

        foreach ($opcionesRequest as $opcion) {
            if (empty($opcion['texto'])) {
                continue;
            }

            $payload = [
                'texto' => $opcion['texto'],
                'es_correcta' => $opcion['es_correcta'] ?? 0,
                'orden' => $orden++,
            ];

            if (!empty($opcion['opcion_id'])) {
                $idsRequest[] = $opcion['opcion_id'];

                $this->repo->actualizarOpcion(
                    $opcion['opcion_id'],
                    $payload
                );
            } else {
                $payload['pregunta_id'] = $preguntaId;

                $this->repo->insertarOpcion($payload);
            }
        }

        foreach ($opcionesDb as $o) {
            if (!in_array($o->opcion_id, $idsRequest)) {
                $this->repo->eliminarOpcion($o->opcion_id);
            }
        }
    }

    public function obtenerEvaluacionCompleta(int $evaluacionId)
    {
        $evaluacion = $this->repo->obtener($evaluacionId);

        if (!$evaluacion) {
            return null;
        }

        $preguntas = $this->repo->listarPreguntas($evaluacionId);

        foreach ($preguntas as &$p) {
            $p->opciones = array_values(
                $this->repo->listarOpciones($p->pregunta_id)
            );
        }

        return [
            'evaluacion' => (array) $evaluacion,
            'preguntas' => array_values($preguntas),
        ];
    }

    public function guardarTrabajo(int $evaluacionId, array $data)
    {
        $payload = array_merge(
            $data['evaluacion'] ?? [],
            $data['trabajo'] ?? $data
        );

        if (!empty($payload['descripcion'])) {
            $payload['descripcion'] =
                HtmlSanitizer::sanitizeQuillHtml($payload['descripcion']);
        }

        $payload['evaluacion_id'] = $evaluacionId;

        return $this->repo->guardarTrabajo($payload);
    }

    public function obtenerTrabajoPorEvaluacionId(int $evaluacionId)
    {
        return $this->repo->obtenerTrabajoPorEvaluacionId($evaluacionId);
    }

    public function publicar(int $evaluacionId)
    {
        $evaluacion = $this->repo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \Exception('Evaluacion no encontrada');
        }

        if (empty($evaluacion->nombre)) {
            throw new \Exception('La evaluacion no tiene titulo');
        }

        if ($evaluacion->peso <= 0) {
            throw new \Exception('La evaluacion debe tener un peso mayor a 0');
        }

        $tipoParamId = (int) ($evaluacion->tipo_param_id ?? 0);

        if (in_array($tipoParamId, [3, 4], true)) {
            $this->validarTrabajoPublicable($evaluacionId);
        } else {
            $this->validarEvaluacionConPreguntas($evaluacionId);
        }

        $this->repo->actualizar($evaluacionId, [
            'publicada' => 1,
        ]);

        return true;
    }

    private function validarEvaluacionConPreguntas(int $evaluacionId): void
    {
        $preguntas = $this->repo->listarPreguntas($evaluacionId);

        if (empty($preguntas)) {
            throw new \Exception('Debe agregar al menos una pregunta');
        }

        foreach ($preguntas as $pregunta) {
            if (empty($pregunta->texto)) {
                throw new \Exception('Hay preguntas sin texto');
            }

            if (empty($pregunta->feedback)) {
                throw new \Exception('Todas las preguntas deben tener feedback');
            }

            $opciones = $this->repo->listarOpciones($pregunta->pregunta_id);

            if (count($opciones) < 2) {
                throw new \Exception('Cada pregunta debe tener al menos 2 opciones');
            }

            $tieneCorrecta = false;

            foreach ($opciones as $opcion) {
                if (empty($opcion->texto)) {
                    throw new \Exception('Hay opciones sin texto');
                }

                if ($opcion->es_correcta) {
                    $tieneCorrecta = true;
                }
            }

            if (!$tieneCorrecta) {
                throw new \Exception('Cada pregunta debe tener una respuesta correcta');
            }
        }
    }

    private function validarTrabajoPublicable(int $evaluacionId): void
    {
        $trabajoData = $this->repo->obtenerTrabajoPorEvaluacionId($evaluacionId);

        if (!$trabajoData) {
            throw new \Exception('La evaluacion de trabajo no existe');
        }

        $trabajo = $trabajoData['trabajo'] ?? [];
        $rubrica = $trabajo['rubrica'] ?? [];
        $criterios = $rubrica['criterios'] ?? [];

        if (empty($trabajo['descripcion'])) {
            throw new \Exception('Debe ingresar la descripcion del trabajo');
        }

        if (empty($criterios)) {
            throw new \Exception('Debe agregar al menos un criterio');
        }

        $puntajeTotal = 0.0;

        foreach ($criterios as $index => $criterio) {
            $numero = $index + 1;
            $nombre = trim((string) ($criterio['nombre'] ?? ''));
            $descripcion = trim((string) ($criterio['descripcion'] ?? ''));
            $puntaje = (float) ($criterio['puntaje_max'] ?? 0);

            if ($nombre === '') {
                throw new \Exception("Criterio {$numero}: debe tener nombre");
            }

            if ($descripcion === '') {
                throw new \Exception("Criterio {$numero}: debe tener descripcion");
            }

            if ($puntaje <= 0) {
                throw new \Exception("Criterio {$numero}: el puntaje debe ser mayor a 0");
            }

            $puntajeTotal += $puntaje;
        }

        if (abs($puntajeTotal - 20) > 0.0001) {
            throw new \Exception('La suma de puntajes de criterios debe ser exactamente 20');
        }
    }

    public function duplicar(int $evaluacionId): int
    {
        $evaluacion = $this->repo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \Exception('Evaluacion no encontrada');
        }

        $nuevoId = $this->repo->insertar([
            'curso_id' => $evaluacion->curso_id,
            'tipo_param_id' => $evaluacion->tipo_param_id,
            'nombre' => $evaluacion->nombre . ' (Nueva version)',
            'tiempo_minutos' => $evaluacion->tiempo_minutos,
            'puntaje_aprobacion' => $evaluacion->puntaje_aprobacion,
            'descripcion' => $evaluacion->descripcion,
            'peso' => $evaluacion->peso,
            'version' => $evaluacion->version + 1,
            'activo' => 1,
            'publicada' => 0,
        ]);

        if (in_array((int) $evaluacion->tipo_param_id, [3, 4], true)) {
            $trabajoOriginal = $this->repo->obtenerTrabajoPorEvaluacionId($evaluacionId);
            $trabajo = $trabajoOriginal['trabajo'] ?? [];
            $rubrica = $trabajo['rubrica'] ?? [];

            $this->repo->guardarTrabajo([
                'evaluacion_id' => $nuevoId,
                'nombre' => $evaluacion->nombre . ' (Nueva version)',
                'tiempo_minutos' => $evaluacion->tiempo_minutos,
                'puntaje_aprobacion' => $evaluacion->puntaje_aprobacion,
                'descripcion' => $evaluacion->descripcion,
                'rubrica' => [
                    'nombre' => $rubrica['nombre'] ?? 'Rubrica general',
                    'criterios' => array_map(function ($criterio, $index) {
                        return [
                            'nombre' => $criterio['nombre'] ?? null,
                            'descripcion' => $criterio['descripcion'] ?? null,
                            'puntaje_max' => $criterio['puntaje_max'] ?? 0,
                            'orden' => $criterio['orden'] ?? ($index + 1),
                        ];
                    }, $rubrica['criterios'] ?? [], array_keys($rubrica['criterios'] ?? [])),
                ],
            ]);

            return $nuevoId;
        }

        $preguntas = $this->repo->listarPreguntas($evaluacionId);

        foreach ($preguntas as $p) {
            $preguntaId = $this->repo->insertarPregunta([
                'evaluacion_id' => $nuevoId,
                'tipo_param_id' => $p->tipo_param_id,
                'texto' => $p->texto,
                'puntaje' => $p->puntaje,
                'feedback' => $p->feedback,
                'orden' => $p->orden,
            ]);

            $opciones = $this->repo->listarOpciones($p->pregunta_id);

            foreach ($opciones as $o) {
                $this->repo->insertarOpcion([
                    'pregunta_id' => $preguntaId,
                    'texto' => $o->texto,
                    'es_correcta' => $o->es_correcta,
                    'orden' => $o->orden,
                ]);
            }
        }

        return $nuevoId;
    }

    public function listarPublicadasPorCursoYTipo(int $cursoId, int $tipoId)
    {
        return $this->repo->listarPublicadasPorCursoYTipo($cursoId, $tipoId);
    }

    private function urgencyRank(?string $deadline): int
    {
        if (!$deadline) {
            return 2;
        }

        $date = Carbon::parse($deadline);
        $today = Carbon::today();

        if ($date->isSameDay($today) || $date->lt($today)) {
            return 0;
        }

        return 1;
    }

    private function urgencyLabel(?string $deadline): string
    {
        if (!$deadline) {
            return 'Pendiente';
        }

        $date = Carbon::parse($deadline);
        $today = Carbon::today();

        if ($date->isSameDay($today) || $date->lt($today)) {
            return 'Urgente';
        }

        return 'Pendiente';
    }

    public function listarParticipantes(int $evaluacionId)
    {
        return $this->repo->listarParticipantesEvaluacion($evaluacionId);
    }
}
