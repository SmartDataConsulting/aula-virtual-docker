<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\SesionService;
use App\Support\AuthSessionKeys;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SessionsController extends Controller
{
    private SesionService $sesionService;

    public function __construct(SesionService $sesionService)
    {
        $this->sesionService = $sesionService;
    }

    public function evaluations(
    Request $request,
    int $courseId,
    int $sessionId
    ){
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        if (!in_array($rol, ['admin', 'operador', 'docente', 'profesor'])) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        Log::info('SessionsController@evaluations', [
            'course_id' => $courseId,
            'session_id' => $sessionId
        ]);

        $result = $this->sesionService
            ->obtenerEvaluacionesSesion($courseId, $sessionId);

        if (!$result->ok()) {
            return response()->json([], 500);
        }

        return response()->json($result->data());
    }

    public function assignEvaluation(
        Request $request,
        int $sessionId
    ){
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        if (!in_array($rol, ['admin', 'operador', 'docente', 'profesor'], true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $evaluaciones = $request->input('evaluaciones', []);

        if (!is_array($evaluaciones)) {
            return response()->json(['error' => 'invalid'], 400);
        }

        Log::info('SessionsController@assignEvaluation', [
            'session_id' => $sessionId,
            'evaluaciones_count' => count($evaluaciones)
        ]);

        $result = $this->sesionService
            ->agregarEvaluacionesSesion($sessionId, $evaluaciones);

        if (!$result->ok()) {
            return response()->json(['ok' => false], 500);
        }

        $this->forgetCourseSessionCache($request);

        return response()->json(['ok' => true]);
    }


    public function removeEvaluation(
        Request $request,
        int $sessionId,
        int $evaluationId
    ){
        $result = $this->sesionService
            ->eliminarEvaluacionSesion($sessionId, $evaluationId);

        if (!$result->ok()) {
            return response()->json(['ok' => false], 500);
        }

        $this->forgetCourseSessionCache($request);

        return response()->json(['ok' => true]);
    }

    public function updateEvaluation(
        Request $request,
        int $sessionId,
        int $evaluationId
    ){
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        if (!in_array($rol, ['admin', 'operador', 'docente', 'profesor'])) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $payload = [];
        foreach (['fecha_limite', 'hito_nombre', 'hito_orden', 'grupo_nombre', 'plazo_dias'] as $field) {
            if ($request->exists($field)) {
                $payload[$field] = $request->input($field);
            }
        }

        $fechaLimite = is_string($payload['fecha_limite'] ?? null)
            ? trim($payload['fecha_limite'])
            : null;

        $hasMetadata = collect($payload)->contains(function ($value) {
            return $value !== null && $value !== '';
        });

        if (!$hasMetadata) {
            return response()->json([
                'ok' => false,
                'error' => 'No hay cambios para guardar.',
            ], 422);
        }

        if (array_key_exists('fecha_limite', $payload)) {
            $payload['fecha_limite'] = $fechaLimite;
        }

        Log::info('SessionsController@updateEvaluation', [
            'session_id' => $sessionId,
            'evaluation_id' => $evaluationId,
            'fields' => array_keys($payload),
        ]);

        $result = $this->sesionService
            ->actualizarEvaluacionSesion($sessionId, $evaluationId, $payload);

        if (!$result->ok()) {
            $error = $result->error();

            return response()->json([
                'ok' => false,
                'error' => $error['message'] ?? $error['error'] ?? 'No se pudo actualizar la evaluación.',
            ], $result->status() ?: 500);
        }

        $this->forgetCourseSessionCache($request);

        return response()->json(['ok' => true]);
    }

    private function forgetCourseSessionCache(Request $request): void
    {
        $courseId = (int) $request->input('course_id', 0);
        $role = (string) $request->session()->get(AuthSessionKeys::USER_ROLE, '');

        if ($courseId > 0 && $role !== '') {
            $this->sesionService->forgetCourseSessions($courseId, $role);
        }
    }

    public function applyEvaluationPlanTemplate(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = (string) $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        if (!in_array($rol, ['admin', 'operador', 'docente', 'profesor'], true)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $validated = $request->validate([
            'template' => ['required', 'in:partial_final'],
            'partial_evaluation_id' => ['required', 'integer', 'min:1'],
            'final_evaluation_id' => ['required', 'integer', 'min:1', 'different:partial_evaluation_id'],
            'deadline_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'final_extra_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'group_name' => ['nullable', 'string', 'max:120'],
        ]);

        $sessionsResult = $this->sesionService->listarSesionesCurso($courseId, $rol);
        if (!$sessionsResult->ok()) {
            return response()->json(['ok' => false, 'error' => 'No se pudieron cargar las sesiones.'], 502);
        }

        $sessions = collect($sessionsResult->data()['sessions'] ?? [])
            ->filter(fn ($session) => !empty($session->id))
            ->sortBy(fn ($session) => (int) ($session->number ?? 0))
            ->values();

        if ($sessions->count() < 2) {
            return response()->json(['ok' => false, 'error' => 'El curso necesita al menos dos sesiones.'], 422);
        }

        $middleNumber = (int) ceil($sessions->count() / 2);
        $partialSession = $sessions->first(fn ($session) => (int) ($session->number ?? 0) === $middleNumber)
            ?: $sessions->get(max(0, $middleNumber - 1));
        $finalSession = $sessions->last();

        $planResult = $this->sesionService->obtenerPlanEvaluacionCurso($courseId);
        $currentAssignments = $planResult->ok()
            ? collect($planResult->data()['sessions'] ?? [])->flatMap(function ($session) {
                return collect($session['milestones'] ?? [])->map(function ($milestone) use ($session) {
                    return [
                        'session_id' => (int) ($session['session_id'] ?? 0),
                        'evaluation_id' => (int) ($milestone['evaluation_id'] ?? 0),
                    ];
                });
            })
            : collect();

        $finalExtraDays = array_key_exists('final_extra_days', $validated) && $validated['final_extra_days'] !== null
            ? (int) $validated['final_extra_days']
            : 5;
        $finalSessionDeadline = $this->buildSessionDeadline($finalSession);

        if (!$finalSessionDeadline) {
            return response()->json([
                'ok' => false,
                'error' => 'La última sesión no tiene fecha válida para calcular los vencimientos.',
            ], 422);
        }

        $groupName = trim((string) ($validated['group_name'] ?? ''));
        $groupName = $groupName !== '' ? $groupName : null;

        $targets = [
            [
                'evaluation_id' => (int) $validated['partial_evaluation_id'],
                'session' => $partialSession,
                'hito_nombre' => 'Examen parcial',
                'hito_orden' => 1,
                'fecha_limite' => $finalSessionDeadline->copy(),
            ],
            [
                'evaluation_id' => (int) $validated['final_evaluation_id'],
                'session' => $finalSession,
                'hito_nombre' => 'Examen final',
                'hito_orden' => 2,
                'fecha_limite' => $finalSessionDeadline->copy()->addDays($finalExtraDays),
            ],
        ];

        foreach ($targets as $target) {
            $targetSessionId = (int) $target['session']->id;
            $evaluationId = (int) $target['evaluation_id'];

            $oldAssignments = $currentAssignments
                ->filter(fn ($item) => $item['evaluation_id'] === $evaluationId);

            foreach ($oldAssignments as $assignment) {
                if ((int) $assignment['session_id'] <= 0) {
                    continue;
                }

                $removed = $this->sesionService->eliminarEvaluacionSesion($assignment['session_id'], $evaluationId);
                if (!$removed->ok()) {
                    return response()->json(['ok' => false, 'error' => 'No se pudo mover una evaluación existente.'], 502);
                }
            }

            $payload = [
                [
                    'id' => $evaluationId,
                    'hito_nombre' => $target['hito_nombre'],
                    'hito_orden' => $target['hito_orden'],
                    'grupo_nombre' => $groupName,
                    'plazo_dias' => null,
                    'fecha_limite' => $target['fecha_limite']->format('Y-m-d\TH:i'),
                ],
            ];

            $assigned = $this->sesionService->agregarEvaluacionesSesion($targetSessionId, $payload);
            if (!$assigned->ok()) {
                return response()->json(['ok' => false, 'error' => 'No se pudo asignar el plan de evaluación.'], 502);
            }

            $updated = $this->sesionService->actualizarEvaluacionSesion($targetSessionId, $evaluationId, $payload[0]);
            if (!$updated->ok()) {
                return response()->json(['ok' => false, 'error' => 'No se pudo actualizar la metadata del hito.'], 502);
            }
        }

        $this->sesionService->forgetCourseSessions($courseId, $rol);

        return response()->json([
            'ok' => true,
            'message' => sprintf(
                'Plan aplicado: parcial en sesión %s con vencimiento en la última sesión, y final en sesión %s con %s días de plazo.',
                $partialSession->number ?? $middleNumber,
                $finalSession->number ?? $sessions->count(),
                $finalExtraDays
            ),
            'reload' => true,
        ]);
    }

    private function buildSessionDeadline(object $session): ?Carbon
    {
        if (empty($session->date)) {
            return null;
        }

        $time = $session->end_time ?? $session->start_time ?? '23:59:00';

        try {
            return Carbon::parse($session->date.' '.$time, 'America/Lima');
        } catch (\Throwable) {
            return null;
        }
    }
}
