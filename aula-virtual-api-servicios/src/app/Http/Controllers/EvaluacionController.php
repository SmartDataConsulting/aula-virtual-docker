<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EvaluacionService;
use App\Services\EvaluacionRendicionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Support\ApiCache;

class EvaluacionController extends Controller
{
    protected EvaluacionService $service;
    protected EvaluacionRendicionService $rendicionService;

    public function __construct(
        EvaluacionService $service,
        EvaluacionRendicionService $rendicionService
    )
    {
        $this->service = $service;
        $this->rendicionService = $rendicionService;
    }

    public function listarPorCurso(Request $request, $cursoId)
    {
        if (!is_numeric($cursoId)) {
            return response()->json(['error' => 'curso_id invalido'], 400);
        }

        $rows = $this->service->listarPorCurso((int)$cursoId);

        $courseName = null;
        $evaluations = [];

        foreach ($rows as $e) {

            if ($courseName === null && isset($e->curso_nombre)) {
                $courseName = $e->curso_nombre;
            }

            if (!$e->id) continue;

            $tipoDescripcion = $e->tipo_descripcion ?? null;
            $tipoNormalizado = $tipoDescripcion
                ? mb_strtolower(trim($tipoDescripcion), 'UTF-8')
                : '';

            $esTrabajo = str_contains($tipoNormalizado, 'trabajo');

            $evaluations[] = [
                'evaluacion_id' => (int)$e->id,
                'nombre' => $e->nombre,
                'tipo_param_id' => (int)$e->tipo_param_id,
                'tipo_descripcion' => $tipoDescripcion,
                'version' => (int)$e->version,
                'publicada' => (bool)$e->publicada,
                'peso' => isset($e->peso) ? (float)$e->peso : null,
                'tiempo_minutos' => isset($e->tiempo_minutos) ? (int)$e->tiempo_minutos : null,
                'puntaje_aprobacion' => isset($e->puntaje_aprobacion) ? (float)$e->puntaje_aprobacion : null,
                'activa' => (int)$e->activo,
                'created_at' => $e->created_at,
                'updated_at' => $e->updated_at,
            ];
        }

        return response()->json([
            'course' => [
                'id' => (int)$cursoId,
                'name' => $courseName
            ],
            'evaluations' => $evaluations
        ]);
    }

    public function resumenCalificacionesCurso(Request $request, $cursoId)
    {
        if (!is_numeric($cursoId)) {
            return response()->json(['error' => 'curso_id invalido'], 400);
        }

        $cursoId = (int) $cursoId;
        $cacheKey = "calificaciones_dashboard_curso_{$cursoId}";

        $payload = Cache::remember($cacheKey, 60, function () use ($cursoId) {
            return $this->service->obtenerDashboardCalificacionesCurso($cursoId);
        });

        return response()->json($payload);
    }

    public function crear(Request $request)
    {
        $start = microtime(true);

        $data = $request->only([
            'curso_id',
            'tipo',
            'nombre',
            'tiempo_minutos',
            'puntaje_aprobacion',
            'descripcion',
            'peso'
        ]);

        if (empty($data['curso_id'])) {
            return response()->json(['error' => 'curso_id requerido'], 400);
        }

        if (empty($data['tipo'])) {
            return response()->json(['error' => 'tipo requerido'], 400);
        }

        if (empty($data['nombre'])) {
            return response()->json(['error' => 'nombre requerido'], 400);
        }

        $data['tipo_param_id'] = $data['tipo'];
        unset($data['tipo']);

        $id = $this->service->crear($data);

        Log::info('evaluacion_crear', [
            'id' => (int)$id,
            'curso_id' => (int)$data['curso_id'],
            'ms' => round((microtime(true) - $start) * 1000)
        ]);

        ApiCache::bumpCourseSummary();

        return response()->json([
            'ok' => true,
            'evaluacion_id' => (int)$id
        ]);
    }

    public function autosave(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['error' => 'evaluacion_id invalido'], 400);
        }

        $payload = $request->all();

        $this->service->autosave((int)$evaluacionId, $payload);

        Log::info('evaluacion_autosave', [
            'evaluacion_id' => (int)$evaluacionId,
            'preguntas' => count($payload['preguntas'] ?? [])
        ]);

        return response()->json(['ok' => true]);
    }

    public function guardarTrabajo(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['error' => 'evaluacion_id invalido'], 400);
        }

        $data = $this->service->guardarTrabajo(
            (int) $evaluacionId,
            $request->all()
        );

        return response()->json($this->mapTrabajoResponse($data));
    }

    public function obtener(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['error' => 'evaluacion_id invalido'], 400);
        }

        $data = $this->service->obtenerEvaluacionCompleta((int)$evaluacionId);

        if (!$data) {
            return response()->json(['error' => 'evaluacion no encontrada'], 404);
        }

        return response()->json($data);
    }

    public function obtenerTrabajo(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['error' => 'evaluacion_id invalido'], 400);
        }

        $data = $this->service->obtenerTrabajoPorEvaluacionId((int) $evaluacionId);

        if (!$data) {
            return response()->json(['error' => 'evaluacion no encontrada'], 404);
        }

        return response()->json($this->mapTrabajoResponse($data));
    }

    public function obtenerDetalleRevision(Request $request, $evaluacionId, $entregaId)
    {
        if (!is_numeric($evaluacionId) || !is_numeric($entregaId)) {
            return response()->json(['message' => 'parametros invalidos'], 400);
        }

        try {
            $data = $this->rendicionService->obtenerDetalleRevision(
                (int) $evaluacionId,
                (int) $entregaId
            );

            return response()->json($this->mapDetalleRevisionResponse($data));
        } catch (\Throwable $e) {
            return $this->mapTrabajoAlumnoException($e);
        }
    }

    public function guardarDetalleRevision(Request $request, $evaluacionId, $entregaId)
    {
        if (!is_numeric($evaluacionId) || !is_numeric($entregaId)) {
            return response()->json(['message' => 'parametros invalidos'], 400);
        }

        try {
            $data = $this->rendicionService->guardarDetalleRevision(
                (int) $evaluacionId,
                (int) $entregaId,
                $request->all()
            );

            return response()->json($this->mapDetalleRevisionResponse($data));
        } catch (\Throwable $e) {
            return $this->mapTrabajoAlumnoException($e);
        }
    }
    public function obtenerTrabajoAlumno(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        try {
            $data = $this->rendicionService->obtenerTrabajoAlumno(
                (int) $evaluacionId,
                $this->obtenerCorreoAlumno($request)
            );

            return response()->json($this->mapTrabajoAlumnoResponse($data));
        } catch (\Throwable $e) {
            return $this->mapTrabajoAlumnoException($e);
        }
    }

    public function guardarEntregaTrabajo(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        try {
            $data = $this->rendicionService->guardarEntregaTrabajoBorrador(
                (int) $evaluacionId,
                $this->obtenerCorreoAlumno($request),
                $request->all(),
                $request->file()
            );

            return response()->json([
                'ok' => true,
                'message' => 'Entrega guardada en borrador',
                'entrega' => $this->mapEntregaPayload($data['entrega'] ?? null),
            ]);
        } catch (\Throwable $e) {
            return $this->mapTrabajoAlumnoException($e);
        }
    }

    public function finalizarEntregaTrabajo(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        try {
            $data = $this->rendicionService->finalizarEntregaTrabajo(
                (int) $evaluacionId,
                $this->obtenerCorreoAlumno($request),
                $request->all()
            );

            return response()->json([
                'ok' => true,
                'message' => 'Entrega finalizada correctamente',
                'entrega' => $this->mapEntregaPayload($data['entrega'] ?? null),
            ]);
        } catch (\Throwable $e) {
            return $this->mapTrabajoAlumnoException($e);
        }
    }

    public function descargarArchivoEntrega(Request $request, $archivoId)
    {
        if (!is_numeric($archivoId)) {
            return response()->json(['message' => 'archivo_id invalido'], 400);
        }

        try {
            $data = $this->rendicionService->obtenerArchivoEntregaParaDescarga(
                (int) $archivoId,
                $this->obtenerCorreoAlumno($request)
            );

            return Storage::disk('files')->download(
                $data['ruta'],
                $data['nombre']
            );
        } catch (\Throwable $e) {
            return $this->mapTrabajoAlumnoException($e);
        }
    }

    public function registrarSubsanacionExamen(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        try {
            $payload = $request->all();
            $this->agregarEvidenciaArchivoAlPayload($request, $payload);

            $data = $this->rendicionService->registrarSubsanacionExamen(
                (int) $evaluacionId,
                $payload
            );

            return response()->json($this->mapSubsanacionExamenResponse($data));
        } catch (\Throwable $e) {
            return $this->mapEvaluacionException($e);
        }
    }

    public function registrarSubsanacionTrabajo(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        try {
            $payload = $request->all();
            $this->agregarEvidenciaArchivoAlPayload($request, $payload);

            $data = $this->rendicionService->registrarSubsanacionTrabajo(
                (int) $evaluacionId,
                $payload
            );

            return response()->json($this->mapSubsanacionTrabajoResponse($data));
        } catch (\Throwable $e) {
            return $this->mapEvaluacionException($e);
        }
    }

    public function listarSubsanaciones(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        try {
            $data = $this->rendicionService->listarSubsanaciones((int) $evaluacionId);

            return response()->json($this->mapListadoSubsanacionesResponse($data));
        } catch (\Throwable $e) {
            return $this->mapEvaluacionException($e);
        }
    }

    public function actualizarSubsanacion(Request $request, $evaluacionId, $subsanacionId)
    {
        if (!is_numeric($evaluacionId) || !is_numeric($subsanacionId)) {
            return response()->json(['message' => 'parametros invalidos'], 400);
        }

        try {
            $payload = $request->all();
            $this->agregarEvidenciaArchivoAlPayload($request, $payload);

            $data = $this->rendicionService->actualizarSubsanacion(
                (int) $evaluacionId,
                (int) $subsanacionId,
                $payload
            );

            if (($data['tipo_subsanacion'] ?? null) === 'trabajo') {
                return response()->json($this->mapSubsanacionTrabajoResponse($data));
            }

            return response()->json($this->mapSubsanacionExamenResponse($data));
        } catch (\Throwable $e) {
            return $this->mapEvaluacionException($e);
        }
    }

    public function descargarEvidenciaSubsanacion(Request $request)
    {
        $path = ltrim(trim((string) $request->query('path', '')), '/');
        $filename = trim((string) $request->query('name', ''));

        if (
            $path === ''
            || !str_starts_with($path, 'subsanaciones/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
        ) {
            return response()->json(['message' => 'path invalido'], 400);
        }

        if (!Storage::disk('files')->exists($path)) {
            return response()->json(['message' => 'archivo no encontrado'], 404);
        }

        if ($filename === '') {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $filename = $extension !== ''
                ? 'Evidencia de subsanacion.' . $extension
                : 'Evidencia de subsanacion';
        }

        return Storage::disk('files')->download($path, $filename);
    }

    public function publicar(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['error' => 'evaluacion_id invalido'], 400);
        }

        try {

            $this->service->publicar((int)$evaluacionId);
            ApiCache::bumpCourseSummary();

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {

            return response()->json([
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function duplicar(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['error' => 'evaluacion_id invalido'], 400);
        }

        try {

            $newId = $this->service->duplicar((int)$evaluacionId);
            ApiCache::bumpCourseSummary();

            return response()->json([
                'ok' => true,
                'evaluacion_id' => (int)$newId
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function listarPublicadasPorCursoYTipo($cursoId, $tipoId)
    {
        if (!is_numeric($cursoId) || !is_numeric($tipoId)) {
            return response()->json([], 400);
        }

        $rows = $this->service
            ->listarPublicadasPorCursoYTipo((int)$cursoId, (int)$tipoId);

        return response()->json(array_map(fn($r) => [
            'id' => (int)$r->id,
            'name' => $r->nombre,
            'tipo_id' => (int)$r->tipo_param_id,
            'tipo' => $r->tipo_descripcion,
        ], $rows));
    }

    public function evaluar(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['error' => 'evaluacion_id invalido'], 400);
        }

        $respuestas = $request->input('respuestas', []);

        if (!is_array($respuestas)) {
            return response()->json(['error' => 'formato invalido'], 400);
        }

        return response()->json(
            $this->rendicionService->evaluar((int)$evaluacionId, $respuestas)
        );
    }

    private function mapTrabajoResponse(array $data): array
    {
        $evaluacion = $data['evaluacion'] ?? [];
        $trabajo = $data['trabajo'] ?? null;

        $tipoDescripcion = $evaluacion['tipo_descripcion'] ?? null;
        $evaluacionId = (int) ($evaluacion['id'] ?? $evaluacion['evaluacion_id'] ?? 0);

        return [
            'evaluacion' => [
                'evaluacion_id' => $evaluacionId,
                'nombre' => $evaluacion['nombre'] ?? null,
                'tipo_param_id' => isset($evaluacion['tipo_param_id'])
                    ? (int) $evaluacion['tipo_param_id']
                    : null,
                'tipo_descripcion' => $tipoDescripcion,
                'peso' => isset($evaluacion['peso'])
                    ? (float) $evaluacion['peso']
                    : null,
                'tiempo_minutos' => isset($evaluacion['tiempo_minutos'])
                    ? (int) $evaluacion['tiempo_minutos']
                    : null,
                'puntaje_aprobacion' => isset($evaluacion['puntaje_aprobacion'])
                    ? (float) $evaluacion['puntaje_aprobacion']
                    : null,
                'fecha_limite' => $evaluacion['fecha_limite'] ?? null,
                'puntaje_max' => isset($evaluacion['puntaje_max'])
                    ? (float) $evaluacion['puntaje_max']
                    : null,
                'publicada' => (bool) ($evaluacion['publicada'] ?? false),
            ],
            'trabajo' => $this->mapTrabajoPayload($trabajo),
        ];
    }

    private function mapTrabajoAlumnoResponse(array $data): array
    {
        return [
            'evaluacion' => $this->mapTrabajoResponse($data)['evaluacion'],
            'trabajo' => $this->mapTrabajoPayload($data['trabajo'] ?? null),
            'entrega' => $this->mapEntregaPayload($data['entrega'] ?? null),
        ];
    }

    private function mapSubsanacionExamenResponse(array $data): array
    {
        $evaluacion = $data['evaluacion'] ?? [];
        $rendicion = $data['rendicion'] ?? [];

        return [
            'evaluacion' => [
                'evaluacion_id' => isset($evaluacion['id']) ? (int) $evaluacion['id'] : null,
                'nombre' => $evaluacion['nombre'] ?? null,
                'tipo_param_id' => isset($evaluacion['tipo_param_id'])
                    ? (int) $evaluacion['tipo_param_id']
                    : null,
                'tipo_descripcion' => $evaluacion['tipo_descripcion'] ?? null,
                'puntaje_aprobacion' => isset($evaluacion['puntaje_aprobacion'])
                    ? (float) $evaluacion['puntaje_aprobacion']
                    : null,
            ],
            'rendicion' => [
                'rendicion_id' => isset($rendicion['id']) ? (int) $rendicion['id'] : null,
                'evaluacion_id' => isset($rendicion['evaluacion_id']) ? (int) $rendicion['evaluacion_id'] : null,
                'alumno_correo' => $rendicion['alumno_correo'] ?? null,
                'estado' => $rendicion['estado'] ?? null,
                'fecha_inicio' => $rendicion['fecha_inicio'] ?? null,
                'fecha_fin' => $rendicion['fecha_fin'] ?? null,
                'puntaje_total' => isset($rendicion['puntaje_total']) ? (float) $rendicion['puntaje_total'] : null,
                'aprobado' => isset($rendicion['aprobado']) ? (bool) $rendicion['aprobado'] : null,
            ],
            'es_subsanacion' => (bool) ($data['es_subsanacion'] ?? false),
            'subsanacion' => $this->mapSubsanacionPayload($data['subsanacion'] ?? null),
        ];
    }

    private function mapSubsanacionTrabajoResponse(array $data): array
    {
        $detalle = $this->mapDetalleRevisionResponse($data);

        return array_merge($detalle, [
            'es_subsanacion' => (bool) ($data['es_subsanacion'] ?? false),
            'subsanacion' => $this->mapSubsanacionPayload($data['subsanacion'] ?? null),
        ]);
    }

    private function mapListadoSubsanacionesResponse(array $data): array
    {
        $evaluacion = $data['evaluacion'] ?? [];

        return [
            'evaluacion' => [
                'evaluacion_id' => isset($evaluacion['id']) ? (int) $evaluacion['id'] : null,
                'nombre' => $evaluacion['nombre'] ?? null,
                'tipo_param_id' => isset($evaluacion['tipo_param_id'])
                    ? (int) $evaluacion['tipo_param_id']
                    : null,
                'tipo_descripcion' => $evaluacion['tipo_descripcion'] ?? null,
            ],
            'subsanaciones' => array_map(function ($subsanacion) {
                $tipo = $subsanacion['tipo_subsanacion'] ?? null;

                return [
                    'subsanacion_id' => isset($subsanacion['subsanacion_id'])
                        ? (int) $subsanacion['subsanacion_id']
                        : null,
                    'tipo' => $tipo,
                    'evaluacion_id' => isset($subsanacion['evaluacion_id'])
                        ? (int) $subsanacion['evaluacion_id']
                        : null,
                    'rendicion_id' => isset($subsanacion['rendicion_id'])
                        ? (int) $subsanacion['rendicion_id']
                        : null,
                    'calificacion_id' => isset($subsanacion['calificacion_id'])
                        ? (int) $subsanacion['calificacion_id']
                        : null,
                    'entrega_id' => isset($subsanacion['entrega_id']) ? (int) $subsanacion['entrega_id'] : null,
                    'alumno_correo' => $tipo === 'examen'
                        ? ($subsanacion['examen_alumno_correo'] ?? null)
                        : ($subsanacion['trabajo_alumno_correo'] ?? null),
                    'puntaje_total' => $tipo === 'examen'
                        ? (isset($subsanacion['examen_puntaje_total']) ? (float) $subsanacion['examen_puntaje_total'] : null)
                        : (isset($subsanacion['trabajo_puntaje_total']) ? (float) $subsanacion['trabajo_puntaje_total'] : null),
                    'aprobado' => $tipo === 'examen'
                        ? (isset($subsanacion['examen_aprobado']) ? (bool) $subsanacion['examen_aprobado'] : null)
                        : (isset($subsanacion['trabajo_aprobado']) ? (bool) $subsanacion['trabajo_aprobado'] : null),
                    'motivo' => $subsanacion['motivo'] ?? null,
                    'observacion' => $subsanacion['observacion'] ?? null,
                    'evidencia_archivo' => $subsanacion['evidencia_archivo'] ?? null,
                    'usuario_id' => isset($subsanacion['usuario_id']) ? (int) $subsanacion['usuario_id'] : null,
                    'created_at' => $subsanacion['created_at'] ?? null,
                    'updated_at' => $subsanacion['updated_at'] ?? null,
                ];
            }, $data['subsanaciones'] ?? []),
        ];
    }

    private function mapDetalleRevisionResponse(array $data): array
    {
        $evaluacion = $data['evaluacion'] ?? [];
        $trabajo = $data['trabajo'] ?? null;
        $rubrica = $data['rubrica'] ?? null;

        return [
            'evaluacion' => array_merge(
                $this->mapTrabajoResponse(['evaluacion' => $evaluacion, 'trabajo' => null])['evaluacion'],
                [
                    'participantes' => array_map(function ($participante) {
                        return [
                            'id' => isset($participante['id']) ? (int) $participante['id'] : null,
                            'nombres' => $participante['NOMBRES'] ?? null,
                            'apellidos' => $participante['APELLIDOS'] ?? null,
                            'alumno' => $participante['alumno'] ?? null,
                            'correo_personal' => $participante['CORREO_PERSONAL'] ?? null,
                            'telefono' => $participante['TELEFONO'] ?? null,
                            'rindio' => isset($participante['rindio']) ? (int) $participante['rindio'] : 0,
                            'corregido' => isset($participante['corregido']) ? (int) $participante['corregido'] : 0,
                            'rendicion_id' => isset($participante['rendicion_id'])
                                ? (int) $participante['rendicion_id']
                                : null,
                            'entrega_id' => isset($participante['entrega_id'])
                                ? (int) $participante['entrega_id']
                                : null,
                            'calificacion_id' => isset($participante['calificacion_id'])
                                ? (int) $participante['calificacion_id']
                                : null,
                        ];
                    }, $evaluacion['participantes'] ?? []),
                ]
            ),
            'trabajo' => $trabajo ? array_merge(
                $this->mapTrabajoPayload($trabajo) ?? [],
                [
                    'entrega' => $this->mapEntregaPayload($trabajo['entrega'] ?? null),
                ]
            ) : null,
            'rubrica' => $rubrica ? [
                'rubrica_id' => isset($rubrica['rubrica_id']) ? (int) $rubrica['rubrica_id'] : null,
                'evaluacion_id' => isset($rubrica['evaluacion_id']) ? (int) $rubrica['evaluacion_id'] : null,
                'entrega_id' => isset($rubrica['entrega_id']) ? (int) $rubrica['entrega_id'] : null,
                'alumno_correo' => $rubrica['alumno_correo'] ?? null,
                'calificacion_id' => isset($rubrica['calificacion_id']) ? (int) $rubrica['calificacion_id'] : null,
                'nombre' => $rubrica['nombre'] ?? null,
                'puntaje_total' => isset($rubrica['puntaje_total']) ? (float) $rubrica['puntaje_total'] : null,
                'aprobado' => isset($rubrica['aprobado']) ? (bool) $rubrica['aprobado'] : null,
                'observacion_docente' => $rubrica['observacion_docente'] ?? null,
                'fecha_correccion' => $rubrica['fecha_correccion'] ?? null,
                'criterios' => array_map(function ($criterio) {
                    return [
                        'criterio_id' => isset($criterio['criterio_id']) ? (int) $criterio['criterio_id'] : null,
                        'rubrica_id' => isset($criterio['rubrica_id']) ? (int) $criterio['rubrica_id'] : null,
                        'nombre' => $criterio['nombre'] ?? null,
                        'descripcion' => $criterio['descripcion'] ?? null,
                        'puntaje_max' => isset($criterio['puntaje_max']) ? (float) $criterio['puntaje_max'] : null,
                        'orden' => isset($criterio['orden']) ? (int) $criterio['orden'] : null,
                        'detalle_id' => isset($criterio['detalle_id']) ? (int) $criterio['detalle_id'] : null,
                        'puntaje_obtenido' => isset($criterio['puntaje_obtenido'])
                            ? (float) $criterio['puntaje_obtenido']
                            : null,
                        'comentario' => $criterio['comentario'] ?? null,
                    ];
                }, $rubrica['criterios'] ?? []),
            ] : null,
        ];
    }

    private function mapTrabajoPayload($trabajo): ?array
    {
        if (!$trabajo) {
            return null;
        }

        $rubrica = $trabajo['rubrica'] ?? null;

        return [
            'trabajo_id' => isset($trabajo['trabajo_id']) ? (int) $trabajo['trabajo_id'] : null,
            'evaluacion_id' => isset($trabajo['evaluacion_id']) ? (int) $trabajo['evaluacion_id'] : null,
            'descripcion' => $trabajo['descripcion'] ?? null,
            'fecha_limite' => $trabajo['fecha_limite'] ?? null,
            'puntaje_max' => isset($trabajo['puntaje_max'])
                ? (float) $trabajo['puntaje_max']
                : null,
            'rubrica' => $rubrica ? [
                'rubrica_id' => isset($rubrica['rubrica_id']) ? (int) $rubrica['rubrica_id'] : null,
                'trabajo_id' => isset($rubrica['trabajo_id']) ? (int) $rubrica['trabajo_id'] : null,
                'nombre' => $rubrica['nombre'] ?? null,
                'criterios' => array_map(function ($criterio) {
                    return [
                        'criterio_id' => isset($criterio['criterio_id']) ? (int) $criterio['criterio_id'] : null,
                        'nombre' => $criterio['nombre'] ?? null,
                        'descripcion' => $criterio['descripcion'] ?? null,
                        'puntaje_max' => isset($criterio['puntaje_max']) ? (float) $criterio['puntaje_max'] : null,
                        'orden' => isset($criterio['orden']) ? (int) $criterio['orden'] : null,
                    ];
                }, $rubrica['criterios'] ?? [])
            ] : null,
        ];
    }

    private function mapEntregaPayload($entrega): array
    {
        if (!$entrega) {
            return [
                'entrega_id' => null,
                'estado' => 'sin_entrega',
                'finalizada' => false,
                'fecha_entrega' => null,
                'observacion_alumno' => null,
                'archivos' => [],
                'puede_editar' => false,
                'max_archivos' => (int) env('EVALUACION_TRABAJO_MAX_ARCHIVOS', 5),
                'max_file_size_mb' => (int) env('EVALUACION_TRABAJO_MAX_FILE_SIZE_MB', 50),
                'allowed_extensions' => $this->allowedWorkSubmissionExtensions(),
                'fuera_de_plazo' => false,
            ];
        }

        return [
            'entrega_id' => isset($entrega['entrega_id']) ? (int) $entrega['entrega_id'] : null,
            'estado' => $entrega['estado'] ?? 'sin_entrega',
            'finalizada' => in_array($entrega['estado'] ?? null, ['entregado', 'corregido', 'finalizado', 'finalizada'], true),
            'fecha_entrega' => $entrega['fecha_entrega'] ?? null,
            'observacion_alumno' => $entrega['observacion_alumno'] ?? null,
            'observacion_docente' => $entrega['observacion_docente'] ?? null,
            'feedback' => $entrega['observacion_docente'] ?? null,
            'puntaje_total' => isset($entrega['puntaje_total']) ? (float) $entrega['puntaje_total'] : null,
            'aprobado' => isset($entrega['aprobado']) ? (bool) $entrega['aprobado'] : null,
            'calificacion_id' => isset($entrega['calificacion_id']) ? (int) $entrega['calificacion_id'] : null,
            'fecha_correccion' => $entrega['fecha_correccion'] ?? null,
            'archivos' => array_map(function ($archivo) {
                return [
                    'archivo_id' => isset($archivo['archivo_id']) ? (int) $archivo['archivo_id'] : null,
                    'nombre_original' => $archivo['nombre_original'] ?? null,
                    'url_descarga' => isset($archivo['archivo_id'])
                        ? $this->buildArchivoEntregaDownloadUrl((int) $archivo['archivo_id'])
                        : null,
                    'peso_bytes' => isset($archivo['peso_bytes']) ? (int) $archivo['peso_bytes'] : null,
                    'mime_type' => $archivo['mime_type'] ?? null,
                ];
            }, $entrega['archivos'] ?? []),
            'puede_editar' => (bool) ($entrega['puede_editar'] ?? false),
            'max_archivos' => isset($entrega['max_archivos']) ? (int) $entrega['max_archivos'] : (int) env('EVALUACION_TRABAJO_MAX_ARCHIVOS', 5),
            'max_file_size_mb' => isset($entrega['max_file_size_mb']) ? (int) $entrega['max_file_size_mb'] : (int) env('EVALUACION_TRABAJO_MAX_FILE_SIZE_MB', 50),
            'allowed_extensions' => array_values((array) ($entrega['allowed_extensions'] ?? $this->allowedWorkSubmissionExtensions())),
            'fuera_de_plazo' => (bool) ($entrega['fuera_de_plazo'] ?? false),
        ];
    }

    private function allowedWorkSubmissionExtensions(): array
    {
        $raw = (string) env(
            'EVALUACION_TRABAJO_ALLOWED_EXTENSIONS',
            'pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,jpg,jpeg,png,txt,csv,odt,ods,odp,json,yml,yaml'
        );

        return array_values(array_filter(array_map(
            static fn ($extension) => trim(mb_strtolower((string) $extension)),
            explode(',', $raw)
        )));
    }

    private function mapSubsanacionPayload($subsanacion): ?array
    {
        if (!$subsanacion) {
            return null;
        }

        return [
            'subsanacion_id' => isset($subsanacion['subsanacion_id']) ? (int) $subsanacion['subsanacion_id'] : null,
            'evaluacion_id' => isset($subsanacion['evaluacion_id']) ? (int) $subsanacion['evaluacion_id'] : null,
            'rendicion_id' => isset($subsanacion['rendicion_id']) ? (int) $subsanacion['rendicion_id'] : null,
            'calificacion_id' => isset($subsanacion['calificacion_id']) ? (int) $subsanacion['calificacion_id'] : null,
            'motivo' => $subsanacion['motivo'] ?? null,
            'observacion' => $subsanacion['observacion'] ?? null,
            'evidencia_archivo' => $subsanacion['evidencia_archivo'] ?? null,
            'usuario_id' => isset($subsanacion['usuario_id']) ? (int) $subsanacion['usuario_id'] : null,
            'created_at' => $subsanacion['created_at'] ?? null,
            'updated_at' => $subsanacion['updated_at'] ?? null,
        ];
    }

    private function agregarEvidenciaArchivoAlPayload(Request $request, array &$payload): void
    {
        $campoArchivo = $request->hasFile('evidencia_archivo')
            ? 'evidencia_archivo'
            : ($request->hasFile('archivo') ? 'archivo' : null);

        if ($campoArchivo === null) {
            return;
        }

        $file = $request->file($campoArchivo);
        $path = Storage::disk('files')->putFile('subsanaciones', $file);

        $payload['evidencia_archivo'] = $path;
    }

    private function obtenerCorreoAlumno(Request $request): string
    {
        return (string) $request->header('X-USER-EMAIL', '');
    }

    private function buildArchivoEntregaDownloadUrl(int $archivoId): string
    {
        $path = '/v1/alumno/evaluaciones/entregas/archivos/' . $archivoId . '/descargar';
        $appUrl = rtrim((string) env('APP_URL', ''), '/');

        return $appUrl !== '' ? ($appUrl . $path) : $path;
    }

    private function mapTrabajoAlumnoException(\Throwable $e)
    {
        if ($e instanceof \InvalidArgumentException) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        if ($e instanceof \RuntimeException) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        if ($e instanceof \DomainException) {
            $status = $e->getMessage() === 'No autorizado' ? 403 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        Log::error('evaluacion_trabajo_alumno_error', [
            'message' => $e->getMessage(),
        ]);

        return response()->json(['message' => 'Error interno del servidor'], 500);
    }

    private function mapEvaluacionException(\Throwable $e)
    {
        if ($e instanceof \InvalidArgumentException) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        if ($e instanceof \RuntimeException) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        if ($e instanceof \DomainException) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Log::error('evaluacion_subsanacion_error', [
            'message' => $e->getMessage(),
        ]);

        return response()->json(['message' => 'Error interno del servidor'], 500);
    }

    public function listarParticipantes($evaluacionId)
    {
        try {

            $rows = $this->service->listarParticipantes((int) $evaluacionId);

            $data = array_map(function ($r) {
                return [
                    'id' => (int) $r->id,
                    'evaluacion_id' => isset($r->evaluacion_id) ? (int) $r->evaluacion_id : null,
                    'curso_sesion_evaluacion_id' => isset($r->curso_sesion_evaluacion_id)
                        ? (int) $r->curso_sesion_evaluacion_id
                        : null,
                    'nombres' => $r->NOMBRES,
                    'apellidos' => $r->APELLIDOS,
                    'alumno' => $r->alumno,
                    'correo_personal' => $r->CORREO_PERSONAL,
                    'telefono' => $r->TELEFONO ?? null,
                    'rindio' => (int) $r->rindio,
                    'corregido' => (int) $r->corregido,
                    'rendicion_id' => isset($r->rendicion_id) ? (int) $r->rendicion_id : null,
                    'rendicion_estado' => $r->rendicion_estado ?? null,
                    'puntaje_total' => isset($r->puntaje_total) ? (float) $r->puntaje_total : null,
                    'fecha_fin' => $r->fecha_fin ?? null,
                    'entrega_id' => isset($r->entrega_id) ? (int) $r->entrega_id : null,
                    'entrega_estado' => $r->entrega_estado ?? null,
                    'finalizada' => in_array($r->entrega_estado ?? null, ['entregado', 'corregido'], true),
                    'fecha_entrega' => $r->fecha_entrega ?? null,
                    'calificacion_id' => isset($r->calificacion_id) ? (int) $r->calificacion_id : null,
                    'fecha_correccion' => $r->fecha_correccion ?? null,
                ];
            }, $rows);

            return response()->json($data);

        } catch (\Throwable $e) {

            $correlationId = (string) \Illuminate\Support\Str::uuid();

            Log::error('Error en listar participantes evaluaciÃƒÂ³n', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error interno',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }
}



