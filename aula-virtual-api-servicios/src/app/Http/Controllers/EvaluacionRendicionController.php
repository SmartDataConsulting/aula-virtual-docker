<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\EvaluacionRendicionService;

class EvaluacionRendicionController extends Controller
{
    protected EvaluacionRendicionService $service;

    public function __construct(EvaluacionRendicionService $service)
    {
        $this->service = $service;
    }

    public function obtenerOIniciar(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        $alumnoCorreo = $this->obtenerAlumnoCorreo($request);

        if ($alumnoCorreo === '') {
            return response()->json(['message' => 'alumno_correo invalido'], 400);
        }

        try {
            $data = $this->service->obtenerOIniciar(
                (int) $evaluacionId,
                $alumnoCorreo
            );

            return response()->json($this->mapRendicionPayload($data));
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }
    }

    public function guardarRespuesta(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        $alumnoCorreo = $this->obtenerAlumnoCorreo($request);

        if ($alumnoCorreo === '') {
            return response()->json(['message' => 'alumno_correo invalido'], 400);
        }

        $preguntaId = $request->input('pregunta_id');
        $opcionId = $request->input('opcion_id');

        if (!is_numeric($preguntaId)) {
            return response()->json(['message' => 'pregunta_id invalido'], 400);
        }

        if ($opcionId !== null && $opcionId !== '' && !is_numeric($opcionId)) {
            return response()->json(['message' => 'opcion_id invalido'], 400);
        }

        try {
            $data = $this->service->guardarRespuesta(
                (int) $evaluacionId,
                $alumnoCorreo,
                (int) $preguntaId,
                ($opcionId === null || $opcionId === '') ? null : (int) $opcionId
            );

            return response()->json([
                'ok' => true,
                'rendicion' => [
                    'rendicion_id' => (int) $data['rendicion_id'],
                    'pregunta_id' => (int) $data['pregunta_id'],
                    'opcion_id' => isset($data['opcion_id']) ? (int) $data['opcion_id'] : null,
                    'es_correcta' => isset($data['es_correcta']) ? (int) $data['es_correcta'] : null,
                    'puntaje_obtenido' => isset($data['puntaje_obtenido'])
                        ? (float) $data['puntaje_obtenido']
                        : 0,
                    'respondidas' => isset($data['respondidas']) ? (int) $data['respondidas'] : 0,
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }
    }

    public function obtenerResultadoParcial(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        $alumnoCorreo = $this->obtenerAlumnoCorreo($request);

        if ($alumnoCorreo === '') {
            return response()->json(['message' => 'alumno_correo invalido'], 400);
        }

        try {
            $data = $this->service->obtenerResultadoParcial(
                (int) $evaluacionId,
                $alumnoCorreo
            );

            return response()->json($this->mapResultadoParcialPayload($data));
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }
    }

    public function finalizar(Request $request, $evaluacionId)
    {
        if (!is_numeric($evaluacionId)) {
            return response()->json(['message' => 'evaluacion_id invalido'], 400);
        }

        $alumnoCorreo = $this->obtenerAlumnoCorreo($request);

        if ($alumnoCorreo === '') {
            return response()->json(['message' => 'alumno_correo invalido'], 400);
        }

        try {
            $data = $this->service->finalizar(
                (int) $evaluacionId,
                $alumnoCorreo
            );

            return response()->json($this->mapResultadoFinalPayload($data));
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }
    }

    public function obtenerResultadoFinal(Request $request, $rendicionId)
    {
        if (!is_numeric($rendicionId)) {
            return response()->json(['message' => 'rendicion_id invalido'], 400);
        }

        try {
            $data = $this->service->obtenerResultadoFinal((int) $rendicionId);

            return response()->json($this->mapResultadoFinalPayload($data));
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }
    }

    private function mapRendicionPayload(array $data): array
    {
        $evaluacion = $data['evaluacion'] ?? [];
        $rendicion = $data['rendicion'] ?? [];
        $respuestas = $data['respuestas'] ?? [];

        return [
            'evaluacion' => [
                'evaluacion_id' => isset($evaluacion['id']) ? (int) $evaluacion['id'] : null,
                'nombre' => $evaluacion['nombre'] ?? null,
                'tipo_param_id' => isset($evaluacion['tipo_param_id'])
                    ? (int) $evaluacion['tipo_param_id']
                    : null,
                'tipo_descripcion' => $evaluacion['tipo_descripcion'] ?? null,
                'tiempo_minutos' => isset($evaluacion['tiempo_minutos'])
                    ? (int) $evaluacion['tiempo_minutos']
                    : null,
                'puntaje_aprobacion' => isset($evaluacion['puntaje_aprobacion'])
                    ? (float) $evaluacion['puntaje_aprobacion']
                    : null,
                'publicada' => (bool) ($evaluacion['publicada'] ?? false),
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
                'respondidas' => isset($data['respondidas']) ? (int) $data['respondidas'] : 0,
            ],
            'respuestas' => array_map(function ($respuesta) {
                return [
                    'respuesta_id' => isset($respuesta['id']) ? (int) $respuesta['id'] : null,
                    'pregunta_id' => isset($respuesta['pregunta_id']) ? (int) $respuesta['pregunta_id'] : null,
                    'opcion_id' => isset($respuesta['opcion_id']) ? (int) $respuesta['opcion_id'] : null,
                    'es_correcta' => isset($respuesta['es_correcta']) ? (int) $respuesta['es_correcta'] : null,
                    'puntaje_obtenido' => isset($respuesta['puntaje_obtenido'])
                        ? (float) $respuesta['puntaje_obtenido']
                        : 0,
                ];
            }, $respuestas),
        ];
    }

    private function mapResultadoParcialPayload(array $data): array
    {
        $rendicion = $data['rendicion'] ?? null;

        return [
            'rendicion' => $rendicion ? [
                'rendicion_id' => isset($rendicion['id']) ? (int) $rendicion['id'] : null,
                'evaluacion_id' => isset($rendicion['evaluacion_id']) ? (int) $rendicion['evaluacion_id'] : null,
                'alumno_correo' => $rendicion['alumno_correo'] ?? null,
                'estado' => $rendicion['estado'] ?? null,
                'fecha_inicio' => $rendicion['fecha_inicio'] ?? null,
                'fecha_fin' => $rendicion['fecha_fin'] ?? null,
            ] : null,
            'respondidas' => isset($data['respondidas']) ? (int) $data['respondidas'] : 0,
            'puntaje' => isset($data['puntaje']) ? (float) $data['puntaje'] : 0,
            'respuestas' => array_map(function ($respuesta) {
                return [
                    'respuesta_id' => isset($respuesta['id']) ? (int) $respuesta['id'] : null,
                    'pregunta_id' => isset($respuesta['pregunta_id']) ? (int) $respuesta['pregunta_id'] : null,
                    'opcion_id' => isset($respuesta['opcion_id']) ? (int) $respuesta['opcion_id'] : null,
                    'es_correcta' => isset($respuesta['es_correcta']) ? (int) $respuesta['es_correcta'] : null,
                    'puntaje_obtenido' => isset($respuesta['puntaje_obtenido'])
                        ? (float) $respuesta['puntaje_obtenido']
                        : 0,
                ];
            }, $data['respuestas'] ?? []),
        ];
    }

    private function mapResultadoFinalPayload(array $data): array
    {
        $evaluacion = $data['evaluacion'] ?? [];
        $rendicion = $data['rendicion'] ?? [];
        $respuestas = $data['respuestas'] ?? [];

        return [
            'evaluacion' => $evaluacion ? [
                'evaluacion_id' => isset($evaluacion['id']) ? (int) $evaluacion['id'] : null,
                'nombre' => $evaluacion['nombre'] ?? null,
                'puntaje_aprobacion' => isset($evaluacion['puntaje_aprobacion'])
                    ? (float) $evaluacion['puntaje_aprobacion']
                    : null,
            ] : null,
            'rendicion' => [
                'rendicion_id' => isset($rendicion['id']) ? (int) $rendicion['id'] : null,
                'evaluacion_id' => isset($rendicion['evaluacion_id']) ? (int) $rendicion['evaluacion_id'] : null,
                'alumno_correo' => $rendicion['alumno_correo'] ?? null,
                'estado' => $rendicion['estado'] ?? null,
                'fecha_inicio' => $rendicion['fecha_inicio'] ?? null,
                'fecha_fin' => $rendicion['fecha_fin'] ?? null,
                'puntaje_total' => isset($data['puntaje_total'])
                    ? (float) $data['puntaje_total']
                    : (isset($rendicion['puntaje_total']) ? (float) $rendicion['puntaje_total'] : 0),
                'aprobado' => isset($data['aprobado'])
                    ? (bool) $data['aprobado']
                    : (bool) ($rendicion['aprobado'] ?? false),
                'correctas' => isset($data['correctas']) ? (int) $data['correctas'] : 0,
                'incorrectas' => isset($data['incorrectas']) ? (int) $data['incorrectas'] : 0,
                'respondidas' => isset($data['respondidas']) ? (int) $data['respondidas'] : 0,
                'total_preguntas' => isset($data['total_preguntas']) ? (int) $data['total_preguntas'] : null,
            ],
            'respuestas' => array_map(function ($respuesta) {
                return [
                    'respuesta_id' => isset($respuesta['id']) ? (int) $respuesta['id'] : null,
                    'pregunta_id' => isset($respuesta['pregunta_id']) ? (int) $respuesta['pregunta_id'] : null,
                    'opcion_id' => isset($respuesta['opcion_id']) ? (int) $respuesta['opcion_id'] : null,
                    'es_correcta' => isset($respuesta['es_correcta']) ? (int) $respuesta['es_correcta'] : null,
                    'puntaje_obtenido' => isset($respuesta['puntaje_obtenido'])
                        ? (float) $respuesta['puntaje_obtenido']
                        : 0,
                ];
            }, $respuestas),
        ];
    }

    private function obtenerAlumnoCorreo(Request $request): string
    {
        return trim((string) $request->header('X-USER-EMAIL', ''));
    }

    private function mapException(\Throwable $e)
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

        Log::error('evaluacion_rendicion_error', [
            'message' => $e->getMessage(),
        ]);

        return response()->json(['message' => 'Error interno del servidor'], 500);
    }

    public function listarNotasAlumnoPorCurso(Request $request, $cursoId)
    {
        if (!is_numeric($cursoId)) {
            return response()->json(['message' => 'curso_id invalido'], 400);
        }

        $alumnoCorreo = $this->obtenerAlumnoCorreo($request);

        if ($alumnoCorreo === '') {
            return response()->json(['message' => 'alumno_correo invalido'], 400);
        }

        try {
            $data = $this->service->listarNotasAlumnoPorCurso(
                (int) $cursoId,
                $alumnoCorreo
            );

            return response()->json([
                'curso_id' => (int) $data['curso_id'],
                'alumno_correo' => $data['alumno_correo'],
                'notas' => array_map(function ($nota) {
                    return [
                        'evaluacion_id' => isset($nota['evaluacion_id']) ? (int) $nota['evaluacion_id'] : null,
                        'evaluacion' => $nota['evaluacion'] ?? null,
                        'tipo_evaluacion' => $nota['tipo_evaluacion'] ?? null,
                        'fecha' => $nota['fecha'] ?? null,
                        'nota' => isset($nota['nota']) ? (float) $nota['nota'] : null,
                        'peso' => isset($nota['peso']) ? (float) $nota['peso'] : null,
                        'criterios' => array_map(function ($criterio) {
                            return [
                                'criterio_id' => isset($criterio['criterio_id']) ? (int) $criterio['criterio_id'] : null,
                                'criterio' => $criterio['criterio'] ?? null,
                                'peso_criterio' => isset($criterio['peso_criterio']) ? (float) $criterio['peso_criterio'] : null,
                                'nivel_id' => isset($criterio['nivel_id']) ? (int) $criterio['nivel_id'] : null,
                                'puntaje' => isset($criterio['puntaje']) ? (float) $criterio['puntaje'] : null,
                                'comentario' => $criterio['comentario'] ?? null,
                            ];
                        }, $nota['criterios'] ?? []),
                    ];
                }, $data['notas'] ?? []),
            ]);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }
    }

    public function descargarArchivoEntregaBackoffice($archivoId)
    {
        if (!is_numeric($archivoId)) {
            return response()->json(['message' => 'archivo_id invalido'], 400);
        }

        try {
            $data = $this->service->obtenerArchivoEntregaParaRevision(
                (int) $archivoId
            );

            return response()->download(
                storage_path('app/files/' . $data['ruta']),
                $data['nombre'],
                [
                    'Content-Type' => $data['mime_type'] ?? 'application/octet-stream'
                ]
            );
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }
    }
}
