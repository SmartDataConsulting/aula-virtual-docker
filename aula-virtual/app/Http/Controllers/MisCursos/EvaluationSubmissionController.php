<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use App\Services\EvaluationService;
use App\Services\EvaluationSubmissionService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EvaluationSubmissionController extends Controller
{
    public function __construct(
        private EvaluationService $evaluationService,
        private EvaluationSubmissionService $submissionService
    ) {
    }

    public function show(Request $request, int $courseId, int $sessionId, int $evaluationId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return redirect()->route('login');
        }

        $evaluationResult = $this->evaluationService->getEvaluation($evaluationId);

        if (!$evaluationResult->ok()) {
            Log::error('EvaluationSubmissionController@show evaluation error', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'error' => $evaluationResult->error(),
            ]);

            abort(500, 'No se pudo cargar la evaluación');
        }

        $submissionResult = $this->submissionService->getOrStart($evaluationId);

        if (!$submissionResult->ok()) {
            Log::error('EvaluationSubmissionController@show submission error', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'error' => $submissionResult->error(),
            ]);

            abort(500, 'No se pudo iniciar la rendición');
        }

        $evaluationData = $evaluationResult->data();
        $submissionData = $submissionResult->data();
        $submission = $submissionData['submission'] ?? null;
        $finalResult = null;

        if (($submission['status'] ?? $submission['estado'] ?? null) === 'finalizado'
            && !empty($submission['submission_id'] ?? $submission['rendicion_id'] ?? null)) {
            $finalResultResponse = $this->submissionService->getFinalResult(
                (int) ($submission['submission_id'] ?? $submission['rendicion_id'])
            );

            if ($finalResultResponse->ok()) {
                $finalResult = $finalResultResponse->data();
                $submissionData['answers'] = $finalResult['answers'] ?? ($submissionData['answers'] ?? []);
                $submission = $finalResult['submission'] ?? $submission;
            }
        }

        return view('mis-cursos.evaluation.run', [
            'courseId' => $courseId,
            'sessionId' => $sessionId,
            'evaluationId' => $evaluationId,
            'evaluacion' => $evaluationData['evaluacion'],
            'preguntas' => $evaluationData['preguntas'],
            'rendicion' => $submission,
            'respuestas' => $submissionData['answers'],
            'resultadoFinal' => $finalResult,
        ]);
    }

    public function start(Request $request, int $courseId, int $sessionId, int $evaluationId)
    {
        try {
            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

            if ($correo === '') {
                return response()->json(['message' => 'No autorizado'], 401);
            }

            $result = $this->submissionService->getOrStart($evaluationId);

            if (!$result->ok()) {
                return response()->json([
                    'message' => $this->resolveApiErrorMessage(
                        $result->error(),
                        'No se pudo iniciar la rendición'
                    ),
                ], $result->status() ?? 500);
            }

            return response()->json($result->data());
        } catch (\Throwable $e) {
            Log::error('EvaluationSubmissionController@start exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo iniciar la rendición',
            ], 500);
        }
    }

    public function saveAnswer(Request $request, int $courseId, int $sessionId, int $evaluationId)
    {
        try {
            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

            if ($correo === '') {
                return response()->json(['message' => 'No autorizado'], 401);
            }

            $questionId = $request->input('question_id', $request->input('pregunta_id'));
            $optionId = $request->input('option_id', $request->input('opcion_id'));

            if (!is_numeric($questionId)) {
                return response()->json([
                    'message' => 'question_id invalido',
                ], 422);
            }

            if ($optionId !== null && $optionId !== '' && !is_numeric($optionId)) {
                return response()->json([
                    'message' => 'option_id invalido',
                ], 422);
            }

            $result = $this->submissionService->saveAnswer(
                $evaluationId,
                (int) $questionId,
                ($optionId === null || $optionId === '') ? null : (int) $optionId
            );

            if (!$result->ok()) {
                return response()->json([
                    'message' => $this->resolveApiErrorMessage(
                        $result->error(),
                        'No se pudo guardar la respuesta'
                    ),
                ], $result->status() ?? 500);
            }

            return response()->json($result->data());
        } catch (\Throwable $e) {
            Log::error('EvaluationSubmissionController@saveAnswer exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo guardar la respuesta',
            ], 500);
        }
    }

    public function partial(Request $request, int $courseId, int $sessionId, int $evaluationId)
    {
        try {
            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

            if ($correo === '') {
                return response()->json(['message' => 'No autorizado'], 401);
            }

            $result = $this->submissionService->getPartialResult($evaluationId);

            if (!$result->ok()) {
                return response()->json([
                    'message' => $this->resolveApiErrorMessage(
                        $result->error(),
                        'No se pudo obtener el avance'
                    ),
                ], $result->status() ?? 500);
            }

            return response()->json($result->data());
        } catch (\Throwable $e) {
            Log::error('EvaluationSubmissionController@partial exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo obtener el avance',
            ], 500);
        }
    }

    public function finalize(Request $request, int $courseId, int $sessionId, int $evaluationId)
    {
        try {
            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

            if ($correo === '') {
                return response()->json(['message' => 'No autorizado'], 401);
            }

            $result = $this->submissionService->finalize($evaluationId);

            if (!$result->ok()) {
                return response()->json([
                    'message' => $this->resolveApiErrorMessage(
                        $result->error(),
                        'No se pudo finalizar la rendición'
                    ),
                ], $result->status() ?? 500);
            }

            return response()->json($result->data());
        } catch (\Throwable $e) {
            Log::error('EvaluationSubmissionController@finalize exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo finalizar la rendición',
            ], 500);
        }
    }

    public function result(
        Request $request,
        int $courseId,
        int $sessionId,
        int $evaluationId,
        int $submissionId
    ) {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return redirect()->route('login');
        }

        $result = $this->submissionService->getFinalResult($submissionId);

        if (!$result->ok()) {
            Log::error('EvaluationSubmissionController@result error', [
                'submission_id' => $submissionId,
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'error' => $result->error(),
            ]);

            abort(500, 'No se pudo cargar el resultado');
        }

        $data = $result->data();
        $evaluationResult = $this->evaluationService->getEvaluation($evaluationId);

        if (!$evaluationResult->ok()) {
            Log::error('EvaluationSubmissionController@result evaluation error', [
                'submission_id' => $submissionId,
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'error' => $evaluationResult->error(),
            ]);

            abort(500, 'No se pudo cargar la evaluación');
        }

        return view('mis-cursos.evaluation.run', [
            'courseId' => $courseId,
            'sessionId' => $sessionId,
            'evaluationId' => $evaluationId,
            'evaluacion' => $evaluationResult->data()['evaluacion'],
            'preguntas' => $evaluationResult->data()['preguntas'],
            'rendicion' => $data['submission'] ?? null,
            'respuestas' => $data['answers'] ?? [],
            'resultadoFinal' => $data,
        ]);
    }

    private function resolveApiErrorMessage(mixed $error, string $fallback): string
    {
        $payload = is_array($error) ? $error : (array) $error;
        $body = $payload['body'] ?? null;

        if (is_string($body)) {
            $decoded = json_decode($body, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        return $payload['message']
            ?? $payload['error']
            ?? $payload['body']
            ?? $fallback;
    }

    public function listarNotasAlumnoPorCurso(int $courseId)
    {
        $response = $this->service->listarNotasAlumnoPorCurso($courseId);

        if (!($response['ok'] ?? false)) {
            return response()->json([
                'message' => $response['message'] ?? 'No se pudieron obtener las notas.',
            ], 500);
        }

        return response()->json(
            $response['data'] ?? [],
            200
        );
    }
}
