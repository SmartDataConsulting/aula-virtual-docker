<?php

namespace App\Services;

use App\Repositories\EvaluacionRepository;
use App\Repositories\EvaluacionRendicionRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvaluacionRendicionService
{
    protected EvaluacionRepository $evaluacionRepo;
    protected EvaluacionRendicionRepository $repo;

    public function __construct(
        EvaluacionRepository $evaluacionRepo,
        EvaluacionRendicionRepository $repo
    ) {
        $this->evaluacionRepo = $evaluacionRepo;
        $this->repo = $repo;
    }

    public function obtenerOIniciar(int $evaluacionId, string $alumnoCorreo): array
    {
        $evaluacion = $this->evaluacionRepo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        if (empty($evaluacion->publicada)) {
            throw new \DomainException('La evaluacion no esta publicada');
        }

        $rendicion = $this->repo->obtenerEnProgreso($evaluacionId, $alumnoCorreo);

        if (!$rendicion) {
            $rendicion = $this->repo->obtenerUltimaPorAlumno($evaluacionId, $alumnoCorreo);
        }

        if (!$rendicion) {
            $rendicionId = $this->repo->insertar([
                'evaluacion_id' => $evaluacionId,
                'alumno_correo' => $alumnoCorreo,
                'estado' => 'en_progreso',
            ]);

            $rendicion = $this->repo->obtener($rendicionId);
        }

        return [
            'evaluacion' => (array) $evaluacion,
            'rendicion' => (array) $rendicion,
            'respuestas' => array_map(function ($row) {
                return (array) $row;
            }, $this->repo->listarRespuestas($rendicion->id)),
            'respondidas' => $this->repo->contarRespondidas($rendicion->id),
        ];
    }

    public function guardarRespuesta(
        int $evaluacionId,
        string $alumnoCorreo,
        int $preguntaId,
        ?int $opcionId
    ): array {
        $evaluacion = $this->evaluacionRepo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        if (empty($evaluacion->publicada)) {
            throw new \DomainException('La evaluacion no esta publicada');
        }

        $rendicion = $this->repo->obtenerEnProgreso($evaluacionId, $alumnoCorreo);

        if (!$rendicion) {
            $ultimaRendicion = $this->repo->obtenerUltimaPorAlumno($evaluacionId, $alumnoCorreo);

            if ($ultimaRendicion && ($ultimaRendicion->estado ?? null) === 'finalizado') {
                throw new \DomainException('La evaluacion ya fue finalizada');
            }

            $rendicionId = $this->repo->insertar([
                'evaluacion_id' => $evaluacionId,
                'alumno_correo' => $alumnoCorreo,
                'estado' => 'en_progreso',
            ]);

            $rendicion = $this->repo->obtener($rendicionId);
        }

        $preguntas = $this->evaluacionRepo->listarPreguntas($evaluacionId);

        $pregunta = null;

        foreach ($preguntas as $item) {
            if ((int) $item->pregunta_id === $preguntaId) {
                $pregunta = $item;
                break;
            }
        }

        if (!$pregunta) {
            throw new \DomainException('La pregunta no pertenece a la evaluacion');
        }

        $opciones = $this->evaluacionRepo->listarOpciones($preguntaId);

        $opcionSeleccionada = null;

        foreach ($opciones as $opcion) {
            if ($opcionId !== null && (int) $opcion->opcion_id === $opcionId) {
                $opcionSeleccionada = $opcion;
            }
        }

        if ($opcionId !== null && !$opcionSeleccionada) {
            throw new \DomainException('La opcion no pertenece a la pregunta');
        }

        $esCorrecta = null;
        $puntajeObtenido = 0;

        if ($opcionSeleccionada) {
            $esCorrecta = ((int) $opcionSeleccionada->es_correcta === 1) ? 1 : 0;
            $puntajeObtenido = $esCorrecta ? (float) $pregunta->puntaje : 0;
        }

        $this->repo->guardarRespuesta([
            'rendicion_id' => $rendicion->id,
            'pregunta_id' => $preguntaId,
            'opcion_id' => $opcionId,
            'es_correcta' => $esCorrecta,
            'puntaje_obtenido' => $puntajeObtenido,
        ]);

        return [
            'rendicion_id' => (int) $rendicion->id,
            'pregunta_id' => $preguntaId,
            'opcion_id' => $opcionId,
            'es_correcta' => $esCorrecta,
            'puntaje_obtenido' => $puntajeObtenido,
            'respondidas' => $this->repo->contarRespondidas($rendicion->id),
        ];
    }

    public function obtenerResultadoParcial(int $evaluacionId, string $alumnoCorreo): array
    {
        $evaluacion = $this->evaluacionRepo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $rendicion = $this->repo->obtenerEnProgreso($evaluacionId, $alumnoCorreo);

        if (!$rendicion) {
            return [
                'rendicion' => null,
                'respuestas' => [],
                'respondidas' => 0,
                'puntaje' => 0,
            ];
        }

        return [
            'rendicion' => (array) $rendicion,
            'respuestas' => array_map(function ($row) {
                return (array) $row;
            }, $this->repo->listarRespuestas($rendicion->id)),
            'respondidas' => $this->repo->contarRespondidas($rendicion->id),
            'puntaje' => $this->repo->sumarPuntaje($rendicion->id),
        ];
    }

    public function finalizar(int $evaluacionId, string $alumnoCorreo): array
    {
        $evaluacion = $this->evaluacionRepo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $rendicion = $this->repo->obtenerEnProgreso($evaluacionId, $alumnoCorreo);

        if (!$rendicion) {
            $ultimaRendicion = $this->repo->obtenerUltimaPorAlumno($evaluacionId, $alumnoCorreo);

            if ($ultimaRendicion && ($ultimaRendicion->estado ?? null) === 'finalizado') {
                return $this->obtenerResultadoFinal((int) $ultimaRendicion->id);
            }

            throw new \DomainException('No existe una rendicion en progreso');
        }

        $preguntas = $this->evaluacionRepo->listarPreguntas($evaluacionId);
        $totalPreguntas = count($preguntas);

        $respondidas = $this->repo->contarRespondidas($rendicion->id);
        $puntajeTotal = $this->repo->sumarPuntaje($rendicion->id);
        
        $puntajeAprobacion = (float) ($evaluacion->puntaje_aprobacion ?? 11);
        $aprobado = $puntajeTotal >= $puntajeAprobacion ? 1 : 0;

        $this->repo->finalizar($rendicion->id, [
            'puntaje_total' => $puntajeTotal,
            'aprobado' => $aprobado,
        ]);

        $rendicionFinal = $this->repo->obtener($rendicion->id);

        return [
            'evaluacion' => (array) $evaluacion,
            'rendicion' => (array) $rendicionFinal,
            'respuestas' => array_map(function ($row) {
                return (array) $row;
            }, $this->repo->listarRespuestas($rendicion->id)),
            'correctas' => $this->contarCorrectas($rendicion->id),
            'incorrectas' => max(0, $respondidas - $this->contarCorrectas($rendicion->id)),
            'respondidas' => $respondidas,
            'total_preguntas' => $totalPreguntas,
            'puntaje_total' => $puntajeTotal,
            'aprobado' => $aprobado,
        ];
    }

    public function obtenerResultadoFinal(int $rendicionId): array
    {
        $rendicion = $this->repo->obtener($rendicionId);

        if (!$rendicion) {
            throw new \RuntimeException('La rendicion no existe');
        }

        $evaluacion = $this->evaluacionRepo->obtener((int) $rendicion->evaluacion_id);
        $respuestas = $this->repo->listarRespuestas($rendicionId);
        $correctas = $this->contarCorrectas($rendicionId);
        $respondidas = $this->repo->contarRespondidas($rendicionId);

        return [
            'evaluacion' => $evaluacion ? (array) $evaluacion : null,
            'rendicion' => (array) $rendicion,
            'respuestas' => array_map(function ($row) {
                return (array) $row;
            }, $respuestas),
            'correctas' => $correctas,
            'incorrectas' => max(0, $respondidas - $correctas),
            'respondidas' => $respondidas,
            'puntaje_total' => (float) ($rendicion->puntaje_total ?? 0),
            'aprobado' => (int) ($rendicion->aprobado ?? 0),
        ];
    }

    public function obtenerTrabajoAlumno(int $evaluacionId, string $correo): array
    {
        $correo = $this->normalizarCorreo($correo);
        $trabajo = $this->evaluacionRepo->obtenerTrabajoPorEvaluacionId($evaluacionId);

        if (!$trabajo) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $evaluacion = $trabajo['evaluacion'] ?? [];

        $this->validarAccesoAlumnoTrabajo($evaluacionId, $correo, $evaluacion);

        return [
            'evaluacion' => $evaluacion,
            'trabajo' => $trabajo['trabajo'] ?? null,
            'entrega' => $this->construirEstadoEntrega(
                $evaluacion,
                $this->repo->obtenerEntregaTrabajoPorEvaluacionYCorreo($evaluacionId, $correo)
            ),
        ];
    }

    public function guardarEntregaTrabajoBorrador(
        int $evaluacionId,
        string $correo,
        array $payload,
        array $uploadedFiles
    ): array {
        $correo = $this->normalizarCorreo($correo);
        $trabajo = $this->evaluacionRepo->obtenerTrabajoPorEvaluacionId($evaluacionId);

        if (!$trabajo) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $evaluacion = $trabajo['evaluacion'] ?? [];

        $this->validarAccesoAlumnoTrabajo($evaluacionId, $correo, $evaluacion);

        if ($this->estaFueraDePlazo($evaluacion)) {
            throw new \DomainException('La fecha limite de entrega ha vencido');
        }

        $entregaActual = $this->repo->obtenerEntregaTrabajoPorEvaluacionYCorreo($evaluacionId, $correo);

        if ($this->esEntregaFinalizada($entregaActual)) {
            throw new \DomainException('La entrega ya fue finalizada');
        }

        $archivosEliminar = $this->normalizarIds($payload['archivos_eliminar'] ?? []);
        $nuevosArchivos = $this->normalizarArchivosSubidos($uploadedFiles);

        $activosRestantes = $this->contarArchivosActivosRestantes(
            $entregaActual['archivos'] ?? [],
            $archivosEliminar
        );

        if (($activosRestantes + count($nuevosArchivos)) > $this->maxArchivosEntrega()) {
            throw new \DomainException(
                'Solo se permiten hasta ' . $this->maxArchivosEntrega() . ' archivos por entrega'
            );
        }

        foreach ($nuevosArchivos as $archivo) {
            $this->validarArchivoEntrega($archivo);
        }

        $archivosGuardados = $this->guardarArchivosEnDisco($evaluacionId, $correo, $nuevosArchivos);

        try {
            $resultado = $this->repo->guardarEntregaAlumno(
                $evaluacionId,
                $correo,
                [
                    'observacion_alumno' => $payload['observacion_alumno'] ?? null,
                ],
                $archivosGuardados,
                $archivosEliminar,
                false
            );
        } catch (\Throwable $e) {
            $this->eliminarArchivosFisicos($archivosGuardados);
            throw $e;
        }

        $this->eliminarArchivosFisicos($resultado['archivos_eliminados'] ?? []);

        return [
            'evaluacion' => $evaluacion,
            'trabajo' => $trabajo['trabajo'] ?? null,
            'entrega' => $this->construirEstadoEntrega($evaluacion, $resultado['entrega'] ?? null),
        ];
    }

    public function finalizarEntregaTrabajo(
        int $evaluacionId,
        string $correo,
        array $payload
    ): array {
        $correo = $this->normalizarCorreo($correo);
        $trabajo = $this->evaluacionRepo->obtenerTrabajoPorEvaluacionId($evaluacionId);

        if (!$trabajo) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $evaluacion = $trabajo['evaluacion'] ?? [];

        $this->validarAccesoAlumnoTrabajo($evaluacionId, $correo, $evaluacion);

        if ($this->estaFueraDePlazo($evaluacion)) {
            throw new \DomainException('La fecha limite de entrega ha vencido');
        }

        $entregaActual = $this->repo->obtenerEntregaTrabajoPorEvaluacionYCorreo($evaluacionId, $correo);

        if ($this->esEntregaFinalizada($entregaActual)) {
            throw new \DomainException('La entrega ya fue finalizada');
        }

        if (count($entregaActual['archivos'] ?? []) < 1) {
            throw new \DomainException('Debe adjuntar al menos 1 archivo para finalizar la entrega');
        }

        $resultado = $this->repo->guardarEntregaAlumno(
            $evaluacionId,
            $correo,
            [
                'observacion_alumno' => $payload['observacion_alumno'] ?? null,
            ],
            [],
            [],
            true
        );

        return [
            'evaluacion' => $evaluacion,
            'trabajo' => $trabajo['trabajo'] ?? null,
            'entrega' => $this->construirEstadoEntrega($evaluacion, $resultado['entrega'] ?? null),
        ];
    }

    public function obtenerArchivoEntregaParaDescarga(int $archivoId, string $correo): array
    {
        $correo = $this->normalizarCorreo($correo);
        $archivo = $this->repo->obtenerArchivoEntregaPorIdYCorreo($archivoId, $correo);

        if (!$archivo) {
            throw new \RuntimeException('Archivo no encontrado');
        }

        return [
            'ruta' => $archivo->ruta_archivo,
            'nombre' => $archivo->nombre_original,
            'mime_type' => $archivo->mime_type,
        ];
    }

    public function obtenerDetalleRevision(int $evaluacionId, int $entregaId): array
    {
        $trabajoData = $this->evaluacionRepo->obtenerTrabajoPorEvaluacionId($evaluacionId);

        if (!$trabajoData) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $evaluacion = $trabajoData['evaluacion'] ?? [];

        if (!in_array((int) ($evaluacion['tipo_param_id'] ?? 0), [3, 4], true)) {
            throw new \DomainException('Solo aplica para evaluaciones de tipo trabajo');
        }

        $entrega = $this->repo->obtenerEntregaTrabajoPorId($evaluacionId, $entregaId);

        if (!$entrega) {
            throw new \RuntimeException('La entrega no existe');
        }

        $participantes = array_map(function ($row) {
            return (array) $row;
        }, $this->evaluacionRepo->listarParticipantesEvaluacion($evaluacionId));

        $trabajo = $trabajoData['trabajo'] ?? null;

        if ($trabajo) {
            $trabajo['entrega'] = $entrega;
        }

        return [
            'evaluacion' => array_merge($evaluacion, [
                'participantes' => $participantes,
            ]),
            'trabajo' => $trabajo,
            'rubrica' => $this->repo->obtenerRubricaEntrega($evaluacionId, $entregaId),
        ];
    }

    public function guardarDetalleRevision(int $evaluacionId, int $entregaId, array $payload): array
    {
        $trabajoData = $this->evaluacionRepo->obtenerTrabajoPorEvaluacionId($evaluacionId);

        if (!$trabajoData) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $evaluacion = $trabajoData['evaluacion'] ?? [];

        if (!in_array((int) ($evaluacion['tipo_param_id'] ?? 0), [3, 4], true)) {
            throw new \DomainException('Solo aplica para evaluaciones de tipo trabajo');
        }

        $entrega = $this->repo->obtenerEntregaTrabajoPorId($evaluacionId, $entregaId);

        if (!$entrega) {
            throw new \RuntimeException('La entrega no existe');
        }

        $rubrica = $this->repo->obtenerRubricaEntrega($evaluacionId, $entregaId);
        $criteriosDisponibles = $rubrica['criterios'] ?? [];

        if (empty($criteriosDisponibles)) {
            throw new \DomainException('La rubrica no tiene criterios configurados');
        }

        $criteriosPayload = $payload['criterios'] ?? null;

        if (!is_array($criteriosPayload) || empty($criteriosPayload)) {
            throw new \DomainException('Debes enviar los criterios a calificar');
        }

        $payloadPorCriterio = [];

        foreach ($criteriosPayload as $criterioPayload) {
            if (!is_array($criterioPayload)) {
                continue;
            }

            $criterioId = (int) ($criterioPayload['criterio_id'] ?? 0);

            if ($criterioId <= 0) {
                continue;
            }

            $payloadPorCriterio[$criterioId] = $criterioPayload;
        }

        $detalles = [];
        $puntajeTotal = 0.0;
        $puntajeMaximo = 0.0;

        foreach ($criteriosDisponibles as $criterio) {
            $criterioId = (int) ($criterio['criterio_id'] ?? 0);
            $criterioPayload = $payloadPorCriterio[$criterioId] ?? null;

            if (!$criterioPayload) {
                throw new \DomainException('Todos los criterios deben tener una calificacion');
            }

            $nivel = (int) ($criterioPayload['nivel'] ?? 0);

            if ($nivel < 1 || $nivel > 5) {
                throw new \DomainException('El nivel debe estar entre 1 y 5');
            }

            $puntajeMax = (float) ($criterio['puntaje_max'] ?? 0);
            $puntajeObtenido = $puntajeMax > 0
                ? round(($puntajeMax * ($nivel - 1)) / 4, 2)
                : 0.0;

            $detalles[] = [
                'criterio_id' => $criterioId,
                'nivel' => $nivel,
                'puntaje_obtenido' => $puntajeObtenido,
                'comentario' => trim((string) ($criterioPayload['comentario'] ?? '')),
            ];

            $puntajeTotal += $puntajeObtenido;
            $puntajeMaximo += $puntajeMax;
        }

        $puntajeAprobacion = (float) ($evaluacion['puntaje_aprobacion'] ?? 11);
        $aprobado = $puntajeTotal >= $puntajeAprobacion;
        $usuarioId = $this->validarEnteroRequerido($payload['usuario_id'] ?? null, 'usuario_id');

        $this->repo->guardarDetalleRevision($evaluacionId, $entregaId, [
            'usuario_id' => $usuarioId,
            'puntaje_total' => round($puntajeTotal, 2),
            'aprobado' => $aprobado ? 1 : 0,
            'observacion_docente' => trim((string) ($payload['observacion_docente'] ?? '')),
            'criterios' => $detalles,
        ]);

        return $this->obtenerDetalleRevision($evaluacionId, $entregaId);
    }

    public function registrarSubsanacionExamen(int $evaluacionId, array $payload): array
    {
        $evaluacion = $this->evaluacionRepo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        if ($this->esEvaluacionTrabajo((array) $evaluacion)) {
            throw new \DomainException('La evaluacion no corresponde a examen');
        }

        $alumnoCorreo = $this->normalizarCorreoPayload((string) ($payload['alumno_correo'] ?? ''));
        $puntajeTotal = $this->validarNumeroRequerido($payload['puntaje_total'] ?? null, 'puntaje_total');
        $aprobado = $this->resolverAprobado($payload['aprobado'] ?? null, $puntajeTotal, $evaluacion);

        $resultado = $this->repo->ejecutarTransaccion(function ($conn) use (
            $evaluacionId,
            $alumnoCorreo,
            $puntajeTotal,
            $aprobado,
            $payload
        ) {
            $rendicion = $this->repo->obtenerUltimaPorAlumno($evaluacionId, $alumnoCorreo);

            if ($rendicion) {
                $rendicionId = (int) $rendicion->id;
                $this->repo->actualizarRendicionFinalizadaSubsanacion($rendicionId, [
                    'puntaje_total' => $puntajeTotal,
                    'aprobado' => $aprobado,
                ], $conn);
            } else {
                $rendicionId = $this->repo->insertarRendicionFinalizadaSubsanacion([
                    'evaluacion_id' => $evaluacionId,
                    'alumno_correo' => $alumnoCorreo,
                    'puntaje_total' => $puntajeTotal,
                    'aprobado' => $aprobado,
                ], $conn);
            }

            $subsanacionId = $this->repo->insertarSubsanacion([
                'evaluacion_id' => $evaluacionId,
                'rendicion_id' => $rendicionId,
                'calificacion_id' => null,
                'motivo' => $this->normalizarTextoOpcional($payload['motivo'] ?? null, 150),
                'observacion' => $this->normalizarTextoOpcional($payload['observacion'] ?? null),
                'evidencia_archivo' => $this->normalizarTextoOpcional($payload['evidencia_archivo'] ?? null, 500),
                'usuario_id' => $this->validarEnteroRequerido($payload['usuario_id'] ?? null, 'usuario_id'),
            ], $conn);

            return [
                'rendicion' => (array) $this->repo->obtener($rendicionId),
                'subsanacion' => $this->repo->obtenerSubsanacionPorId($subsanacionId, $conn),
            ];
        });

        return [
            'evaluacion' => (array) $evaluacion,
            'rendicion' => $resultado['rendicion'],
            'subsanacion' => $resultado['subsanacion'],
            'es_subsanacion' => true,
        ];
    }

    public function registrarSubsanacionTrabajo(int $evaluacionId, array $payload): array
    {
        $trabajoData = $this->evaluacionRepo->obtenerTrabajoPorEvaluacionId($evaluacionId);

        if (!$trabajoData) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $evaluacion = $trabajoData['evaluacion'] ?? [];

        if (!$this->esEvaluacionTrabajo($evaluacion)) {
            throw new \DomainException('La evaluacion no corresponde a trabajo');
        }

        $alumnoCorreo = $this->normalizarCorreoPayload((string) ($payload['alumno_correo'] ?? ''));
        $usuarioId = $this->validarEnteroRequerido($payload['usuario_id'] ?? null, 'usuario_id');
        $puntajeTotal = $this->validarNumeroRequerido($payload['puntaje_total'] ?? null, 'puntaje_total');
        $aprobado = $this->resolverAprobado($payload['aprobado'] ?? null,$puntajeTotal,$evaluacion);
        $criterios = $this->resolverCriteriosSubsanacionTrabajo($evaluacionId, $payload['criterios'] ?? []);

        $resultado = $this->repo->ejecutarTransaccion(function ($conn) use (
            $evaluacionId,
            $alumnoCorreo,
            $usuarioId,
            $puntajeTotal,
            $aprobado,
            $criterios,
            $payload,
            $trabajoData
        ) {
            $entrega = $this->repo->obtenerEntregaTrabajoPorEvaluacionYCorreo($evaluacionId, $alumnoCorreo, $conn);

            if (!$entrega) {
                $entregaId = $this->repo->crearEntregaTrabajoSubsanacion($evaluacionId, $alumnoCorreo, $conn);
            } else {
                $entregaId = (int) $entrega['entrega_id'];
            }

            $rubrica = $this->repo->guardarCalificacionTrabajoSubsanacion($evaluacionId, $entregaId, [
                'usuario_id' => $usuarioId,
                'puntaje_total' => $puntajeTotal,
                'aprobado' => $aprobado,
                'observacion_docente' => $this->normalizarTextoOpcional($payload['observacion'] ?? null),
                'criterios' => $criterios,
            ], $conn);

            $calificacionId = (int) ($rubrica['calificacion_id'] ?? 0);

            if ($calificacionId <= 0) {
                throw new \RuntimeException('No se pudo generar la calificacion de trabajo');
            }

            $subsanacionId = $this->repo->insertarSubsanacion([
                'evaluacion_id' => $evaluacionId,
                'rendicion_id' => null,
                'calificacion_id' => $calificacionId,
                'motivo' => $this->normalizarTextoOpcional($payload['motivo'] ?? null, 150),
                'observacion' => $this->normalizarTextoOpcional($payload['observacion'] ?? null),
                'evidencia_archivo' => $this->normalizarTextoOpcional($payload['evidencia_archivo'] ?? null, 500),
                'usuario_id' => $usuarioId,
            ], $conn);

            $trabajo = $trabajoData['trabajo'] ?? null;

            if ($trabajo) {
                $trabajo['entrega'] = $this->repo->obtenerEntregaTrabajoPorId($evaluacionId, $entregaId, $conn);
            }

            return [
                'trabajo' => $trabajo,
                'rubrica' => $this->repo->obtenerRubricaEntrega($evaluacionId, $entregaId, $conn),
                'subsanacion' => $this->repo->obtenerSubsanacionPorId($subsanacionId, $conn),
            ];
        });

        return [
            'evaluacion' => $evaluacion,
            'trabajo' => $resultado['trabajo'],
            'rubrica' => $resultado['rubrica'],
            'subsanacion' => $resultado['subsanacion'],
            'es_subsanacion' => true,
        ];
    }

    public function actualizarSubsanacion(int $evaluacionId, int $subsanacionId, array $payload): array
    {
        $evaluacion = $this->evaluacionRepo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        $subsanacion = $this->repo->obtenerSubsanacionPorId($subsanacionId);

        if (!$subsanacion || (int) ($subsanacion['evaluacion_id'] ?? 0) !== $evaluacionId) {
            throw new \RuntimeException('La subsanacion no existe');
        }

        $esTrabajo = empty($subsanacion['rendicion_id']) && !empty($subsanacion['calificacion_id']);

        if ($esTrabajo && !$this->esEvaluacionTrabajo((array) $evaluacion)) {
            throw new \DomainException('La evaluacion no corresponde a trabajo');
        }

        if (!$esTrabajo && $this->esEvaluacionTrabajo((array) $evaluacion)) {
            throw new \DomainException('La evaluacion no corresponde a examen');
        }

        $resultado = $this->repo->ejecutarTransaccion(function ($conn) use (
            $evaluacionId,
            $subsanacionId,
            $payload,
            $subsanacion,
            $evaluacion,
            $esTrabajo
        ) {
            $this->repo->actualizarSubsanacion(
                $subsanacionId,
                $this->resolverPayloadActualizacionSubsanacion($payload),
                $conn
            );

            if ($esTrabajo) {
                $calificacionId = (int) $subsanacion['calificacion_id'];
                $calificacion = $this->repo->obtenerCalificacionTrabajoPorId($calificacionId, $conn);

                if (!$calificacion) {
                    throw new \RuntimeException('La calificacion no existe');
                }

                $actualizarNota = array_key_exists('puntaje_total', $payload)
                    || array_key_exists('aprobado', $payload);
                $actualizarCriterios = array_key_exists('criterios', $payload);
                $calificacionPayload = [];

                if (array_key_exists('usuario_id', $payload)) {
                    $calificacionPayload['usuario_id'] = $this->validarEnteroRequerido(
                        $payload['usuario_id'],
                        'usuario_id'
                    );
                }

                if ($actualizarNota) {
                    $puntajeTotal = array_key_exists('puntaje_total', $payload)
                        ? $this->validarNumeroRequerido($payload['puntaje_total'], 'puntaje_total')
                        : (float) ($calificacion['puntaje_total'] ?? 0);

                    $calificacionPayload['puntaje_total'] = $puntajeTotal;
                    $calificacionPayload['aprobado'] = $this->resolverAprobado(
                        $payload['aprobado'] ?? null,
                        $puntajeTotal,
                        $evaluacion
                    );
                }

                if (array_key_exists('observacion', $payload)) {
                    $calificacionPayload['observacion_docente'] = $this->normalizarTextoOpcional(
                        $payload['observacion'] ?? null
                    );
                }

                if ($actualizarCriterios) {
                    $calificacionPayload['criterios'] = $this->resolverCriteriosSubsanacionTrabajo(
                        $evaluacionId,
                        $payload['criterios']
                    );
                }

                $this->repo->actualizarCalificacionTrabajoSubsanacion(
                    $calificacionId,
                    $calificacionPayload,
                    $actualizarCriterios,
                    $conn
                );

                $entregaId = (int) ($calificacion['entrega_id'] ?? 0);
                $trabajoData = $this->evaluacionRepo->obtenerTrabajoPorEvaluacionId($evaluacionId);
                $trabajo = $trabajoData['trabajo'] ?? null;

                if ($trabajo) {
                    $trabajo['entrega'] = $this->repo->obtenerEntregaTrabajoPorId($evaluacionId, $entregaId, $conn);
                }

                return [
                    'tipo_subsanacion' => 'trabajo',
                    'trabajo' => $trabajo,
                    'rubrica' => $this->repo->obtenerRubricaEntrega($evaluacionId, $entregaId, $conn),
                    'subsanacion' => $this->repo->obtenerSubsanacionPorId($subsanacionId, $conn),
                ];
            }

            $rendicionId = (int) ($subsanacion['rendicion_id'] ?? 0);
            $rendicion = $this->repo->obtener($rendicionId);

            if (!$rendicion) {
                throw new \RuntimeException('La rendicion no existe');
            }

            if (array_key_exists('puntaje_total', $payload) || array_key_exists('aprobado', $payload)) {
                $puntajeTotal = array_key_exists('puntaje_total', $payload)
                    ? $this->validarNumeroRequerido($payload['puntaje_total'], 'puntaje_total')
                    : (float) ($rendicion->puntaje_total ?? 0);

                $this->repo->actualizarRendicionFinalizadaSubsanacion($rendicionId, [
                    'puntaje_total' => $puntajeTotal,
                    'aprobado' => $this->resolverAprobado($payload['aprobado'] ?? null, $puntajeTotal, $evaluacion),
                ], $conn);
            }

            return [
                'tipo_subsanacion' => 'examen',
                'rendicion' => (array) $this->repo->obtener($rendicionId),
                'subsanacion' => $this->repo->obtenerSubsanacionPorId($subsanacionId, $conn),
            ];
        });

        return array_merge($resultado, [
            'evaluacion' => (array) $evaluacion,
            'es_subsanacion' => true,
        ]);
    }

    public function listarSubsanaciones(int $evaluacionId): array
    {
        $evaluacion = $this->evaluacionRepo->obtener($evaluacionId);

        if (!$evaluacion) {
            throw new \RuntimeException('La evaluacion no existe');
        }

        return [
            'evaluacion' => (array) $evaluacion,
            'subsanaciones' => $this->repo->listarSubsanacionesPorEvaluacion($evaluacionId),
        ];
    }

    public function evaluar(int $evaluacionId, array $respuestas)
    {
        $correctasDb = $this->evaluacionRepo->obtenerRespuestasCorrectas($evaluacionId);

        $correctas = 0;
        $incorrectas = 0;
        $puntos = 0;

        foreach ($correctasDb as $c) {
            $respuesta = $respuestas[$c->pregunta_id] ?? null;

            if ($respuesta == $c->opcion_id) {
                $correctas++;
                $puntos += $c->puntaje;
            } else {
                $incorrectas++;
            }
        }

        $total = count($correctasDb);

        return [
            'correctas' => $correctas,
            'incorrectas' => $incorrectas,
            'puntos' => $puntos,
            'porcentaje' => $total > 0
                ? round(($correctas / $total) * 100, 1)
                : 0
        ];
    }

    private function contarCorrectas(int $rendicionId): int
    {
        $respuestas = $this->repo->listarRespuestas($rendicionId);
        $total = 0;

        foreach ($respuestas as $respuesta) {
            if ((int) ($respuesta->es_correcta ?? 0) === 1) {
                $total++;
            }
        }

        return $total;
    }

    private function esEvaluacionTrabajo(array $evaluacion): bool
    {
        return in_array((int) ($evaluacion['tipo_param_id'] ?? 0), [3, 4], true);
    }

    private function validarEnteroRequerido($valor, string $campo): int
    {
        if (!is_numeric($valor)) {
            throw new \InvalidArgumentException($campo . ' invalido');
        }

        return (int) $valor;
    }

    private function validarNumeroRequerido($valor, string $campo): float
    {
        if (!is_numeric($valor)) {
            throw new \InvalidArgumentException($campo . ' invalido');
        }

        return round((float) $valor, 2);
    }

    private function normalizarTextoOpcional($valor, ?int $maxLength = null): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        if ($maxLength !== null) {
            $texto = mb_substr($texto, 0, $maxLength);
        }

        return $texto;
    }

    private function resolverPayloadActualizacionSubsanacion(array $payload): array
    {
        $data = [];

        if (array_key_exists('motivo', $payload)) {
            $data['motivo'] = $this->normalizarTextoOpcional($payload['motivo'] ?? null, 150);
        }

        if (array_key_exists('observacion', $payload)) {
            $data['observacion'] = $this->normalizarTextoOpcional($payload['observacion'] ?? null);
        }

        if (array_key_exists('evidencia_archivo', $payload)) {
            $data['evidencia_archivo'] = $this->normalizarTextoOpcional(
                $payload['evidencia_archivo'] ?? null,
                500
            );
        }

        if (array_key_exists('usuario_id', $payload)) {
            $data['usuario_id'] = $this->validarEnteroRequerido($payload['usuario_id'], 'usuario_id');
        }

        return $data;
    }

    private function resolverAprobado($valor, float $puntajeTotal, $evaluacion): int
    {
        if ($valor !== null && $valor !== '') {
            if (!in_array((string) $valor, ['0', '1', 'true', 'false'], true) && !is_bool($valor) && !is_int($valor)) {
                throw new \InvalidArgumentException('aprobado invalido');
            }

            return filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? 1 : 0;
        }

        $puntajeAprobacion = is_array($evaluacion)
            ? (float) ($evaluacion['puntaje_aprobacion'] ?? 11)
            : (float) ($evaluacion->puntaje_aprobacion ?? 11);

        return $puntajeTotal >= $puntajeAprobacion ? 1 : 0;
    }

    private function resolverCriteriosSubsanacionTrabajo(int $evaluacionId, $criteriosPayload): array
    {
        if ($criteriosPayload === null || $criteriosPayload === []) {
            return [];
        }

        if (!is_array($criteriosPayload)) {
            throw new \InvalidArgumentException('criterios invalido');
        }

        $trabajoData = $this->evaluacionRepo->obtenerTrabajoPorEvaluacionId($evaluacionId);
        $rubrica = $trabajoData['trabajo']['rubrica'] ?? null;

        if (!$rubrica) {
            throw new \DomainException('La evaluacion no tiene rubrica configurada');
        }

        $criteriosPermitidos = [];

        foreach ($rubrica['criterios'] ?? [] as $criterio) {
            $criteriosPermitidos[(int) ($criterio['criterio_id'] ?? 0)] = true;
        }

        $criterios = [];

        foreach ($criteriosPayload as $criterio) {
            if (!is_array($criterio)) {
                throw new \InvalidArgumentException('criterios invalido');
            }

            $criterioId = $this->validarEnteroRequerido($criterio['criterio_id'] ?? null, 'criterio_id');

            if (!isset($criteriosPermitidos[$criterioId])) {
                throw new \DomainException('El criterio no pertenece a la rubrica de la evaluacion');
            }

            $criterios[] = [
                'criterio_id' => $criterioId,
                'puntaje_obtenido' => $this->validarNumeroRequerido(
                    $criterio['puntaje_obtenido'] ?? null,
                    'puntaje_obtenido'
                ),
                'comentario' => $this->normalizarTextoOpcional($criterio['comentario'] ?? null),
            ];
        }

        return $criterios;
    }

    private function normalizarCorreo(string $correo): string
    {
        $correo = trim(mb_strtolower($correo));

        if ($correo === '') {
            throw new \InvalidArgumentException('X-USER-EMAIL requerido');
        }

        return $correo;
    }

    private function normalizarCorreoPayload(string $correo): string
    {
        $correo = trim(mb_strtolower($correo));

        if ($correo === '') {
            throw new \InvalidArgumentException('alumno_correo invalido');
        }

        return $correo;
    }

    private function validarAccesoAlumnoTrabajo(
        int $evaluacionId,
        string $correo,
        array $evaluacion
    ): void {
        if (!$this->repo->alumnoTieneAccesoTrabajo($evaluacionId, $correo)) {
            throw new \DomainException('No autorizado');
        }

        if (!in_array((int) ($evaluacion['tipo_param_id'] ?? 0), [3, 4], true)) {
            throw new \DomainException('Solo aplica para evaluaciones de tipo trabajo');
        }

        if (empty($evaluacion['publicada'])) {
            throw new \DomainException('La evaluacion no esta publicada');
        }
    }

    private function construirEstadoEntrega(array $evaluacion, ?array $entrega): array
    {
        $finalizada = $this->esEntregaFinalizada($entrega);
        $fueraDePlazo = $this->estaFueraDePlazo($evaluacion);

        return [
            'entrega_id' => isset($entrega['entrega_id']) ? (int) $entrega['entrega_id'] : null,
            'estado' => $entrega['estado'] ?? 'sin_entrega',
            'finalizada' => $finalizada,
            'fecha_entrega' => $entrega['fecha_entrega'] ?? null,
            'observacion_alumno' => $entrega['observacion_alumno'] ?? null,
            'observacion_docente' => $entrega['observacion_docente'] ?? null,
            'feedback' => $entrega['observacion_docente'] ?? null,
            'puntaje_total' => isset($entrega['puntaje_total']) ? (float) $entrega['puntaje_total'] : null,
            'score' => isset($entrega['puntaje_total']) ? (float) $entrega['puntaje_total'] : null,
            'aprobado' => isset($entrega['aprobado']) ? (bool) $entrega['aprobado'] : null,
            'calificacion_id' => isset($entrega['calificacion_id']) ? (int) $entrega['calificacion_id'] : null,
            'fecha_correccion' => $entrega['fecha_correccion'] ?? null,
            'archivos' => $entrega['archivos'] ?? [],
            'puede_editar' => !$finalizada && !$fueraDePlazo,
            'max_archivos' => $this->maxArchivosEntrega(),
            'max_file_size_mb' => $this->maxFileSizeMbEntrega(),
            'allowed_extensions' => $this->extensionesPermitidasEntrega(),
            'fuera_de_plazo' => $fueraDePlazo,
        ];
    }

    private function esEntregaFinalizada(?array $entrega): bool
    {
        return in_array($entrega['estado'] ?? null, ['entregado', 'corregido'], true);
    }

    private function estaFueraDePlazo(array $evaluacion): bool
    {
        $fechaLimite = $evaluacion['fecha_limite'] ?? null;

        if (empty($fechaLimite)) {
            return false;
        }

        return Carbon::now()->gt(Carbon::parse($fechaLimite));
    }

    private function normalizarIds($ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_map('intval', $ids);

        return array_values(array_filter(array_unique($ids), fn ($id) => $id > 0));
    }

    private function normalizarArchivosSubidos(array $uploadedFiles): array
    {
        $archivos = [];

        foreach ($uploadedFiles as $file) {
            if (is_array($file)) {
                foreach ($file as $subfile) {
                    if ($subfile instanceof UploadedFile) {
                        $archivos[] = $subfile;
                    }
                }
                continue;
            }

            if ($file instanceof UploadedFile) {
                $archivos[] = $file;
            }
        }

        return $archivos;
    }

    private function contarArchivosActivosRestantes(array $archivosActuales, array $archivoIdsEliminar): int
    {
        if (empty($archivoIdsEliminar)) {
            return count($archivosActuales);
        }

        return count(array_filter($archivosActuales, function ($archivo) use ($archivoIdsEliminar) {
            return !in_array((int) ($archivo['archivo_id'] ?? 0), $archivoIdsEliminar, true);
        }));
    }

    private function validarArchivoEntrega(UploadedFile $archivo): void
    {
        $extension = mb_strtolower((string) $archivo->getClientOriginalExtension());

        if (!in_array($extension, $this->extensionesPermitidasEntrega(), true)) {
            throw new \DomainException('Tipo de archivo no permitido');
        }

        if (($archivo->getSize() ?? 0) > $this->maxBytesPorArchivoEntrega()) {
            throw new \DomainException(
                'El archivo excede el tamano maximo permitido de '
                . $this->maxFileSizeMbEntrega()
                . 'MB'
            );
        }
    }

    private function guardarArchivosEnDisco(
        int $evaluacionId,
        string $correo,
        array $archivos
    ): array {
        $paths = [];
        $folder = 'trabajos/' . $evaluacionId . '/' . sha1($correo);

        foreach ($archivos as $archivo) {
            $extension = mb_strtolower((string) $archivo->getClientOriginalExtension());
            $filename = Str::uuid()->toString() . ($extension ? '.' . $extension : '');
            $ruta = Storage::disk('files')->putFileAs($folder, $archivo, $filename);

            $paths[] = [
                'nombre_original' => $archivo->getClientOriginalName(),
                'ruta_archivo' => $ruta,
                'peso_bytes' => $archivo->getSize(),
                'mime_type' => $archivo->getMimeType(),
            ];
        }

        return $paths;
    }

    private function eliminarArchivosFisicos(array $archivos): void
    {
        foreach ($archivos as $archivo) {
            $ruta = $archivo['ruta_archivo'] ?? null;

            if ($ruta) {
                Storage::disk('files')->delete($ruta);
            }
        }
    }

    private function maxArchivosEntrega(): int
    {
        return max(1, (int) env('EVALUACION_TRABAJO_MAX_ARCHIVOS', 5));
    }

    private function maxFileSizeMbEntrega(): int
    {
        return max(1, (int) env('EVALUACION_TRABAJO_MAX_FILE_SIZE_MB', 50));
    }

    private function maxBytesPorArchivoEntrega(): int
    {
        return $this->maxFileSizeMbEntrega() * 1024 * 1024;
    }

    private function extensionesPermitidasEntrega(): array
    {
        $raw = (string) env(
            'EVALUACION_TRABAJO_ALLOWED_EXTENSIONS',
            'pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,jpg,jpeg,png,txt,csv,odt,ods,odp,json,yml,yaml'
        );

        return array_values(array_filter(array_map(function ($ext) {
            return trim(mb_strtolower($ext));
        }, explode(',', $raw))));
    }

    public function listarNotasAlumnoPorCurso(int $cursoId, string $correo): array
    {
        $correo = $this->normalizarCorreo($correo);

        Log::info('LISTAR_NOTAS_ALUMNO_POR_CURSO_REPO_REQUEST', [
            'metodo' => 'listarNotasCabeceraAlumno',
            'curso_id' => $cursoId,
            'correo' => $correo,
        ]);

        $notas = $this->repo->listarNotasCabeceraAlumno($cursoId, $correo);

        Log::info('LISTAR_NOTAS_ALUMNO_POR_CURSO_REPO_RESPONSE', [
            'metodo' => 'listarNotasCabeceraAlumno',
            'total' => count($notas),
            'data' => array_map(function ($row) {
                return (array) $row;
            }, $notas),
        ]);

        Log::info('LISTAR_NOTAS_ALUMNO_POR_CURSO_REPO_REQUEST', [
            'metodo' => 'listarCriteriosTrabajoAlumno',
            'curso_id' => $cursoId,
            'correo' => $correo,
        ]);

        $criterios = $this->repo->listarCriteriosTrabajoAlumno($cursoId, $correo);

        Log::info('LISTAR_NOTAS_ALUMNO_POR_CURSO_REPO_RESPONSE', [
            'metodo' => 'listarCriteriosTrabajoAlumno',
            'total' => count($criterios),
            'data' => array_map(function ($row) {
                return (array) $row;
            }, $criterios),
        ]);

        $criteriosPorEvaluacion = [];

        foreach ($criterios as $criterio) {
            $criterio = (array) $criterio;
            $evaluacionId = (int) $criterio['evaluacion_id'];

            $criteriosPorEvaluacion[$evaluacionId][] = $criterio;
        }

        return [
            'curso_id' => $cursoId,
            'alumno_correo' => $correo,
            'notas' => array_map(function ($row) use ($criteriosPorEvaluacion) {
                $nota = (array) $row;
                $evaluacionId = (int) $nota['evaluacion_id'];

                $nota['criterios'] = $criteriosPorEvaluacion[$evaluacionId] ?? [];

                return $nota;
            }, $notas),
        ];
    }

   public function obtenerArchivoEntregaParaRevision(int $archivoId): array
    {
        $archivo = $this->repo->obtenerArchivoEntregaPorId($archivoId);

        if (!$archivo) {
            throw new \RuntimeException('Archivo no encontrado');
        }

        return [
            'ruta' => $archivo->ruta_archivo,
            'nombre' => $archivo->nombre_original,
            'mime_type' => $archivo->mime_type,
        ];
    }
}
