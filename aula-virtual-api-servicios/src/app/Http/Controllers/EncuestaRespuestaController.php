<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EncuestaRespuestaService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use App\Services\GenDocsSurveyService;

class EncuestaRespuestaController extends Controller
{
    protected EncuestaRespuestaService $service;

    public function __construct(EncuestaRespuestaService $service, private readonly GenDocsSurveyService $genDocsSurveyService)
    {
        $this->service = $service;
    }


    /**
     * Verificar si el alumno ya respondió una encuesta de sesión
     */
    public function verificar(Request $request)
    {
        $correo = trim((string) $request->header('X-USER-EMAIL', ''));
        $sesionId = $request->input('sesion_id');
        $cursoId = $request->input('curso_id');
        $encuestaId = $request->input('encuesta_id');

        if (empty($correo)) {
            return response()->json(['error' => 'correo requerido'], 400);
        }

        if (!$cursoId || !$this->service->alumnoPuedeAcceder((int) $cursoId, $sesionId ? (int) $sesionId : null, $correo)) {
            return response()->json(['error' => 'No autorizado para este curso'], 403);
        }

        try {
            $respondio = $this->service->alumnoYaRespondioEncuesta([
                'correo' => $correo,
                'encuesta_id' => $encuestaId,
                'sesion_id' => $sesionId,
                'curso_id' => $cursoId,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'respondio' => $respondio
        ]);
    }



    /**
     * Registrar encuesta respondida
     */
    public function registrar(Request $request)
    {
        $start = microtime(true);

        $data = $request->only([
            'encuesta_id',
            'curso_id',
            'sesion_id',
            'respuestas'
        ]);

        $data['correo'] = trim((string) $request->header('X-USER-EMAIL', ''));

       
        if (empty($data['correo'])) {
            return response()->json(['error' => 'correo requerido'], 400);
        }

        if (empty($data['encuesta_id'])) {
            return response()->json(['error' => 'encuesta_id requerido'], 400);
        }

        if (empty($data['curso_id'])) {
            return response()->json(['error' => 'curso_id requerido'], 400);
        }

        if (empty($data['sesion_id'])) {
            return response()->json(['error' => 'sesion_id requerido'], 400);
        }

        if (empty($data['respuestas']) || !is_array($data['respuestas'])) {
            return response()->json(['error' => 'respuestas requeridas'], 400);
        }

        $res = $this->service->registrarEncuesta($data);

        if (!$res['ok']) {

            Log::warning('encuesta_respuesta_rechazada', [
                'sesion_id' => (int) $data['sesion_id'],
                'status' => (int) ($res['status'] ?? 422),
            ]);

            return response()->json([
                'error' => $res['mensaje']
            ], (int) ($res['status'] ?? 422));
        }

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('encuesta_registrar', [
            'sesion_id' => (int)$data['sesion_id'],
            'respuesta_id' => (int)$res['respuesta_id'],
            'ms' => $elapsed
        ]);

        return response()->json([
            'ok' => true,
            'respuesta_id' => (int)$res['respuesta_id']
        ]);
    }


    /**
     * Obtener cuántos alumnos respondieron una sesión
     */
    public function estadisticaSesion(Request $request, $sesionId)
    {
        $start = microtime(true);

        if (!is_numeric($sesionId)) {
            return response()->json(['error' => 'sesion_id invalido'], 400);
        }

        $rol = (string) $request->header('X-USER-ROL', '');
        $correo = (string) $request->header('X-USER-EMAIL', '');
        if (!$this->service->puedeConsultarSesion((int) $sesionId, $rol, $correo)) {
            return response()->json(['error' => 'No autorizado para esta sesión'], 403);
        }

        $total = $this->service->contarRespondidasSesion((int)$sesionId);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('encuesta_sesion_estadistica', [
            'sesion_id' => (int)$sesionId,
            'respondidas' => $total,
            'ms' => $elapsed
        ]);

        return response()->json([
            'sesion_id' => (int)$sesionId,
            'respondidas' => (int)$total
        ]);
    }

    /**
 * Obtener detalle de resultados de encuesta por sesión
 */
public function detalleResultadosPorSesion(Request $request, $cursoEdicionId)
{
    $start = microtime(true);

    if (!is_numeric($cursoEdicionId)) {
        return response()->json(['error' => 'curso_edicion_id invalido'], 400);
    }

    $rol = (string) $request->header('X-USER-ROL', '');
    $correo = (string) $request->header('X-USER-EMAIL', '');
    if (!$this->service->puedeConsultarCurso((int) $cursoEdicionId, $rol, $correo)) {
        return response()->json(['error' => 'No autorizado para este curso'], 403);
    }

    $filters = [
        'kind' => (string) $request->query('kind', 'all'),
        'session' => (int) $request->query('session', 0),
        'teacher' => (int) $request->query('teacher', 0),
        'form' => (int) $request->query('form', 0),
        'page' => (int) $request->query('page', 1),
        'per_page' => (int) $request->query('per_page', 25),
    ];
    $result = $this->genDocsSurveyService->results((int) $cursoEdicionId, $rol, $correo, $filters);
    if (!($result['ok'] ?? false)) {
        return response()->json([
            'ok' => false,
            'message' => $result['message'] ?? 'No se pudieron cargar los resultados.',
        ], (int) ($result['status'] ?? 503));
    }
    $elapsed = round((microtime(true) - $start) * 1000);

    Log::info('encuesta_detalle_resultados_por_sesion', [
        'curso_edicion_id' => (int)$cursoEdicionId,
        'total' => (int) ($result['total'] ?? 0),
        'response_rows' => (int) ($result['response_rows'] ?? 0),
        'ms' => $elapsed
    ]);

    unset($result['ok']);
    $result['curso_edicion_id'] = (int) $cursoEdicionId;

    return response()->json($result);
}
}
