<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\PerformanceCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class EvaluationService
{
    private const WORK_TYPES = [3, 4];

    public function __construct(
        private readonly ApiServiciosClient $client
    ) {
    }

    public function listByCourse(int $courseId): ServiceResult
    {
        return PerformanceCache::remember(
            $this->courseEvaluationsCacheKey($courseId),
            PerformanceCache::COURSE_LIST_TTL,
            fn () => $this->listByCourseFresh($courseId)
        );
    }

    private function listByCourseFresh(int $courseId): ServiceResult
    {
        $result = $this->client->listarEvaluacionesPorCurso($courseId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();

        $course = $payload['course'] ?? null;
        $rows   = $payload['evaluations'] ?? [];

        $evaluations = collect($rows)
            ->map(fn ($e) => $this->normalizeEvaluation($e))
            ->filter(fn ($e) => $e['id'] !== null)
            ->values();

        return ServiceResult::success([
            'course' => $course,
            'evaluations' => $evaluations
        ]);
    }

    public function getCourseQualificationsDashboard(int $courseId): ServiceResult
    {
        return PerformanceCache::remember(
            $this->courseQualificationsCacheKey($courseId),
            PerformanceCache::COURSE_LIST_TTL,
            fn () => $this->getCourseQualificationsDashboardFresh($courseId)
        );
    }

    private function getCourseQualificationsDashboardFresh(int $courseId): ServiceResult
    {
        $result = $this->client->obtenerResumenCalificacionesCurso($courseId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();
        $course = is_array($payload['course'] ?? null) ? $payload['course'] : [];
        $rows = collect($payload['evaluations'] ?? [])
            ->map(function ($item) {
                $data = is_array($item) ? $item : (array) $item;
                $evaluationId = (int) ($data['evaluacion_id'] ?? 0);
                $courseSessionEvaluationId = (int) ($data['curso_sesion_evaluacion_id'] ?? 0);

                return [
                    // Keep the relation identifier for gradebook cells, but expose
                    // the real evaluation identifier for detail API operations.
                    'id' => $courseSessionEvaluationId ?: $evaluationId,
                    'course_session_evaluation_id' => $courseSessionEvaluationId,
                    'evaluation_id' => $evaluationId ?: $courseSessionEvaluationId,
                    'name' => (string) ($data['nombre'] ?? 'Evaluacion'),
                    'type_id' => (int) ($data['tipo_param_id'] ?? 0),
                    'type' => (string) ($data['tipo_descripcion'] ?? 'Evaluacion'),
                    'published' => (bool) ($data['publicada'] ?? false),
                    'weight_percent' => (float) ($data['peso'] ?? 0),
                    'pass_score' => (int) ($data['puntaje_aprobacion'] ?? 0),
                    'deadline' => $data['fecha_limite'] ?? null,
                    'created_at' => $data['created_at'] ?? null,
                    'students_total' => (int) ($data['students_total'] ?? 0),
                    'rendered_count' => (int) ($data['rendered_count'] ?? 0),
                    'absent_count' => (int) ($data['absent_count'] ?? 0),
                    'average_score' => (float) ($data['average_score'] ?? 0),
                    'max_score' => (float) ($data['max_score'] ?? 0),
                    'min_score' => (float) ($data['min_score'] ?? 0),
                    'failed_count' => (int) ($data['failed_count'] ?? 0),
                    'delivered_count' => (int) ($data['delivered_count'] ?? 0),
                    'missing_count' => (int) ($data['missing_count'] ?? 0),
                    'corrected_count' => isset($data['corrected_count']) ? (int) $data['corrected_count'] : null,
                    'pending_correction_count' => (int) ($data['pending_correction_count'] ?? 0),
                    'progress_percent' => (float) ($data['progress_percent'] ?? 0),
                    'status' => (string) ($data['status'] ?? 'Pendiente'),
                    'is_exam' => (bool) ($data['is_exam'] ?? false),
                    'is_work' => (bool) ($data['is_work'] ?? false),
                    'auto_corrected' => (bool) ($data['auto_corrected'] ?? false),
                ];
            })
            ->filter(fn (array $evaluation) => $evaluation['id'] > 0)
            ->values();

        $pending = collect($payload['pending_actions'] ?? [])
            ->map(function ($item) {
                $data = is_array($item) ? $item : (array) $item;

                return [
                    'id' => (int) ($data['evaluacion_id'] ?? 0),
                    'name' => (string) ($data['nombre'] ?? 'Evaluacion'),
                    'type' => (string) ($data['tipo_descripcion'] ?? 'Trabajo'),
                    'pending_correction_count' => (int) ($data['pending_correction_count'] ?? 0),
                    'deadline' => $data['fecha_limite'] ?? null,
                    'urgency' => (string) ($data['urgency'] ?? 'Pendiente'),
                ];
            })
            ->filter(fn (array $evaluation) => $evaluation['id'] > 0)
            ->values();

        return ServiceResult::success([
            'course' => [
                'id' => (int) ($course['id'] ?? $courseId),
                'name' => (string) ($course['name'] ?? 'Curso'),
                'subtitle' => (string) ($course['subtitle'] ?? ('Gestion de evaluaciones - ' . now()->format('Y'))),
            ],
            'evaluations' => $rows,
            'pending_actions' => $pending,
        ]);
    }

    public function getEvaluationParticipants(int $evaluationId): ServiceResult
    {
        $result = $this->client->listarParticipantesEvaluacion($evaluationId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();
        $rows = $this->extractRows($payload, ['participantes', 'items', 'data']);
        $participants = collect($rows)
            ->map(fn ($item) => $this->normalizeQualificationParticipant($item))
            ->filter(fn (array $participant) => $participant['id'] > 0 || $participant['delivery_id'] > 0 || $participant['name'] !== 'Participante')
            ->values();

        $summary = is_array($payload) ? $payload : [];
        $deliveredCount = $participants->where('has_delivery', true)->count();
        $correctedCount = $participants->where('status_key', 'corrected')->count();
        $pendingCount = $participants
            ->filter(fn (array $participant) => in_array($participant['status_key'], ['draft', 'pending', 'reviewing'], true))
            ->count();

        return ServiceResult::success([
            'participants' => $participants,
            'summary' => [
                'total' => (int) ($summary['total'] ?? $summary['total_participantes'] ?? $participants->count()),
                'delivered_count' => (int) ($summary['entregados'] ?? $summary['delivered_count'] ?? $deliveredCount),
                'corrected_count' => (int) ($summary['corregidos'] ?? $summary['corrected_count'] ?? $correctedCount),
                'pending_count' => (int) ($summary['pendientes'] ?? $summary['pending_count'] ?? $pendingCount),
            ],
        ]);
    }

    public function listEvaluationSubsanations(int $evaluationId): ServiceResult
    {
        $result = $this->client->listarSubsanacionesEvaluacion($evaluationId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();
        $rows = $this->extractRows($payload, ['subsanaciones', 'items', 'data']);
        $items = collect($rows)
            ->map(fn ($item) => $this->normalizeSubsanation($item, $evaluationId))
            ->filter(fn (array $item) => $item['id'] > 0 || $item['student_email'] !== '')
            ->values();

        return ServiceResult::success([
            'items' => $items,
            'total' => (int) (is_array($payload) ? ($payload['total'] ?? $items->count()) : $items->count()),
        ]);
    }

    public function registerExamSubsanation(int $evaluationId, array $payload): ServiceResult
    {
        $result = $this->client->registrarSubsanacionExamen($evaluationId, $payload);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $data = $result->data();
        $item = is_array($data['subsanacion'] ?? null) ? $data['subsanacion'] : $data;

        return ServiceResult::success(
            $this->normalizeSubsanation($item, $evaluationId),
            $result->status()
        );
    }

    public function registerWorkSubsanation(int $evaluationId, array $payload): ServiceResult
    {
        $result = $this->client->registrarSubsanacionTrabajo($evaluationId, $payload);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $data = $result->data();
        $item = is_array($data['subsanacion'] ?? null) ? $data['subsanacion'] : $data;

        return ServiceResult::success(
            $this->normalizeSubsanation($item, $evaluationId),
            $result->status()
        );
    }

    public function updateEvaluationSubsanation(int $evaluationId, int $subsanationId, array $payload): ServiceResult
    {
        $result = $this->client->actualizarSubsanacionEvaluacion($evaluationId, $subsanationId, $payload);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $data = $result->data();
        $item = is_array($data['subsanacion'] ?? null) ? $data['subsanacion'] : $data;

        return ServiceResult::success(
            $this->normalizeSubsanation($item, $evaluationId),
            $result->status()
        );
    }

    public function downloadSubsanationEvidence(string $path): ServiceResult
    {
        return $this->client->descargarEvidenciaSubsanacion($path);
    }

    public function getEvaluationRevision(int $evaluationId, int $deliveryId): ServiceResult
    {
        $result = $this->client->obtenerRevisionEntregaEvaluacion($evaluationId, $deliveryId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success(
            $this->normalizeQualificationRevision($result->data())
        );
    }

    public function saveEvaluationReview(
        int $evaluationId,
        int $deliveryId,
        array $payload
    ): ServiceResult {
        $result = $this->client->guardarRevisionEntregaEvaluacion(
            $evaluationId,
            $deliveryId,
            $payload
        );

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success(
            $this->normalizeQualificationRevision($result->data())
        );
    }

    private function normalizeEvaluation(mixed $item): array
    {
        $data = is_array($item) ? $item : (array) $item;

        return [
            'id' => $data['evaluacion_id'] ?? null,
            'evaluacion_id' => $data['evaluacion_id'] ?? null,
            'name' => $data['nombre'] ?? '',
            'nombre' => $data['nombre'] ?? '',
            'type_id' => $data['tipo_param_id'] ?? null,
            'type' => $data['tipo_descripcion'] ?? null,
            'weight_percent' => $data['peso'] ?? $data['peso_porcentaje'] ?? null,
            'peso' => $data['peso'] ?? $data['peso_porcentaje'] ?? null,
            'time_minutes' => $data['tiempo_minutos'] ?? null,
            'pass_score' => (int) ($data['puntaje_aprobacion'] ?? 0),
            'published' => (bool) ($data['publicada'] ?? false),
            'publicada' => (bool) ($data['publicada'] ?? false),
        ];
    }

    /**
     * Crear evaluación
     */
    public function create(array $payload): ServiceResult
    {
        $result = $this->client->crearEvaluacion($payload);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        PerformanceCache::forgetCourseLists();
        $this->forgetCourseEvaluationCaches((int) ($payload['curso_id'] ?? $payload['course_id'] ?? 0));

        return ServiceResult::success($result->data());
    }

    /**
     * AUTOSAVE evaluación
     */
    public function autosave(int $evaluationId, array $payload): ServiceResult
    {
        $result = $this->client->autosaveEvaluacion(
            $evaluationId,
            $payload
        );

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        PerformanceCache::forgetCourseLists();
        $this->forgetCourseEvaluationCaches((int) ($payload['curso_id'] ?? $payload['course_id'] ?? 0));

        return ServiceResult::success($result->data());
    }

    /**
     * Obtener evaluación completa
     */
    public function getEvaluation(int $evaluationId): ServiceResult
    {
        $result = $this->client->obtenerEvaluacion($evaluationId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();

        $evaluacion = (array) ($payload['evaluacion'] ?? []);
        $preguntas  = $payload['preguntas'] ?? [];

        $questions = collect($preguntas)
            ->map(fn ($q, $index) => $this->normalizeQuestion($q, $index + 1))
            ->values();

        return ServiceResult::success([
            'evaluacion' => [
                'id' => $evaluacion['evaluacion_id'] ?? null,
                'evaluacion_id' => $evaluacion['evaluacion_id'] ?? null,
                'name' => $evaluacion['nombre'] ?? '',
                'nombre' => $evaluacion['nombre'] ?? '',
                'course_name' => $evaluacion['curso_nombre'] ?? '',
                'type_id' => $evaluacion['tipo_param_id'] ?? null,
                'weight_percent' => isset($evaluacion['peso'])? (float) $evaluacion['peso']: null,
                'time_minutes' => $evaluacion['tiempo_minutos'] ?? null,
                'pass_score' => (int) ($evaluacion['puntaje_aprobacion'] ?? 0),
                'published' => (bool) ($evaluacion['publicada'] ?? false),
                'publicada' => (bool) ($evaluacion['publicada'] ?? false),
            ],
            'preguntas' => $questions
        ]);
    }

    public function getWorkEvaluation(int $evaluationId): ServiceResult
    {
        $result = $this->client->obtenerTrabajoEvaluacion($evaluationId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();
        $evaluacion = (array) ($payload['evaluacion'] ?? []);
        $trabajo = (array) ($payload['trabajo'] ?? []);
        $rubrica = (array) ($trabajo['rubrica'] ?? []);
        $criterios = collect($rubrica['criterios'] ?? [])
            ->map(function ($criterio, $index) {
                $item = is_array($criterio) ? $criterio : (array) $criterio;
                $description = $item['descripcion'] ?? '';
                $name = $item['nombre'] ?? $description;

                return [
                    'id' => $item['criterio_id'] ?? null,
                    'criterio_id' => $item['criterio_id'] ?? null,
                    'nombre' => $name,
                    'descripcion' => $description,
                    'puntaje_max' => $item['puntaje_max'] ?? null,
                    'orden' => $item['orden'] ?? ($index + 1),
                ];
            })
            ->sortBy('orden')
            ->values()
            ->all();
        $puntajeMaximo = is_numeric($trabajo['puntaje_max'] ?? null)
            ? (float) $trabajo['puntaje_max']
            : array_sum(array_map(
                static fn (array $criterio): float => (float) ($criterio['puntaje_max'] ?? 0),
                $criterios
            ));

        return ServiceResult::success([
            'evaluacion' => [
                'id' => $evaluacion['evaluacion_id'] ?? null,
                'evaluacion_id' => $evaluacion['evaluacion_id'] ?? null,
                'name' => $evaluacion['nombre'] ?? '',
                'nombre' => $evaluacion['nombre'] ?? '',
                'type_id' => $evaluacion['tipo_param_id'] ?? null,
                'type' => $evaluacion['tipo_descripcion'] ?? null,
                'weight_percent' => isset($evaluacion['peso'])? (float) $evaluacion['peso']: null,
                'time_minutes' => $evaluacion['tiempo_minutos'] ?? null,
                'pass_score' => (int) ($evaluacion['puntaje_aprobacion'] ?? 0),
                'published' => (bool) ($evaluacion['publicada'] ?? false),
                'publicada' => (bool) ($evaluacion['publicada'] ?? false),
            ],
            'trabajo' => [
                'trabajo_id' => $trabajo['trabajo_id'] ?? null,
                'evaluacion_id' => $trabajo['evaluacion_id'] ?? ($evaluacion['evaluacion_id'] ?? null),
                'descripcion' => $trabajo['descripcion'] ?? '',
                'fecha_limite' => $trabajo['fecha_limite'] ?? null,
                'fecha_limite_label' => $this->formatWorkDeadline($trabajo['fecha_limite'] ?? null),
                'puntaje_max' => $puntajeMaximo,
                'rubrica' => [
                    'rubrica_id' => $rubrica['rubrica_id'] ?? null,
                    'trabajo_id' => $rubrica['trabajo_id'] ?? ($trabajo['trabajo_id'] ?? null),
                    'nombre' => $rubrica['nombre'] ?? 'Rúbrica general',
                    'criterios' => $criterios,
                ],
            ],
        ]);
    }

    public function getStudentWorkEvaluation(int $evaluationId): ServiceResult
    {
        $result = $this->client->obtenerTrabajoAlumno($evaluationId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $payload = $result->data();
        $evaluacion = (array) ($payload['evaluacion'] ?? []);
        $trabajo = (array) ($payload['trabajo'] ?? []);
        $rubrica = (array) ($trabajo['rubrica'] ?? []);
        $entrega = (array) ($payload['entrega'] ?? []);

        $criterios = collect($rubrica['criterios'] ?? [])
            ->map(function ($criterio, $index) {
                $item = is_array($criterio) ? $criterio : (array) $criterio;
                $description = $item['descripcion'] ?? '';
                $name = $item['nombre'] ?? $description;

                return [
                    'id' => $item['criterio_id'] ?? null,
                    'criterio_id' => $item['criterio_id'] ?? null,
                    'nombre' => $name,
                    'descripcion' => $description,
                    'puntaje_max' => $item['puntaje_max'] ?? null,
                    'orden' => $item['orden'] ?? ($index + 1),
                ];
            })
            ->sortBy('orden')
            ->values()
            ->all();
        $puntajeMaximo = is_numeric($trabajo['puntaje_max'] ?? null)
            ? (float) $trabajo['puntaje_max']
            : array_sum(array_map(
                static fn (array $criterio): float => (float) ($criterio['puntaje_max'] ?? 0),
                $criterios
            ));

        $archivos = collect($entrega['archivos'] ?? [])
            ->map(function ($archivo) {
                $item = is_array($archivo) ? $archivo : (array) $archivo;

                return [
                    'archivo_id' => $item['archivo_id'] ?? null,
                    'nombre_original' => $item['nombre_original'] ?? 'Archivo',
                    'url_descarga' => $item['url_descarga'] ?? null,
                    'peso_bytes' => $item['peso_bytes'] ?? null,
                    'mime_type' => $item['mime_type'] ?? null,
                ];
            })
            ->values()
            ->all();

        return ServiceResult::success([
            'evaluacion' => [
                'id' => $evaluacion['evaluacion_id'] ?? null,
                'evaluacion_id' => $evaluacion['evaluacion_id'] ?? null,
                'name' => $evaluacion['nombre'] ?? '',
                'nombre' => $evaluacion['nombre'] ?? '',
                'type_id' => $evaluacion['tipo_param_id'] ?? null,
                'tipo_param_id' => $evaluacion['tipo_param_id'] ?? null,
                'type' => $evaluacion['tipo_descripcion'] ?? null,
                'tipo_descripcion' => $evaluacion['tipo_descripcion'] ?? null,
                'weight_percent' => isset($evaluacion['peso']) ? (float) $evaluacion['peso'] : null,
                'peso' => isset($evaluacion['peso']) ? (float) $evaluacion['peso'] : null,
                'time_minutes' => $evaluacion['tiempo_minutos'] ?? null,
                'pass_score' => (int) ($evaluacion['puntaje_aprobacion'] ?? 0),
                'published' => (bool) ($evaluacion['publicada'] ?? false),
                'publicada' => (bool) ($evaluacion['publicada'] ?? false),
            ],
            'trabajo' => [
                'trabajo_id' => $trabajo['trabajo_id'] ?? null,
                'evaluacion_id' => $trabajo['evaluacion_id'] ?? ($evaluacion['evaluacion_id'] ?? null),
                'descripcion' => $trabajo['descripcion'] ?? '',
                'fecha_limite' => $trabajo['fecha_limite'] ?? null,
                'fecha_limite_label' => $this->formatWorkDeadline($trabajo['fecha_limite'] ?? null),
                'puntaje_max' => $puntajeMaximo,
                'rubrica' => [
                    'rubrica_id' => $rubrica['rubrica_id'] ?? null,
                    'trabajo_id' => $rubrica['trabajo_id'] ?? ($trabajo['trabajo_id'] ?? null),
                    'nombre' => $rubrica['nombre'] ?? 'Rubrica general',
                    'criterios' => $criterios,
                ],
            ],
            'entrega' => array_merge(
                $this->normalizeStudentDelivery($entrega),
                [
                    'archivos' => $archivos,
                ]
            ),
        ]);
    }

    public function saveStudentWorkSubmission(int $evaluationId, array $payload): ServiceResult
    {
        $result = $this->client->guardarEntregaTrabajoAlumno($evaluationId, $payload);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success([
            'ok' => (bool) ($result->data()['ok'] ?? true),
            'message' => $result->data()['message'] ?? 'Entrega guardada en borrador',
            'entrega' => $this->normalizeStudentDelivery($result->data()['entrega'] ?? []),
        ]);
    }

    public function finalizeStudentWorkSubmission(int $evaluationId, array $payload): ServiceResult
    {
        $result = $this->client->finalizarEntregaTrabajoAlumno($evaluationId, $payload);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success([
            'ok' => (bool) ($result->data()['ok'] ?? true),
            'message' => $result->data()['message'] ?? 'Entrega finalizada correctamente',
            'entrega' => $this->normalizeStudentDelivery($result->data()['entrega'] ?? []),
        ]);
    }

    public function downloadStudentWorkAttachment(int $attachmentId): ServiceResult
    {
        return $this->client->descargarArchivoEntregaTrabajoAlumno($attachmentId);
    }

    public function saveWorkEvaluation(int $evaluationId, array $payload): ServiceResult
    {
        $result = $this->client->guardarTrabajoEvaluacion($evaluationId, $payload);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        PerformanceCache::forgetCourseLists();
        $this->forgetEvaluationWideCaches();

        return ServiceResult::success($result->data());
    }

    private function normalizeQuestion(mixed $item, int $order): array
    {
        $data = is_array($item) ? $item : (array) $item;

        return [
            'id' => $data['pregunta_id'] ?? null,
            'type_id' => $data['tipo_param_id'] ?? 1,
            'text' => $data['texto'] ?? '',
            'feedback' => $data['feedback'] ?? '',
            'points' => $data['puntaje'] ?? 1,
            'order' => $data['orden'] ?? $order,
            'options' => collect($data['opciones'] ?? [])
                ->map(function ($o) {
                    $opt = is_array($o) ? $o : (array) $o;

                    return [
                        'id' => $opt['opcion_id'] ?? null,
                        'text' => $opt['texto'] ?? '',
                        'correct' => (bool) ($opt['es_correcta'] ?? false),
                        'order' => $opt['orden'] ?? 1
                    ];
                })
                ->values()
        ];
    }

    public function publicarEvaluacion(int $evaluacionId)
    {
        $result = $this->client->publicarEvaluacion($evaluacionId);

        if (!$result->ok()) {
            $error = $result->error();
            $body = $error['body'] ?? null;
            $decodedBody = is_string($body) ? json_decode($body, true) : null;

            return [
                'ok' => false,
                'error' => $decodedBody['error']
                    ?? $decodedBody['message']
                    ?? $error['error']
                    ?? $error['message']
                    ?? 'Error al publicar la evaluación'
            ];
        }

        PerformanceCache::forgetCourseLists();
        $this->forgetEvaluationWideCaches();

        return [
            'ok' => true
        ];
    }

    public function duplicateEvaluation(
        int $courseId,
        int $evaluationId,
        int $typeId = 0
    ): array {
        $listResult = $this->listByCourse($courseId);

        if (!$listResult->ok()) {
            return [
                'ok' => false,
                'error' => $listResult->error()['message']
                    ?? $listResult->error()['error']
                    ?? 'No se pudo obtener la evaluación a duplicar'
            ];
        }

        $source = collect($listResult->data()['evaluations'] ?? [])
            ->firstWhere('id', $evaluationId);

        if (!is_array($source)) {
            return [
                'ok' => false,
                'error' => 'No se encontró la evaluación a duplicar'
            ];
        }

        $resolvedTypeId = $typeId > 0
            ? $typeId
            : (int) ($source['type_id'] ?? 0);

        return in_array($resolvedTypeId, self::WORK_TYPES, true)
            ? $this->duplicateWorkEvaluation($courseId, $source, $resolvedTypeId)
            : $this->duplicateExamEvaluation($courseId, $source, $resolvedTypeId);
    }

    private function duplicateExamEvaluation(
        int $courseId,
        array $source,
        int $typeId
    ): array {
        $detailResult = $this->getEvaluation((int) ($source['id'] ?? 0));

        if (!$detailResult->ok()) {
            return [
                'ok' => false,
                'error' => $detailResult->error()['message']
                    ?? $detailResult->error()['error']
                    ?? 'No se pudo cargar la evaluación original'
            ];
        }

        $detail = $detailResult->data();
        $evaluation = is_array($detail['evaluacion'] ?? null)
            ? $detail['evaluacion']
            : [];
        $questions = collect($detail['preguntas'] ?? [])
            ->map(function ($question) {
                $item = is_array($question) ? $question : (array) $question;

                return [
                    'pregunta_id' => null,
                    'tipo_param_id' => (int) ($item['type_id'] ?? 1),
                    'texto' => (string) ($item['text'] ?? ''),
                    'feedback' => (string) ($item['feedback'] ?? ''),
                    'puntaje' => $item['points'] ?? 1,
                    'orden' => (int) ($item['order'] ?? 1),
                    'opciones' => collect($item['options'] ?? [])
                        ->map(function ($option, int $index) {
                            $opt = is_array($option) ? $option : (array) $option;

                            return [
                                'opcion_id' => null,
                                'texto' => (string) ($opt['text'] ?? ''),
                                'es_correcta' => !empty($opt['correct']) ? 1 : 0,
                                'orden' => (int) ($opt['order'] ?? ($index + 1)),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $name = (string) ($evaluation['nombre']
            ?? $evaluation['name']
            ?? $source['nombre']
            ?? $source['name']
            ?? 'Evaluación');
        $passScore = (int) ($evaluation['pass_score'] ?? $source['pass_score'] ?? 0);
        $maxScore = (float) $this->calculateQuestionsMaxScore($questions);

        $createResult = $this->create([
            'nombre' => $name,
            'tipo' => $typeId,
            'curso_id' => $courseId,
            'tiempo_minutos' => (int) ($evaluation['time_minutes'] ?? $source['time_minutes'] ?? 0),
            'puntaje_aprobacion' => $passScore,
            'peso' => (float) ($source['peso'] ?? $source['weight_percent'] ?? 0),
            'puntaje_max' => $maxScore
        ]);

        if (!$createResult->ok()) {
            return [
                'ok' => false,
                'error' => $createResult->error()['message']
                    ?? $createResult->error()['error']
                    ?? 'No se pudo crear la copia de la evaluación'
            ];
        }

        $newId = (int) ($createResult->data()['evaluacion_id'] ?? 0);

        if ($newId <= 0) {
            return [
                'ok' => false,
                'error' => 'La copia se creó sin identificador válido'
            ];
        }

        $saveResult = $this->autosave($newId, [
            'evaluacion' => [
                'nombre' => $name,
                'puntaje_aprobacion' => $passScore,
                'puntaje_max' => $maxScore
            ],
            'preguntas' => $questions
        ]);

        if (!$saveResult->ok()) {
            Log::error('EvaluationService duplicate exam save failed', [
                'source_evaluation_id' => $source['id'] ?? null,
                'new_evaluation_id' => $newId,
                'error' => $saveResult->error()
            ]);

            return [
                'ok' => false,
                'error' => $saveResult->error()['message']
                    ?? $saveResult->error()['error']
                    ?? 'La copia se creó, pero no se pudo replicar el contenido'
            ];
        }

        return [
            'ok' => true,
            'newId' => $newId,
            'typeId' => $typeId
        ];
    }

    private function duplicateWorkEvaluation(
        int $courseId,
        array $source,
        int $typeId
    ): array {
        $detailResult = $this->getWorkEvaluation((int) ($source['id'] ?? 0));

        if (!$detailResult->ok()) {
            return [
                'ok' => false,
                'error' => $detailResult->error()['message']
                    ?? $detailResult->error()['error']
                    ?? 'No se pudo cargar el trabajo original'
            ];
        }

        $detail = $detailResult->data();
        $evaluation = is_array($detail['evaluacion'] ?? null)
            ? $detail['evaluacion']
            : [];
        $work = is_array($detail['trabajo'] ?? null)
            ? $detail['trabajo']
            : [];
        $rubric = is_array($work['rubrica'] ?? null)
            ? $work['rubrica']
            : [];
        $criteria = collect($rubric['criterios'] ?? [])
            ->map(function ($criterion, int $index) {
                $item = is_array($criterion) ? $criterion : (array) $criterion;

                return [
                    'nombre' => (string) ($item['nombre'] ?? $item['descripcion'] ?? ''),
                    'descripcion' => (string) ($item['descripcion'] ?? ''),
                    'puntaje_max' => (float) ($item['puntaje_max'] ?? 0),
                    'orden' => (int) ($item['orden'] ?? ($index + 1))
                ];
            })
            ->values()
            ->all();

        $name = (string) ($evaluation['nombre']
            ?? $evaluation['name']
            ?? $source['nombre']
            ?? $source['name']
            ?? 'Trabajo');
        $passScore = (int) ($evaluation['pass_score'] ?? $source['pass_score'] ?? 0);
        $maxScore = (float) ($work['puntaje_max']
            ?? $source['puntaje_max']
            ?? $source['max_score']
            ?? $this->calculateCriteriaMaxScore($criteria));

        $createResult = $this->create([
            'nombre' => $name,
            'tipo' => $typeId,
            'curso_id' => $courseId,
            'tiempo_minutos' => 0,
            'puntaje_aprobacion' => $passScore,
            'peso' => (float) ($source['peso'] ?? $source['weight_percent'] ?? 0),
            'puntaje_max' => $maxScore
        ]);

        if (!$createResult->ok()) {
            return [
                'ok' => false,
                'error' => $createResult->error()['message']
                    ?? $createResult->error()['error']
                    ?? 'No se pudo crear la copia del trabajo'
            ];
        }

        $newId = (int) ($createResult->data()['evaluacion_id'] ?? 0);

        if ($newId <= 0) {
            return [
                'ok' => false,
                'error' => 'La copia se creó sin identificador válido'
            ];
        }

        $saveResult = $this->saveWorkEvaluation($newId, [
            'evaluacion' => [
            'nombre' => $name,
            'tiempo_minutos' => 0,
            'puntaje_aprobacion' => $passScore,
            'peso' => (float) ($source['peso'] ?? $source['weight_percent'] ?? 0),
        ],
            'trabajo' => [
                'descripcion' => (string) ($work['descripcion'] ?? ''),
                'puntaje_max' => $maxScore,
                'rubrica' => [
                    'nombre' => (string) ($rubric['nombre'] ?? 'Rúbrica general'),
                    'criterios' => $criteria
                ]
            ]
        ]);

        if (!$saveResult->ok()) {
            Log::error('EvaluationService duplicate work save failed', [
                'source_evaluation_id' => $source['id'] ?? null,
                'new_evaluation_id' => $newId,
                'error' => $saveResult->error()
            ]);

            return [
                'ok' => false,
                'error' => $saveResult->error()['message']
                    ?? $saveResult->error()['error']
                    ?? 'La copia se creó, pero no se pudo replicar el contenido'
            ];
        }

        return [
            'ok' => true,
            'newId' => $newId,
            'typeId' => $typeId
        ];
    }

    private function calculateQuestionsMaxScore(array $questions): float
    {
        return (float) collect($questions)
            ->sum(fn (array $question) => (float) ($question['puntaje'] ?? 0));
    }

    private function calculateCriteriaMaxScore(array $criteria): float
    {
        return (float) collect($criteria)
            ->sum(fn (array $criterion) => (float) ($criterion['puntaje_max'] ?? 0));
    }

    public function listPublishedByCourseAndType(
    int $courseId,
    int $typeId
    ): ServiceResult
    {
        $result = $this->client
            ->listarEvaluacionesPublicadasPorCursoYTipo($courseId, $typeId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $rows = $result->data();   // ← aquí estaba el error

        $evaluations = collect($rows)
            ->map(fn ($e) => $this->normalizeEvaluation($e))
            ->filter(fn ($e) => $e['id'] !== null)
            ->values();

        return ServiceResult::success([
            'evaluations' => $evaluations
        ]);
    }

    public function evaluate(int $evaluationId, array $answers): ServiceResult
    {
        Log::info('EVALUATION_SERVICE evaluate', [
            'evaluation_id' => $evaluationId,
            'answers_count' => count($answers),
            'answers' => $answers
        ]);

        $result = $this->client->evaluarEvaluacion(
            $evaluationId,
            $answers
        );

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $data = $result->data();

        return ServiceResult::success([
            'correct' => $data['correctas'] ?? 0,
            'incorrect' => $data['incorrectas'] ?? 0,
            'points' => $data['puntos'] ?? 0
        ]);
    }

    public function isWorkType(int $typeId): bool
    {
        return in_array($typeId, self::WORK_TYPES, true);
    }

    private function normalizeQualificationParticipant(mixed $participant): array
    {
        $data = is_array($participant) ? $participant : (array) $participant;
        $delivery = is_array($data['entrega'] ?? null) ? $data['entrega'] : [];

        $id = (int) ($data['participante_id'] ?? $data['alumno_id'] ?? $data['usuario_id'] ?? $data['id'] ?? 0);
        $deliveryId = (int) ($data['entrega_id'] ?? $delivery['entrega_id'] ?? $delivery['id'] ?? 0);
        $firstNames = trim((string) ($data['nombres'] ?? ''));
        $lastNames = trim((string) ($data['apellidos'] ?? ''));
        $name = $lastNames !== '' || $firstNames !== ''
            ? trim($lastNames . ' ' . $firstNames)
            : trim((string) (
            $data['nombre_completo']
            ?? $data['alumno_nombre']
            ?? $data['participante_nombre']
            ?? $data['alumno']
            ?? $data['participante']
            ?? $data['nombre']
            ?? ''
        ));

        if ($name === '') {
            $name = 'Participante';
        }

        $submittedAt = $data['fecha_entrega']
            ?? $data['fecha_envio']
            ?? $data['entregado_en']
            ?? $data['submitted_at']
            ?? $delivery['fecha_entrega']
            ?? $delivery['submitted_at']
            ?? null;

        $score = $this->toNullableFloat(
            $data['nota_final']
            ?? $data['puntaje_total']
            ?? $data['puntaje_obtenido']
            ?? $data['calificacion']
            ?? $data['score']
            ?? $delivery['nota_final']
            ?? $delivery['puntaje_total']
            ?? null
        );

        $maxScore = $this->toNullableFloat(
            $data['puntaje_max']
            ?? $data['puntaje_maximo']
            ?? $data['max_score']
            ?? $delivery['puntaje_max']
            ?? $delivery['puntaje_maximo']
            ?? null
        );

        $status = (string) (
            $data['rendicion_estado']
            ?? $data['entrega_estado']
            ?? $data['estado_revision']
            ?? $data['estado_entrega']
            ?? $data['estado']
            ?? $delivery['estado_revision']
            ?? $delivery['estado']
            ?? ''
        );

        $hasDelivery = $deliveryId > 0
            || (bool) ($data['tiene_entrega'] ?? false)
            || (bool) ($data['entregado'] ?? false)
            || (bool) ($data['rindio'] ?? false)
            || (bool) ($data['finalizada'] ?? false)
            || $submittedAt !== null;

        $statusKey = $this->normalizeQualificationStatusKey(
            $status,
            $hasDelivery,
            (bool) (
                $data['corregido']
                ?? $data['revisado']
                ?? $delivery['corregido']
                ?? ($data['fecha_correccion'] ?? null)
                ?? false
            )
        );

        return [
            'id' => $id,
            'delivery_id' => $deliveryId,
            'name' => $name,
            'initials' => $this->makeInitials($name),
            'status' => $this->humanizeQualificationStatus($statusKey, $status),
            'status_key' => $statusKey,
            'submitted_at' => $submittedAt,
            'score' => $score,
            'max_score' => $maxScore,
            'has_delivery' => $hasDelivery,
            'email' => (string) ($data['correo_personal'] ?? $data['correo'] ?? ''),
            'phone' => (string) ($data['telefono'] ?? ''),
            'corrected_at' => $data['fecha_correccion'] ?? null,
            'finalized' => (bool) ($data['finalizada'] ?? false),
        ];
    }

    private function normalizeQualificationRevision(mixed $payload): array
    {
        $data = is_array($payload) ? $payload : (array) $payload;
        $evaluation = is_array($data['evaluacion'] ?? null) ? $data['evaluacion'] : [];
        $participant = is_array($data['participante'] ?? null)
            ? $data['participante']
            : (is_array($data['alumno'] ?? null) ? $data['alumno'] : []);
        $work = is_array($data['trabajo'] ?? null) ? $data['trabajo'] : [];
        $delivery = is_array($data['entrega'] ?? null)
            ? $data['entrega']
            : (is_array($data['delivery'] ?? null)
                ? $data['delivery']
                : (is_array($work['entrega'] ?? null) ? $work['entrega'] : []));
        $revision = is_array($data['revision'] ?? null)
            ? $data['revision']
            : (is_array($data['detalle_revision'] ?? null) ? $data['detalle_revision'] : []);
        $rubric = is_array($revision['rubrica'] ?? null)
            ? $revision['rubrica']
            : (is_array($data['rubrica'] ?? null)
                ? $data['rubrica']
                : (is_array($work['rubrica'] ?? null) ? $work['rubrica'] : []));

        if (empty($participant)) {
            $participants = collect($this->extractRows($evaluation, ['participantes']))
                ->map(fn ($item) => is_array($item) ? $item : (array) $item);
            $deliveryId = (int) ($delivery['entrega_id'] ?? $delivery['id'] ?? 0);

            $participant = $participants->first(function (array $item) use ($deliveryId) {
                return $deliveryId > 0
                    && (int) ($item['entrega_id'] ?? $item['delivery_id'] ?? 0) === $deliveryId;
            }) ?? [];
        }

        $criteriaRows = $this->extractRows(
            $rubric,
            ['criterios', 'criteria']
        );

        if (empty($criteriaRows)) {
            $criteriaRows = $this->extractRows($revision, ['criterios', 'criteria']);
        }

        if (empty($criteriaRows)) {
            $criteriaRows = $this->extractRows($data, ['criterios', 'criteria']);
        }

        $criteria = collect($criteriaRows)
            ->map(function ($criterion, $index) {
                $item = is_array($criterion) ? $criterion : (array) $criterion;
                $name = (string) ($item['nombre'] ?? $item['titulo'] ?? $item['criterio'] ?? $item['descripcion'] ?? ('Criterio ' . ($index + 1)));
                $description = (string) ($item['descripcion'] ?? $item['detalle'] ?? '');
                $maxScore = (float) (
                    $item['puntaje_max']
                    ?? $item['puntaje_maximo']
                    ?? $item['max_score']
                    ?? 0
                );
                $score = $this->toNullableFloat(
                    $item['puntaje']
                    ?? $item['puntaje_obtenido']
                    ?? $item['puntaje_asignado']
                    ?? $item['score']
                    ?? $item['calificacion']
                    ?? null
                );
                $explicitLevel = isset($item['nivel']) && is_numeric($item['nivel'])
                    ? (int) $item['nivel']
                    : null;

                return [
                    'id' => (int) ($item['criterio_id'] ?? $item['id'] ?? 0),
                    'name' => $name,
                    'description' => $description,
                    'max_score' => $maxScore,
                    'score' => $score,
                    'level' => $explicitLevel !== null && $explicitLevel >= 1 && $explicitLevel <= 5
                        ? $explicitLevel
                        : $this->resolveRubricLevel($score, $maxScore),
                    'comment' => (string) ($item['comentario'] ?? $item['observacion'] ?? $item['feedback'] ?? ''),
                ];
            })
            ->values();

        $attachmentRows = $this->extractRows($delivery, ['archivos', 'adjuntos', 'attachments']);

        if (empty($attachmentRows)) {
            $attachmentRows = $this->extractRows($data, ['archivos', 'adjuntos', 'attachments']);
        }

        $attachments = collect($attachmentRows)
            ->map(function ($attachment) {
                $item = is_array($attachment) ? $attachment : (array) $attachment;
                $bytes = $item['peso_bytes'] ?? $item['size_bytes'] ?? $item['tamano_bytes'] ?? null;

                return [
                    'id' => (int) ($item['archivo_id'] ?? $item['adjunto_id'] ?? $item['id'] ?? 0),
                    'name' => (string) ($item['nombre_original'] ?? $item['nombre'] ?? $item['filename'] ?? 'Archivo'),
                    'mime_type' => (string) ($item['mime_type'] ?? $item['tipo_mime'] ?? ''),
                    'extension' => strtolower((string) pathinfo((string) ($item['nombre_original'] ?? $item['nombre'] ?? ''), PATHINFO_EXTENSION)),
                    'size_bytes' => is_numeric($bytes) ? (int) $bytes : null,
                    'download_url' => $item['url_descarga'] ?? $item['download_url'] ?? $item['url'] ?? null,
                ];
            })
            ->values()
            ->all();

        $totalScore = $this->toNullableFloat(
            $revision['nota_final']
            ?? $revision['puntaje_total']
            ?? $rubric['puntaje_total']
            ?? $delivery['nota_final']
            ?? $delivery['puntaje_total']
            ?? $data['nota_final']
            ?? $data['puntaje_total']
            ?? null
        );
        $maxScore = $this->toNullableFloat(
            $revision['puntaje_max']
            ?? $revision['puntaje_maximo']
            ?? $work['puntaje_max']
            ?? $delivery['puntaje_max']
            ?? $delivery['puntaje_maximo']
            ?? null
        ) ?? (float) $criteria->sum('max_score');

        return [
            'participant' => $this->normalizeQualificationParticipant(array_merge($participant, [
                'entrega_id' => $delivery['entrega_id'] ?? $delivery['id'] ?? null,
                'fecha_entrega' => $delivery['fecha_entrega'] ?? $delivery['submitted_at'] ?? null,
                'estado' => $participant['entrega_estado']
                    ?? $revision['estado']
                    ?? $delivery['estado']
                    ?? null,
                'nota_final' => $totalScore,
                'puntaje_max' => $maxScore,
                'corregido' => $participant['corregido']
                    ?? ($rubric['fecha_correccion'] ?? null ? 1 : 0),
                'fecha_correccion' => $participant['fecha_correccion']
                    ?? $rubric['fecha_correccion']
                    ?? null,
                'tiene_entrega' => true,
            ])),
            'delivery' => [
                'id' => (int) ($delivery['entrega_id'] ?? $delivery['id'] ?? 0),
                'status' => (string) ($revision['estado'] ?? $delivery['estado'] ?? 'Pendiente'),
                'submitted_at' => $delivery['fecha_entrega'] ?? $delivery['submitted_at'] ?? null,
                'feedback' => (string) (
                    $revision['retroalimentacion']
                    ?? $revision['comentario_general']
                    ?? $rubric['observacion_docente']
                    ?? $delivery['retroalimentacion']
                    ?? ''
                ),
                'student_comment' => (string) ($delivery['observacion_alumno'] ?? ''),
            ],
            'rubric' => [
                'name' => (string) ($rubric['nombre'] ?? 'Rúbrica de Evaluación'),
                'criteria' => $criteria,
            ],
            'attachments' => $attachments,
            'totals' => [
                'score' => $totalScore,
                'max_score' => $maxScore,
            ],
        ];
    }

    private function normalizeSubsanation(mixed $item, int $evaluationId): array
    {
        $data = is_array($item) ? $item : (array) $item;

        return [
            'id' => (int) ($data['subsanacion_id'] ?? $data['id'] ?? 0),
            'evaluation_id' => (int) ($data['evaluacion_id'] ?? $evaluationId),
            'student_email' => trim((string) (
                $data['alumno_correo']
                ?? $data['correo_alumno']
                ?? $data['correo_personal']
                ?? $data['correo']
                ?? $data['email']
                ?? ''
            )),
            'user_id' => isset($data['usuario_id']) ? (int) $data['usuario_id'] : null,
            'score' => $this->toNullableFloat(
                $data['puntaje_total']
                ?? $data['nota_final']
                ?? $data['puntaje']
                ?? $data['score']
                ?? null
            ),
            'approved' => isset($data['aprobado']) ? (bool) $data['aprobado'] : null,
            'reason' => trim((string) (
                $data['motivo']
                ?? $data['motivo_subsanacion']
                ?? $data['justificacion']
                ?? ''
            )),
            'observation' => trim((string) (
                $data['observacion']
                ?? $data['observacion_docente']
                ?? $data['comentario']
                ?? ''
            )),
            'evidence' => trim((string) (
                $data['evidencia_archivo']
                ?? $data['evidencia_url']
                ?? $data['archivo']
                ?? $data['enlace_evidencia']
                ?? ''
            )),
            'evidence_name' => trim((string) (
                $data['evidencia_nombre']
                ?? $data['nombre_original']
                ?? $data['nombre_archivo']
                ?? $data['filename']
                ?? ''
            )),
            'created_at' => $data['created_at'] ?? $data['fecha_registro'] ?? $data['fecha_creacion'] ?? null,
            'updated_at' => $data['updated_at'] ?? $data['fecha_actualizacion'] ?? null,
        ];
    }

    private function extractRows(mixed $payload, array $keys): array
    {
        if (is_array($payload)) {
            foreach ($keys as $key) {
                $value = $payload[$key] ?? null;

                if (is_array($value)) {
                    return $value;
                }
            }

            if (array_is_list($payload)) {
                return $payload;
            }
        }

        return [];
    }

    private function normalizeQualificationStatusKey(
        string $status,
        bool $hasDelivery,
        bool $isCorrected
    ): string {
        if ($isCorrected) {
            return 'corrected';
        }

        if (!$hasDelivery) {
            return 'missing';
        }

        $normalized = mb_strtolower(trim($status));

        if ($normalized === '') {
            return 'pending';
        }

        if (str_contains($normalized, 'correg')
            || str_contains($normalized, 'calific')
            || str_contains($normalized, 'revisado')
            || str_contains($normalized, 'aprob')
        ) {
            return 'corrected';
        }

        if (str_contains($normalized, 'revision') || str_contains($normalized, 'revisi')) {
            return 'reviewing';
        }

        if (str_contains($normalized, 'borrador') || str_contains($normalized, 'en_progreso')) {
            return 'draft';
        }

        if (str_contains($normalized, 'pend')) {
            return 'pending';
        }

        if (str_contains($normalized, 'sin entrega') || str_contains($normalized, 'no entreg')) {
            return 'missing';
        }

        if (str_contains($normalized, 'entreg') || str_contains($normalized, 'finaliz')) {
            return 'pending';
        }

        return 'pending';
    }

    private function humanizeQualificationStatus(string $statusKey, string $fallback = ''): string
    {
        return match ($statusKey) {
            'corrected' => 'Calificado',
            'reviewing' => 'En revisión',
            'draft' => 'Borrador',
            'missing' => 'Sin entrega registrada',
            'pending' => 'Pendiente',
            default => trim($fallback) !== '' ? $fallback : 'Pendiente',
        };
    }

    private function makeInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : 'AV';
    }

    private function toNullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function resolveRubricLevel(?float $score, float $maxScore): ?int
    {
        if ($score === null || $maxScore <= 0) {
            return null;
        }

        $ratio = max(0, min(1, $score / $maxScore));
        $level = (int) round(($ratio * 4) + 1);

        return max(1, min(5, $level));
    }

    private function forgetCourseEvaluationCaches(int $courseId): void
    {
        if ($courseId <= 0) {
            return;
        }

        PerformanceCache::forget($this->courseEvaluationsCacheKey($courseId));
        PerformanceCache::forget($this->courseQualificationsCacheKey($courseId));
    }

    private function forgetEvaluationWideCaches(): void
    {
        for ($courseId = 1; $courseId <= 500; $courseId++) {
            $this->forgetCourseEvaluationCaches($courseId);
        }
    }

    private function courseEvaluationsCacheKey(int $courseId): string
    {
        return 'evaluations:course:' . $courseId;
    }

    private function courseQualificationsCacheKey(int $courseId): string
    {
        return 'qualifications:course-dashboard:' . $courseId;
    }

    private function normalizeStudentDelivery(mixed $delivery): array
    {
        $entrega = is_array($delivery) ? $delivery : (array) $delivery;

        $score = $this->toNullableFloat(
            $entrega['nota_final']
            ?? $entrega['puntaje_total']
            ?? $entrega['puntaje_obtenido']
            ?? $entrega['nota']
            ?? $entrega['calificacion']
            ?? $entrega['score']
            ?? null
        );

        $maxScore = $this->toNullableFloat(
            $entrega['puntaje_max']
            ?? $entrega['puntaje_maximo']
            ?? $entrega['max_score']
            ?? null
        );

        $feedback = trim((string) (
            $entrega['observacion_docente']
            ?? $entrega['retroalimentacion']
            ?? $entrega['feedback']
            ?? $entrega['observacion']
            ?? $entrega['comentario']
            ?? ''
        ));

        return [
            'entrega_id' => $entrega['entrega_id'] ?? null,
            'estado' => $entrega['estado'] ?? $entrega['status'] ?? 'borrador',
            'finalizada' => (bool) ($entrega['finalizada'] ?? false),
            'fecha_entrega' => $entrega['fecha_entrega'] ?? $entrega['submitted_at'] ?? null,
            'observacion_alumno' => $entrega['observacion_alumno'] ?? null,
            'observacion_docente' => $feedback,
            'feedback' => $feedback,
            'score' => $score,
            'max_score' => $maxScore,
            'approved' => array_key_exists('aprobado', $entrega) && $entrega['aprobado'] !== null
                ? (bool) $entrega['aprobado']
                : null,
            'archivos' => collect($entrega['archivos'] ?? [])
                ->map(function ($archivo) {
                    $item = is_array($archivo) ? $archivo : (array) $archivo;

                    return [
                        'archivo_id' => $item['archivo_id'] ?? null,
                        'nombre_original' => $item['nombre_original'] ?? 'Archivo',
                        'url_descarga' => $item['url_descarga'] ?? null,
                        'peso_bytes' => $item['peso_bytes'] ?? null,
                        'mime_type' => $item['mime_type'] ?? null,
                    ];
                })
                ->values()
                ->all(),
            'puede_editar' => (bool) ($entrega['puede_editar'] ?? false),
            'max_archivos' => (int) ($entrega['max_archivos'] ?? 5),
            'max_file_size_mb' => (int) ($entrega['max_file_size_mb'] ?? 50),
            'allowed_extensions' => array_values(array_filter(array_map(
                static fn ($extension) => trim(strtolower((string) $extension)),
                (array) ($entrega['allowed_extensions'] ?? ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'txt', 'csv', 'odt', 'ods', 'odp', 'json', 'yml', 'yaml'])
            ))),
            'fuera_de_plazo' => (bool) ($entrega['fuera_de_plazo'] ?? false),
        ];
    }

    private function formatWorkDeadline(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value, 'America/Lima')->setTimezone('America/Lima');
        } catch (\Throwable) {
            return null;
        }

        $months = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        $period = $date->hour < 12 ? 'a.m.' : 'p.m.';

        return sprintf(
            '%d de %s de %d · %s %s',
            $date->day,
            $months[$date->month],
            $date->year,
            $date->format('g:i'),
            $period
        );
    }
}
