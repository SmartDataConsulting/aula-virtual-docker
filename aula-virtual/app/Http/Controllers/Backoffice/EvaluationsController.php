<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\EvaluationService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EvaluationsController extends Controller
{
    private const EXAM_TYPES = [1, 2];
    private const WORK_TYPES = [3, 4];
    private const REQUIRED_WORK_TOTAL_SCORE = 20;

    private EvaluationService $evaluationService;

    public function __construct(EvaluationService $evaluationService)
    {
        $this->evaluationService = $evaluationService;
    }

    public function index(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        Log::info('EvaluationsController@index', [
            'course_id' => $courseId,
            'correo' => $correo,
            'rol' => $rol
        ]);

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        $error = null;
        $evaluations = collect();
        $courseName = 'Curso';

        $result = $this->evaluationService->listByCourse($courseId);

        if (!$result->ok()) {

            Log::error('Error listing evaluations', [
                'course_id' => $courseId,
                'error' => $result->error(),
            ]);

            $error = 'No se pudieron cargar las evaluaciones.';

        } else {

            $data = $result->data();

            $courseName = $data['course']['name'] ?? 'Curso';
            $evaluations = collect($data['evaluations']);
        }

        return view('backoffice.evaluations.show', [
            'evaluations' => $evaluations,
            'courseName'  => $courseName,
            'courseId'    => $courseId,
            'error'       => $error,
        ]);
    }

    /**
     * Crear evaluación
     */
    public function store(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        $typeId = (int) $request->input('tipo');
        $weight = $request->input('peso');

        if (in_array($typeId, self::EXAM_TYPES, true)) {
            if (!$request->input('tiempo_minutos')) {
                return back()->withErrors(['tiempo_minutos' => 'Tiempo requerido']);
            }

            if (!$request->input('puntaje_aprobacion')) {
                return back()->withErrors(['puntaje_aprobacion' => 'Puntaje requerido']);
            }
        }

        if (!$weight || (float) $weight <= 0 || (float) $weight > 100) {
            return back()->withErrors(['peso' => 'El peso debe ser mayor a 0 y no puede exceder 100%.']);
        }

        $payload = [
            'nombre'   => $request->input('nombre'),
            'tipo'     => $typeId,
            'curso_id' => $courseId,
            'tiempo_minutos' => (int) $request->input('tiempo_minutos', 0),
            'puntaje_aprobacion' => (int) $request->input('puntaje_aprobacion', 0),
            'peso' => (float) $request->input('peso', 0),
        ];

        Log::info('EvaluationsController@store', [
            'payload' => $payload,
            'correo'  => $correo,
            'rol'     => $rol
        ]);

        $result = $this->evaluationService->create($payload);

        if (!$result->ok()) {

            $error = $result->error();
            $correlationId = $error['correlation_id'] ?? 'N/A';

            return back()->withErrors([
                'evaluation' => "No se pudo crear evaluación. Código: {$correlationId}"
            ]);
        }

        $data = $result->data();
        $evaluationId = $data['evaluacion_id'] ?? null;

        return redirect()->route(
            $this->isWorkType($typeId)
                ? 'backoffice.evaluations.work.edit'
                : 'backoffice.evaluations.edit',
            [$courseId, $evaluationId]
        );
    }

    public function edit(int $courseId, int $evaluationId)
    {
        $result = $this->evaluationService->getEvaluation($evaluationId);

        if (!$result->ok()) {

            Log::error('EvaluationsController@edit error', [
                'evaluation_id' => $evaluationId,
                'error' => $result->error()
            ]);

            abort(500, 'No se pudo cargar la evaluación');
        }

        $data = $result->data();

        Log::info('EvaluationsController@edit payload to view', [
            'course_id' => $courseId,
            'evaluation_id' => $evaluationId,
            'payload' => $data
        ]);

        return view('backoffice.evaluations.edit', [
            'courseId' => $courseId,
            'evaluationId' => $evaluationId,
            'evaluation' => $data['evaluacion'],
            'preguntas' => $data['preguntas']
        ]);
    }

    public function workEdit(int $courseId, int $evaluationId)
    {
        $result = $this->evaluationService->getWorkEvaluation($evaluationId);

        if (!$result->ok()) {

            Log::error('EvaluationsController@workEdit error', [
                'evaluation_id' => $evaluationId,
                'error' => $result->error()
            ]);

            abort(500, 'No se pudo cargar el trabajo');
        }

        $data = $result->data();

         Log::info('EvaluationsController@workEdit payload to view', [
            'course_id' => $courseId,
            'evaluation_id' => $evaluationId,
            'payload' => $data
        ]);

        return view('backoffice.evaluations.work-edit', [
            'courseId' => $courseId,
            'evaluationId' => $evaluationId,
            'evaluation' => $data['evaluacion'],
            'trabajo' => $data['trabajo']
        ]);
    }

    public function view(int $courseId, int $evaluationId)
    {
        $result = $this->evaluationService->getEvaluation($evaluationId);

        if (!$result->ok()) {

            Log::error('EvaluationsController@view error', [
                'evaluation_id' => $evaluationId,
                'error' => $result->error()
            ]);

            abort(500, 'No se pudo cargar la evaluación');
        }

        $data = $result->data();

        return view('backoffice.evaluations.view', [
            'courseId' => $courseId,
            'evaluationId' => $evaluationId,
            'evaluation' => $data['evaluacion'],
            'preguntas' => $data['preguntas']
        ]);
    }

    public function workView(int $courseId, int $evaluationId)
    {
        $result = $this->evaluationService->getWorkEvaluation($evaluationId);

        if (!$result->ok()) {

            Log::error('EvaluationsController@workView error', [
                'evaluation_id' => $evaluationId,
                'error' => $result->error()
            ]);

            abort(500, 'No se pudo cargar el trabajo');
        }

        $data = $result->data();

        return view('backoffice.evaluations.work-view', [
            'courseId' => $courseId,
            'evaluationId' => $evaluationId,
            'evaluation' => $data['evaluacion'],
            'trabajo' => $data['trabajo']
        ]);
    }

    /**
     * AUTOSAVE evaluación (AJAX)
     */
    public function autosave(
        Request $request,
        int $courseId,
        int $evaluationId
    ) {
        try {

            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
            $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

            if (!$correo) {
                return response()->json(['error' => 'unauthorized'], 401);
            }

            if (!in_array($rol, ['admin', 'operador'])) {
                return response()->json(['error' => 'forbidden'], 403);
            }

            $payload = $request->all();

            Log::info('EvaluationsController@autosave', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'preguntas' => count($payload['preguntas'] ?? []),
                'correo' => $correo,
                'rol' => $rol
            ]);

            $result = $this->evaluationService->autosave(
                $evaluationId,
                $payload
            );

            if (!$result->ok()) {

                Log::error('EvaluationsController@autosave error', [
                    'evaluation_id' => $evaluationId,
                    'error' => $result->error()
                ]);

                return response()->json([
                    'ok' => false,
                    'error' => 'autosave failed'
                ], 500);
            }

            return response()->json([
                'ok' => true
            ]);

        } catch (\Throwable $e) {

            Log::error('EvaluationsController@autosave exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'unexpected error'
            ], 500);
        }
    }

    public function saveWork(
        Request $request,
        int $courseId,
        int $evaluationId
    ) {
        try {

            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
            $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

            if (!$correo) {
                return response()->json(['error' => 'unauthorized'], 401);
            }

            if (!in_array($rol, ['admin', 'operador'])) {
                return response()->json(['error' => 'forbidden'], 403);
            }

            $payload = $request->all();

            Log::info('EvaluationsController@saveWork', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'criterios' => count($payload['trabajo']['rubrica']['criterios'] ?? []),
                'correo' => $correo,
                'rol' => $rol
            ]);

            $result = $this->evaluationService->saveWorkEvaluation(
                $evaluationId,
                $payload
            );

            if (!$result->ok()) {

                Log::error('EvaluationsController@saveWork error', [
                    'evaluation_id' => $evaluationId,
                    'error' => $result->error()
                ]);

                return response()->json([
                    'ok' => false,
                    'error' => 'save work failed'
                ], 500);
            }

            return response()->json([
                'ok' => true,
                'data' => $result->data()
            ]);

        } catch (\Throwable $e) {

            Log::error('EvaluationsController@saveWork exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'unexpected error'
            ], 500);
        }
    }

    public function publish(
    Request $request,
    int $courseId,
    int $evaluationId
    ) {
        try {

            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
            $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

            if (!$correo) {
                return response()->json([
                    'ok' => false,
                    'error' => 'unauthorized'
                ], 401);
            }

            if (!in_array($rol, ['admin', 'operador'])) {
                return response()->json([
                    'ok' => false,
                    'error' => 'forbidden'
                ], 403);
            }

            Log::info('EvaluationsController@publish', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'correo' => $correo,
                'rol' => $rol
            ]);

            $evaluationResult = $this->evaluationService->getEvaluation($evaluationId);

            if (!$evaluationResult->ok()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'No se pudo validar la evaluación antes de publicarla'
                ], 422);
            }

            $evaluation = $evaluationResult->data()['evaluacion'] ?? [];
            $typeId = (int) ($evaluation['type_id'] ?? 0);

            if ($this->isWorkType($typeId)) {
                $workValidationError = $this->validateWorkScoreForPublication($evaluationId);

                if ($workValidationError !== null) {
                    return response()->json([
                        'ok' => false,
                        'error' => $workValidationError
                    ], 422);
                }
            }

            $result = $this->evaluationService->publicarEvaluacion(
                $evaluationId
            );

            if (!$result['ok']) {

                Log::warning('EvaluationsController@publish validation', [
                    'evaluation_id' => $evaluationId,
                    'error' => $result['error']
                ]);

                return response()->json([
                    'ok' => false,
                    'error' => $result['error']
                ], 422);
            }

            return response()->json([
                'ok' => true
            ]);

        } catch (\Throwable $e) {

            Log::error('EvaluationsController@publish exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'unexpected error'
            ], 500);
        }
    }

public function duplicate(Request $request, int $courseId, int $evaluationId)
{
    $typeId = (int) $request->input('type_id', 0);
    $result = $this->evaluationService->duplicateEvaluation(
        $courseId,
        $evaluationId,
        $typeId
    );
    $resolvedTypeId = (int) ($result['typeId'] ?? $typeId);

    $redirectUrl = route(
        $this->isWorkType($resolvedTypeId)
            ? 'backoffice.evaluations.work.edit'
            : 'backoffice.evaluations.edit',
        [$courseId, $result['newId'] ?? 0]
    );

    if (!$result['ok']) {
        if ($request->expectsJson() || $request->isJson()) {
            return response()->json([
                'ok' => false,
                'error' => $result['error']
            ], 422);
        }

        return redirect()
            ->back()
            ->with('error', $result['error']);
    }

    $request->session()->flash('duplicating', true);

    if ($request->expectsJson() || $request->isJson()) {
        return response()->json([
            'ok' => true,
            'redirect_url' => $redirectUrl
        ]);
    }

    return redirect($redirectUrl)->with('duplicating', true);
}

public function byType(int $courseId, int $typeId)
{
    $result = $this->evaluationService
        ->listPublishedByCourseAndType($courseId, $typeId);

    if (!$result->ok()) {
        return response()->json([]);
    }

    $data = $result->data();

    return response()->json($data['evaluations']);
}

private function isWorkType(int $typeId): bool
{
    return in_array($typeId, self::WORK_TYPES, true);
}

private function validateWorkScoreForPublication(int $evaluationId): ?string
{
    $workResult = $this->evaluationService->getWorkEvaluation($evaluationId);
    $evaluationResult = $this->evaluationService->getEvaluation($evaluationId);

    if (!$workResult->ok()) {
        Log::warning('EvaluationsController@validateWorkScoreForPublication load error', [
            'evaluation_id' => $evaluationId,
            'error' => $workResult->error()
        ]);

        return 'No se pudo validar la rúbrica antes de publicar';
    }

    if (!$evaluationResult->ok()) {
        Log::warning('EvaluationsController@validateWorkScoreForPublication evaluation load error', [
            'evaluation_id' => $evaluationId,
            'error' => $evaluationResult->error()
        ]);

        return 'No se pudo validar la evaluación antes de publicar';
    }

    $evaluation = $evaluationResult->data()['evaluacion'] ?? [];
    $trabajo = $workResult->data()['trabajo'] ?? [];
    $rubrica = $trabajo['rubrica'] ?? [];
    $criterios = $rubrica['criterios'] ?? [];
    $totalScore = collect($criterios)->sum(function ($criterio) {
        return (float) ($criterio['puntaje_max'] ?? 0);
    });

    if (empty(trim((string) ($evaluation['nombre'] ?? '')))) {
        return 'Debe ingresar un nombre';
    }

    $passScore = (int) ($evaluation['pass_score'] ?? 0);

    if ($passScore <= 0 || $passScore > self::REQUIRED_WORK_TOTAL_SCORE) {
        return 'El puntaje mínimo para aprobar debe ser mayor a 0 y no exceder 20';
    }

    if (empty(trim(strip_tags((string) ($trabajo['descripcion'] ?? ''))))) {
        return 'Debe ingresar la descripción del trabajo';
    }


    if (empty($criterios)) {
        return 'Debe agregar al menos un criterio en la rúbrica';
    }

    foreach ($criterios as $index => $criterio) {
        if (empty(trim((string) ($criterio['nombre'] ?? '')))) {
            return 'Criterio ' . ($index + 1) . ': debe tener nombre';
        }

        if (empty(trim((string) ($criterio['descripcion'] ?? '')))) {
            return 'Criterio ' . ($index + 1) . ': debe tener descripcion';
        }

        if ((float) ($criterio['puntaje_max'] ?? 0) <= 0) {
            return 'Criterio ' . ($index + 1) . ': el puntaje debe ser mayor a 0';
        }
    }

    if (abs($totalScore - self::REQUIRED_WORK_TOTAL_SCORE) > 0.0001) {
        return 'La suma de puntajes de criterios debe ser exactamente 20 para poder publicar';
    }

    return null;
}
    
}
