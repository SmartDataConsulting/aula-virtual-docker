<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use Illuminate\Support\Facades\Log;

class EvaluationSubmissionService
{
    public function __construct(
        private readonly ApiServiciosClient $client
    ) {
    }

    public function getOrStart(int $evaluationId): ServiceResult
    {
        $result = $this->client->obtenerOIniciarRendicionAlumno($evaluationId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();

        return ServiceResult::success([
            'evaluation' => $this->normalizeEvaluation($payload['evaluacion'] ?? []),
            'submission' => $this->normalizeSubmission($payload['rendicion'] ?? []),
            'answers' => $this->normalizeAnswers($payload['respuestas'] ?? []),
        ]);
    }

    public function saveAnswer(
        int $evaluationId,
        int $questionId,
        ?int $optionId
    ): ServiceResult {
        $result = $this->client->guardarRespuestaRendicionAlumno(
            $evaluationId,
            $questionId,
            $optionId
        );

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();
        $submission = (array) ($payload['rendicion'] ?? []);

        return ServiceResult::success([
            'ok' => (bool) ($payload['ok'] ?? true),
            'submission' => [
                'submission_id' => $submission['rendicion_id'] ?? null,
                'rendicion_id' => $submission['rendicion_id'] ?? null,
                'question_id' => $submission['pregunta_id'] ?? null,
                'pregunta_id' => $submission['pregunta_id'] ?? null,
                'option_id' => $submission['opcion_id'] ?? null,
                'opcion_id' => $submission['opcion_id'] ?? null,
                'is_correct' => isset($submission['es_correcta'])
                    ? (bool) $submission['es_correcta']
                    : null,
                'es_correcta' => isset($submission['es_correcta'])
                    ? (bool) $submission['es_correcta']
                    : null,
                'score' => isset($submission['puntaje_obtenido'])
                    ? (float) $submission['puntaje_obtenido']
                    : 0,
                'puntaje_obtenido' => isset($submission['puntaje_obtenido'])
                    ? (float) $submission['puntaje_obtenido']
                    : 0,
                'answered_count' => isset($submission['respondidas'])
                    ? (int) $submission['respondidas']
                    : 0,
                'respondidas' => isset($submission['respondidas'])
                    ? (int) $submission['respondidas']
                    : 0,
            ],
        ]);
    }

    public function getPartialResult(int $evaluationId): ServiceResult
    {
        $result = $this->client->obtenerResultadoParcialRendicionAlumno($evaluationId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();

        return ServiceResult::success([
            'submission' => $this->normalizeSubmission($payload['rendicion'] ?? []),
            'answers' => $this->normalizeAnswers($payload['respuestas'] ?? []),
            'answered_count' => isset($payload['respondidas']) ? (int) $payload['respondidas'] : 0,
            'respondidas' => isset($payload['respondidas']) ? (int) $payload['respondidas'] : 0,
            'score' => isset($payload['puntaje']) ? (float) $payload['puntaje'] : 0,
            'puntaje' => isset($payload['puntaje']) ? (float) $payload['puntaje'] : 0,
        ]);
    }

    public function finalize(int $evaluationId): ServiceResult
    {
        $result = $this->client->finalizarRendicionAlumno($evaluationId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();

        return ServiceResult::success([
            'evaluation' => $this->normalizeFinalEvaluation($payload['evaluacion'] ?? []),
            'submission' => $this->normalizeFinalSubmission($payload['rendicion'] ?? [], $payload),
            'answers' => $this->normalizeAnswers($payload['respuestas'] ?? []),
        ]);
    }

    public function getFinalResult(int $submissionId): ServiceResult
    {
        $result = $this->client->obtenerResultadoFinalRendicionAlumno($submissionId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();

        return ServiceResult::success([
            'evaluation' => $this->normalizeFinalEvaluation($payload['evaluacion'] ?? []),
            'submission' => $this->normalizeFinalSubmission($payload['rendicion'] ?? [], $payload),
            'answers' => $this->normalizeAnswers($payload['respuestas'] ?? []),
        ]);
    }

    private function normalizeEvaluation(mixed $evaluation): array
    {
        $data = is_array($evaluation) ? $evaluation : (array) $evaluation;

        return [
            'id' => $data['evaluacion_id'] ?? null,
            'evaluacion_id' => $data['evaluacion_id'] ?? null,
            'name' => $data['nombre'] ?? '',
            'nombre' => $data['nombre'] ?? '',
            'type_id' => $data['tipo_param_id'] ?? null,
            'tipo_param_id' => $data['tipo_param_id'] ?? null,
            'type' => $data['tipo_descripcion'] ?? null,
            'tipo_descripcion' => $data['tipo_descripcion'] ?? null,
            'time_minutes' => $data['tiempo_minutos'] ?? null,
            'tiempo_minutos' => $data['tiempo_minutos'] ?? null,
            'pass_score' => (int) ($data['puntaje_aprobacion'] ?? 0),
            'published' => (bool) ($data['publicada'] ?? false),
            'publicada' => (bool) ($data['publicada'] ?? false),
        ];
    }

    private function normalizeFinalEvaluation(mixed $evaluation): ?array
    {
        $data = is_array($evaluation) ? $evaluation : (array) $evaluation;

        if (empty($data)) {
            return null;
        }

        return [
            'id' => $data['evaluacion_id'] ?? null,
            'evaluacion_id' => $data['evaluacion_id'] ?? null,
            'name' => $data['nombre'] ?? '',
            'nombre' => $data['nombre'] ?? '',
            'pass_score' => (int) ($data['puntaje_aprobacion'] ?? 0),
        ];
    }

    private function normalizeSubmission(mixed $submission): ?array
    {
        $data = is_array($submission) ? $submission : (array) $submission;

        if (empty($data)) {
            return null;
        }

        return [
            'id' => $data['rendicion_id'] ?? null,
            'submission_id' => $data['rendicion_id'] ?? null,
            'rendicion_id' => $data['rendicion_id'] ?? null,
            'evaluation_id' => $data['evaluacion_id'] ?? null,
            'evaluacion_id' => $data['evaluacion_id'] ?? null,
            'student_email' => $data['alumno_correo'] ?? null,
            'alumno_correo' => $data['alumno_correo'] ?? null,
            'status' => $data['estado'] ?? null,
            'estado' => $data['estado'] ?? null,
            'started_at' => $data['fecha_inicio'] ?? null,
            'fecha_inicio' => $data['fecha_inicio'] ?? null,
            'finished_at' => $data['fecha_fin'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'score' => isset($data['puntaje_total']) ? (float) $data['puntaje_total'] : null,
            'puntaje_total' => isset($data['puntaje_total']) ? (float) $data['puntaje_total'] : null,
            'answered_count' => isset($data['respondidas']) ? (int) $data['respondidas'] : 0,
            'respondidas' => isset($data['respondidas']) ? (int) $data['respondidas'] : 0,
        ];
    }

    private function normalizeFinalSubmission(mixed $submission, array $payload = []): array
    {
        $data = is_array($submission) ? $submission : (array) $submission;

        return [
            'id' => $data['rendicion_id'] ?? null,
            'submission_id' => $data['rendicion_id'] ?? null,
            'rendicion_id' => $data['rendicion_id'] ?? null,
            'evaluation_id' => $data['evaluacion_id'] ?? null,
            'evaluacion_id' => $data['evaluacion_id'] ?? null,
            'student_email' => $data['alumno_correo'] ?? null,
            'alumno_correo' => $data['alumno_correo'] ?? null,
            'status' => $data['estado'] ?? null,
            'estado' => $data['estado'] ?? null,
            'started_at' => $data['fecha_inicio'] ?? null,
            'fecha_inicio' => $data['fecha_inicio'] ?? null,
            'finished_at' => $data['fecha_fin'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'score' => isset($data['puntaje_total'])
                ? (float) $data['puntaje_total']
                : (isset($payload['puntaje_total']) ? (float) $payload['puntaje_total'] : 0),
            'puntaje_total' => isset($data['puntaje_total'])
                ? (float) $data['puntaje_total']
                : (isset($payload['puntaje_total']) ? (float) $payload['puntaje_total'] : 0),
            'correct_count' => isset($data['correctas'])
                ? (int) $data['correctas']
                : (isset($payload['correctas']) ? (int) $payload['correctas'] : 0),
            'correctas' => isset($data['correctas'])
                ? (int) $data['correctas']
                : (isset($payload['correctas']) ? (int) $payload['correctas'] : 0),
            'incorrect_count' => isset($data['incorrectas'])
                ? (int) $data['incorrectas']
                : (isset($payload['incorrectas']) ? (int) $payload['incorrectas'] : 0),
            'incorrectas' => isset($data['incorrectas'])
                ? (int) $data['incorrectas']
                : (isset($payload['incorrectas']) ? (int) $payload['incorrectas'] : 0),
            'answered_count' => isset($data['respondidas'])
                ? (int) $data['respondidas']
                : (isset($payload['respondidas']) ? (int) $payload['respondidas'] : 0),
            'respondidas' => isset($data['respondidas'])
                ? (int) $data['respondidas']
                : (isset($payload['respondidas']) ? (int) $payload['respondidas'] : 0),
            'question_count' => isset($data['total_preguntas'])
                ? (int) $data['total_preguntas']
                : (isset($payload['total_preguntas']) ? (int) $payload['total_preguntas'] : null),
            'total_preguntas' => isset($data['total_preguntas'])
                ? (int) $data['total_preguntas']
                : (isset($payload['total_preguntas']) ? (int) $payload['total_preguntas'] : null),
        ];
    }

    private function normalizeAnswers(array $answers): array
    {
        return collect($answers)
            ->map(function ($answer) {
                $item = is_array($answer) ? $answer : (array) $answer;

                return [
                    'id' => $item['respuesta_id'] ?? null,
                    'respuesta_id' => $item['respuesta_id'] ?? null,
                    'question_id' => $item['pregunta_id'] ?? null,
                    'pregunta_id' => $item['pregunta_id'] ?? null,
                    'option_id' => $item['opcion_id'] ?? null,
                    'opcion_id' => $item['opcion_id'] ?? null,
                    'is_correct' => isset($item['es_correcta'])
                        ? (bool) $item['es_correcta']
                        : null,
                    'es_correcta' => isset($item['es_correcta'])
                        ? (bool) $item['es_correcta']
                        : null,
                    'score' => isset($item['puntaje_obtenido'])
                        ? (float) $item['puntaje_obtenido']
                        : 0,
                    'puntaje_obtenido' => isset($item['puntaje_obtenido'])
                        ? (float) $item['puntaje_obtenido']
                        : 0,
                ];
            })
            ->values()
            ->all();
    }

    public function listarNotasAlumnoPorCurso(int $courseId): array
    {
        $result = $this->client->listarNotasAlumnoPorCurso($courseId);

        if (!$result->ok()) {
            $error = $result->error();

            return [
                'ok' => false,
                'message' => $error['message'] ?? $error['error'] ?? $error['body'] ?? 'No se pudieron obtener las notas.',
                'data' => [],
            ];
        }

        return [
            'ok' => true,
            'data' => $result->data(),
        ];
    }

    public function descargarArchivoEntregaTrabajoBackoffice(
    int $attachmentId
): ServiceResult {
    $result = $this->client
        ->descargarArchivoEntregaTrabajoBackoffice($attachmentId);

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    return ServiceResult::success(
        $result->data(),
        $result->status()
    );
}
}
