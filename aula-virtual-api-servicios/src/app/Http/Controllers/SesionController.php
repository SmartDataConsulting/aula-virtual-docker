<?php

namespace App\Http\Controllers;

 
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Routing\Controller as BaseController;
use App\Services\SesionService;

class SesionController extends BaseController
{
    protected SesionService $service;

    public function __construct(SesionService $service)
    {
        $this->service = $service;
    }

    /**
     * Lista sesiones por curso (alumno / profesor)
     */
    public function listarPorCurso($cursoId)
    {
        $start = microtime(true);

        if (!is_numeric($cursoId)) {
            return response()->json(['error' => 'curso_id invalido'], 400);
        }

        $rol = request()->header('X-USER-ROL');
        $correo = request()->header('X-USER-EMAIL');

        if (!$rol) {
            abort(400, 'Missing X-USER-ROL header');
        }

        if ($rol !== 'admin' && $rol !== 'operador' && !$correo) {
            abort(400, 'Missing X-USER-EMAIL header');
        }

        if ($rol === 'admin' || $rol === 'operador') {
            $rows = $this->service->listarPorCursoProfesor((int)$cursoId, $rol);
        } else {
            $rows = $this->service->listarPorCursoAlumno(
                (int)$cursoId,
                $correo,
                $rol
            );
        }

        $data = array_map(function ($s) use ($rol) {

            $base = [
                'id' => (int) $s->id,
                'curso_edicion_id' => (int) $s->curso_edicion_id,
                'numero' => (int) $s->numero,
                'fecha' => $s->fecha,
                'hora_inicio' => $s->hora_inicio,
                'hora_fin' => $s->hora_fin,
                'duracion' => (int) $s->duracion,
                'estado' => $s->estado,
                'curso' => $s->curso_nombre ?? null,
                'docente' => $s->curso_docente ?? null,
                'edicion' => $s->curso_edicion ?? null,
                'video_status' => $s->video_status ?? null,
                'video_drive_file_id' => $s->video_drive_file_id ?? null,
                'video_uploaded_at' => $s->video_uploaded_at ?? null,
                'video_filesize' => isset($s->video_filesize) ? (int) $s->video_filesize : null,
                'video_chat_drive_file_id' => $s->video_chat_drive_file_id ?? null,
                'video_chat_titulo' => $s->video_chat_titulo ?? null,
                'video_chat_filesize' => isset($s->video_chat_filesize) ? (int) $s->video_chat_filesize : null,
                'video_chat_uploaded_at' => $s->video_chat_uploaded_at ?? null,
                'encuesta_id' => $s->encuesta_id ?? null,
                'encuesta_respondida' => (bool) ($s->encuesta_respondida ?? false),
                'surveys' => $s->surveys ?? [],
                'tiene_evaluacion' => (bool) ($s->tiene_evaluacion ?? false),
                'evaluaciones' => $s->evaluaciones ?? [],
                'meeting' => $s->meeting ?? null,
            ];

            if ($rol === 'admin' || $rol === 'operador') {
                $base['falta_material'] = (int) $s->falta_material;
                $base['existe_evaluacion'] = (int) $s->existe_evaluacion;
            }

            return $base;

        }, $rows);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('curso_sesiones', [
            'curso_id' => (int)$cursoId,
            'rol' => $rol,
            'correo' => $correo,
            'ms' => $elapsed,
            'count' => count($data)
        ]);

        return response()->json($data);
    }

    public function listarPorCursoAlumnoLight($cursoId)
    {
        $start = microtime(true);

        if (!is_numeric($cursoId)) {
            return response()->json(['error' => 'curso_id invalido'], 400);
        }

        $correo = request()->header('X-USER-EMAIL');

        if (!$correo) {
            abort(400, 'Missing X-USER-EMAIL header');
        }

        $rows = $this->service->listarPorCursoAlumnoLight((int) $cursoId, (string) $correo);

        $data = array_map(function ($s) {
            return [
                'id' => (int) $s->id,
                'curso_edicion_id' => (int) $s->curso_edicion_id,
                'numero' => (int) $s->numero,
                'fecha' => $s->fecha,
                'hora_inicio' => $s->hora_inicio,
                'hora_fin' => $s->hora_fin,
                'duracion' => (int) $s->duracion,
                'estado' => $s->estado,
                'curso' => $s->curso_nombre ?? null,
                'docente' => $s->curso_docente ?? null,
                'edicion' => $s->curso_edicion ?? null,
                'encuesta_id' => $s->encuesta_id ?? null,
                'encuesta_respondida' => (bool) ($s->encuesta_respondida ?? false),
                'surveys' => $s->surveys ?? [],
            ];
        }, $rows);

        Log::info('alumno_curso_sesiones_light', [
            'curso_id' => (int) $cursoId,
            'correo' => $correo,
            'ms' => round((microtime(true) - $start) * 1000),
            'count' => count($data),
        ]);

        return response()->json($data);
    }

    public function obtenerEvaluaciones($cursoId, $sesionId)
    {
        // LOG ENTRADA
        Log::info('evaluaciones_request_debug', [
            'cursoId_param' => $cursoId,
            'sesionId_param' => $sesionId,
            'headers' => request()->headers->all(),
            'query' => request()->query(),
            'body' => request()->all()
        ]);

        if (!is_numeric($cursoId) || !is_numeric($sesionId)) {
            return response()->json([], 400);
        }

        $data = $this->service->obtenerEvaluacionesSesion(
            (int)$cursoId,
            (int)$sesionId
        );

        // LOG SALIDA
        Log::info('evaluaciones_response_debug', [
            'curso_id' => (int)$cursoId,
            'sesion_id' => (int)$sesionId,
            'count' => is_array($data) ? count($data) : null,
            'data' => $data
        ]);

        return response()->json($data);
    }

    public function detalleAlumno($cursoId, $sesionId)
    {
        $start = microtime(true);

        if (!is_numeric($cursoId) || !is_numeric($sesionId)) {
            return response()->json(['error' => 'parametros invalidos'], 400);
        }

        $correo = request()->header('X-USER-EMAIL');

        if (!$correo) {
            abort(400, 'Missing X-USER-EMAIL header');
        }

        $detalle = $this->service->obtenerDetalleSesionAlumno(
            (int) $cursoId,
            (int) $sesionId,
            $correo
        );

        if (!$detalle) {
            return response()->json(['error' => 'sesion no encontrada'], 404);
        }

        $sesion = $detalle['sesion'];
        $materiales = $this->mapMateriales($detalle['materiales'] ?? []);
        $evaluaciones = $this->mapEvaluaciones($sesion->evaluaciones ?? []);
        $anuncios = $this->mapAnuncios($detalle['anuncios'] ?? []);

        $sessionData = [
            'id' => (int) $sesion->id,
            'curso_edicion_id' => (int) $sesion->curso_edicion_id,
            'numero' => (int) $sesion->numero,
            'fecha' => $sesion->fecha,
            'hora_inicio' => $sesion->hora_inicio,
            'hora_fin' => $sesion->hora_fin,
            'duracion' => (int) $sesion->duracion,
            'estado' => $sesion->estado,
            'curso' => $sesion->curso_nombre ?? null,
            'docente' => $sesion->curso_docente ?? null,
            'edicion' => $sesion->curso_edicion ?? null,
            'video_status' => $sesion->video_status ?? null,
            'video_drive_file_id' => $sesion->video_drive_file_id ?? null,
            'video_uploaded_at' => $sesion->video_uploaded_at ?? null,
            'video_filesize' => isset($sesion->video_filesize) ? (int) $sesion->video_filesize : null,
            'video_chat_drive_file_id' => $sesion->video_chat_drive_file_id ?? null,
            'video_chat_titulo' => $sesion->video_chat_titulo ?? null,
            'video_chat_filesize' => isset($sesion->video_chat_filesize) ? (int) $sesion->video_chat_filesize : null,
            'video_chat_uploaded_at' => $sesion->video_chat_uploaded_at ?? null,
            'encuesta_id' => $sesion->encuesta_id ?? null,
            'encuesta_respondida' => (bool) ($sesion->encuesta_respondida ?? false),
            'surveys' => $sesion->surveys ?? [],
            'tiene_evaluacion' => (bool) ($sesion->tiene_evaluacion ?? false),
            'evaluaciones' => $evaluaciones,
            'meeting' => $sesion->meeting ?? null,
            'materials' => $materiales,
            'materiales' => $materiales,
        ];

        Log::info('alumno_sesion_detalle', [
            'curso_id' => (int) $cursoId,
            'sesion_id' => (int) $sesionId,
            'correo' => $correo,
            'ms' => round((microtime(true) - $start) * 1000),
            'materiales' => count($materiales),
            'evaluaciones' => count($evaluaciones),
            'anuncios' => count($anuncios),
        ]);

        return response()->json([
            'session' => $sessionData,
            'sesion' => $sessionData,
            'materiales' => $materiales,
            'evaluaciones' => $evaluaciones,
            'anuncios' => $anuncios,
            'anuncio_sesion_no_leido' => $this->resolverAnuncioSesion($anuncios),
            'video' => $detalle['video'] ?? null,
        ]);
    }

    public function actualizarEvaluacion($sesionId)
{
    if (!is_numeric($sesionId)) {
        return response()->json([
            'error' => 'sesion_id invalido'
        ], 400);
    }

    $evaluacionIds = request()->input('evaluaciones', []);

    if (!is_array($evaluacionIds)) {
        return response()->json([
            'error' => 'evaluaciones invalido'
        ], 400);
    }

    $this->service->agregarEvaluacionesSesion(
        (int)$sesionId,
        $evaluacionIds
    );

    return response()->json([
        'ok' => true
    ]);
}

public function planEvaluacionCurso($cursoId)
{
    if (!is_numeric($cursoId)) {
        return response()->json([
            'error' => 'curso_id invalido'
        ], 400);
    }

    return response()->json(
        $this->service->obtenerPlanEvaluacionCurso((int) $cursoId)
    );
}

public function actualizarFechaLimiteEvaluacion($sesionId, $evaluacionId)
{
    if (!is_numeric($sesionId)) {
        return response()->json([
            'error' => 'sesion_id invalido'
        ], 400);
    }

    if (!is_numeric($evaluacionId)) {
        return response()->json([
            'error' => 'evaluacion_id invalido'
        ], 400);
    }

    $allowed = ['fecha_limite', 'hito_nombre', 'hito_orden', 'grupo_nombre', 'plazo_dias'];
    $payload = [];
    foreach ($allowed as $field) {
        if (request()->exists($field)) {
            $payload[$field] = request()->input($field);
        }
    }

    if (empty($payload)) {
        return response()->json([
            'error' => 'metadata requerido'
        ], 422);
    }

    $fechaLimite = $payload['fecha_limite'] ?? null;

    if ($fechaLimite !== null && $fechaLimite !== '') {
        try {
            $fecha = Carbon::createFromFormat('Y-m-d\TH:i', (string) $fechaLimite);

            if ($fecha->format('Y-m-d\TH:i') !== (string) $fechaLimite) {
                throw new \InvalidArgumentException('fecha_limite invalido');
            }

            $fechaLimite = $fecha->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'fecha_limite invalido'
            ], 422);
        }
    } elseif ($fechaLimite === '') {
        $fechaLimite = null;
    }

    $actualizado = $this->service->actualizarFechaLimiteEvaluacionSesion(
        (int)$sesionId,
        (int)$evaluacionId,
        $fechaLimite,
        $payload
    );

    if (!$actualizado) {
        return response()->json([
            'error' => 'relacion sesion-evaluacion no encontrada'
        ], 404);
    }

    return response()->json([
        'ok' => true
    ]);
}

public function eliminarEvaluacion($sesionId, $evaluacionId)
{
    if (!is_numeric($sesionId)) {
        return response()->json([
            'error' => 'sesion_id invalido'
        ], 400);
    }

    if (!is_numeric($evaluacionId)) {
        return response()->json([
            'error' => 'evaluacion_id invalido'
        ], 400);
    }

    $this->service->eliminarEvaluacionSesion(
        (int)$sesionId,
        (int)$evaluacionId
    );

    return response()->json([
        'ok' => true
    ]);
}

private function mapMateriales($rows): array
{
    return array_map(function ($m) {
        return [
            'id' => (int) $m->id,
            'curso_edicion_sesion_id' => (int) $m->curso_edicion_sesion_id,
            'titulo' => $m->titulo,
            'descripcion' => $m->descripcion,
            'tipo' => $m->tipo,
            'nombre_archivo' => $m->nombre_archivo,
            'ruta_archivo' => $m->ruta_archivo,
            'mime_type' => $m->mime_type,
            'tamano_bytes' => $m->tamano_bytes ? (int) $m->tamano_bytes : null,
            'url_externa' => $m->url_externa,
            'orden' => (int) $m->orden,
        ];
    }, $this->toList($rows));
}

private function mapEvaluaciones($rows): array
{
    return array_map(function ($e) {
        return [
            'id' => (int) $e->id,
            'nombre' => $e->nombre,
            'tipo_param_id' => isset($e->tipo_param_id) ? (int) $e->tipo_param_id : null,
            'tipo' => $e->tipo ?? null,
            'rendicion_id' => isset($e->rendicion_id) ? (int) $e->rendicion_id : null,
            'rendicion_estado' => $e->rendicion_estado ?? null,
            'puntaje_total' => isset($e->puntaje_total) ? (float) $e->puntaje_total : null,
            'puntaje_maximo' => isset($e->puntaje_maximo) ? (float) $e->puntaje_maximo : null,
            'puntaje_aprobacion' => isset($e->puntaje_aprobacion) ? (float) $e->puntaje_aprobacion : null,
            'aprobado' => isset($e->aprobado) ? (bool) $e->aprobado : null,
            'fecha_limite' => $e->fecha_limite ?? null,
            'hito_nombre' => $e->hito_nombre ?? null,
            'hito_orden' => isset($e->hito_orden) ? (int) $e->hito_orden : null,
            'grupo_nombre' => $e->grupo_nombre ?? null,
            'plazo_dias' => isset($e->plazo_dias) ? (int) $e->plazo_dias : null,
        ];
    }, $this->toList($rows));
}

private function mapAnuncios($rows): array
{
    return array_map(function ($a) {
        return [
            'id' => (int) $a->id,
            'titulo' => $a->titulo,
            'contenido' => $a->contenido,
            'tipo' => $a->tipo,
            'creado_por' => (int) $a->creado_por,
            'creado_en' => $a->creado_en,
            'actualizado_en' => $a->actualizado_en,
            'leido' => (int) ($a->leido ?? 0),
        ];
    }, $this->toList($rows));
}

private function resolverAnuncioSesion(array $anuncios): array
{
    if (empty($anuncios)) {
        return [
            'existen' => false,
            'pendiente' => null,
        ];
    }

    usort($anuncios, fn ($a, $b) => strcmp((string) ($b['creado_en'] ?? ''), (string) ($a['creado_en'] ?? '')));
    $ultimo = $anuncios[0];

    return [
        'existen' => true,
        'pendiente' => ((int) ($ultimo['leido'] ?? 0) === 0)
            ? $ultimo
            : null,
    ];
}

private function toList($rows): array
{
    if (is_object($rows) && method_exists($rows, 'all')) {
        return $rows->all();
    }

    return is_array($rows) ? $rows : [];
}

}
