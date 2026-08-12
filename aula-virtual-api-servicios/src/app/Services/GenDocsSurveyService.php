<?php

namespace App\Services;

use App\Repositories\GenDocsSurveyRepository;
use App\Support\ApiCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenDocsSurveyService
{
    public function __construct(private readonly GenDocsSurveyRepository $repository)
    {
    }

    public function attachSummaries(array $sessions, string $email): array
    {
        try {
            $map = $this->repository->summariesForSessions($sessions, $email);
        } catch (\Throwable $exception) {
            Log::warning('survey_summary_unavailable', [
                'exception' => get_class($exception),
                'session_count' => count($sessions),
            ]);
            $map = [];
        }
        foreach ($sessions as $session) {
            $session->surveys = $map[(int) $session->id] ?? [];
            $first = $session->surveys[0] ?? null;
            $session->encuesta_id = $first['link_id'] ?? null;
            $session->encuesta_respondida = (bool) ($first['answered'] ?? false);
        }
        return $sessions;
    }

    public function form(int $courseId, int $sessionId, int $linkId, string $email): array
    {
        try {
            if (!$this->repository->studentEnrolled($courseId, $email)) {
                return $this->failure('No tienes acceso a este curso.', 403);
            }
            $context = $this->repository->findContext($courseId, $sessionId, $linkId, $email);
        } catch (\Throwable $exception) {
            Log::error('student_survey_form_unavailable', [
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'link_id' => $linkId,
                'exception' => get_class($exception),
            ]);
            return $this->failure('El módulo de encuestas no está disponible en este momento.', 503);
        }
        if (!$context) {
            return $this->failure('Encuesta no encontrada.', 404);
        }

        $summary = $context['summary'];
        if ($summary['status'] === 'closed') {
            return $this->failure('La encuesta está cerrada.', 409, ['survey' => $summary]);
        }

        return ['ok' => true, 'survey' => $summary + [
            'course_id' => $courseId,
            'session_id' => $sessionId,
            'session_number' => (int) $context['row']->nro_sesion,
            'course_name' => (string) $context['row']->curso,
            'edition' => (string) $context['row']->edicion,
            'questions' => $context['questions'],
            'teachers' => $context['teachers'],
        ]];
    }

    public function submit(int $courseId, int $sessionId, int $linkId, string $email, array $payload): array
    {
        $form = $this->form($courseId, $sessionId, $linkId, $email);
        if (!($form['ok'] ?? false)) {
            return $form;
        }
        $survey = $form['survey'];
        if (!$survey['available']) {
            return $this->failure('La encuesta todavía no está disponible.', 409, ['survey' => $survey]);
        }
        if ($survey['answered']) {
            return $this->failure('Esta encuesta ya fue respondida.', 409);
        }

        $validation = $this->validateAnswers($survey, $payload);
        if (!($validation['ok'] ?? false)) {
            return $validation;
        }

        try {
            $ids = $this->repository->connection()->transaction(function () use ($survey, $email, $validation) {
                return $this->insertResponses($survey, mb_strtolower(trim($email), 'UTF-8'), $validation);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000'], true)) {
                return $this->failure('Esta encuesta ya fue respondida.', 409);
            }
            throw $exception;
        }

        ApiCache::bumpSurveyResults();

        return ['ok' => true, 'response_ids' => $ids];
    }

    public function results(int $courseId, string $role, string $email = '', array $filters = []): array
    {
        $role = strtolower(trim($role));
        $isAdmin = in_array($role, ['admin', 'administrador'], true);
        $filters = $this->normalizeResultFilters($filters, $isAdmin);
        $cacheKey = ApiCache::surveyResultsKey($courseId, $role, $email, $filters);

        try {
            return Cache::remember($cacheKey, 60, function () use ($courseId, $role, $email, $filters, $isAdmin) {
                return $this->buildResults($courseId, $role, $email, $filters, $isAdmin);
            });
        } catch (\Throwable $exception) {
            Log::error('survey_results_unavailable', [
                'course_id' => $courseId,
                'exception' => get_class($exception),
            ]);
            return $this->failure('No se pudieron cargar los resultados de encuestas.', 503);
        }
    }

    private function buildResults(int $courseId, string $role, string $email, array $filters, bool $isAdmin): array
    {
        $allRows = collect($this->repository->resultsForCourse($courseId, $isAdmin));
        $eligibleStudents = $this->repository->enrolledStudentsCount($courseId);
        $currentTeacherId = $isAdmin ? null : $this->repository->collaboratorIdByEmail($email);
        $catalogs = $this->resultCatalogs($allRows, $isAdmin, $currentTeacherId);

        $segmentRows = $allRows->filter(function (array $row) use ($filters): bool {
            if ($filters['kind'] !== 'all' && ($row['kind'] ?? 'session') !== $filters['kind']) {
                return false;
            }
            if ($filters['session'] > 0 && (int) ($row['nro_sesion'] ?? 0) !== $filters['session']) {
                return false;
            }
            if ($filters['teacher'] > 0 && (int) ($row['docente_id'] ?? 0) !== $filters['teacher']) {
                return false;
            }
            return $filters['form'] <= 0 || (int) ($row['formulario_id'] ?? 0) === $filters['form'];
        })->values();

        $courseAverage = null;
        if (!$isAdmin) {
            $courseSubmissionCount = $segmentRows->map(fn (array $row) => $this->submissionKey($row))->unique()->count();
            if ($courseSubmissionCount >= 5) {
                [, $courseObservations] = $this->questionAggregates($segmentRows);
                $courseScale = $courseObservations->where('type', 'scale')->filter(fn ($item) => is_numeric($item['value']));
                $courseAverage = $courseScale->isNotEmpty() ? round($courseScale->avg('value'), 2) : null;
            }
        }

        $rows = !$isAdmin
            ? $segmentRows->filter(fn (array $row) => $currentTeacherId
                && (int) ($row['docente_id'] ?? 0) === $currentTeacherId)->values()
            : $segmentRows;

        $submissionCount = $rows->map(fn (array $row) => $this->submissionKey($row))->unique()->count();
        $emptyParticipant = hash('sha256', '');
        $participants = $rows->pluck('participant_key')->filter(fn ($key) => $key && $key !== $emptyParticipant)->unique()->count();
        $protected = !$isAdmin && $submissionCount < 5;
        [$questions, $observations, $comments] = $this->questionAggregates($rows);

        if ($protected) {
            $questions = collect();
            $observations = collect();
            $comments = collect();
        }

        $scaleObservations = $observations->where('type', 'scale')->filter(fn ($item) => is_numeric($item['value']));
        $sessionsEvaluated = $rows->pluck('nro_sesion')->filter()->unique()->count();
        $rosterMismatch = $eligibleStudents > 0 && $participants > $eligibleStudents;
        $participation = $eligibleStudents > 0 && !$rosterMismatch
            ? round(($participants / $eligibleStudents) * 100, 1)
            : null;
        $page = $filters['page'];
        $perPage = $filters['per_page'];
        $responseRows = $isAdmin ? $rows->forPage($page, $perPage)->map(function (array $row): array {
            unset($row['participant_key'], $row['email']);
            return $row;
        })->values() : collect();

        $sessionComparisons = $scaleObservations->groupBy('session')->map(function ($items, $session): array {
            return [
                'session' => (int) $session,
                'responses' => $items->pluck('submission')->unique()->count(),
                'average' => round($items->avg('value'), 2),
            ];
        })->sortBy('session')->values();

        $teacherComparisons = $scaleObservations->where('scope', 'teacher')->groupBy('teacher_id')->map(function ($items, $teacherId): array {
            return [
                'teacher_id' => (int) $teacherId,
                'teacher' => (string) ($items->first()['teacher'] ?? 'Docente sin identificar'),
                'responses' => $items->pluck('submission')->unique()->count(),
                'average' => round($items->avg('value'), 2),
            ];
        })->filter(fn ($item) => $isAdmin || ($currentTeacherId && $item['teacher_id'] === $currentTeacherId))->values();

        $payload = [
            'ok' => true,
            'course_id' => $courseId,
            'filters' => $filters,
            'catalogs' => $catalogs,
            'summary' => [
                'submissions' => $submissionCount,
                'response_rows' => $rows->count(),
                'participants' => $participants,
                'eligible_students' => $eligibleStudents,
                'participation_percent' => $participation,
                'roster_mismatch' => $rosterMismatch,
                'sessions' => $sessionsEvaluated,
                'average' => $scaleObservations->isNotEmpty() ? round($scaleObservations->avg('value'), 2) : null,
                'comments' => $comments->count(),
                'sample_small' => $submissionCount > 0 && $submissionCount < 5,
                'privacy_protected' => $protected,
            ],
            'questions' => $questions->values()->all(),
            'comparisons' => [
                'sessions' => $sessionComparisons->all(),
                'teachers' => $teacherComparisons->all(),
                'course_average' => $isAdmin
                    ? ($scaleObservations->isNotEmpty() ? round($scaleObservations->avg('value'), 2) : null)
                    : $courseAverage,
            ],
            'comments' => $comments->values()->all(),
            'responses' => [
                'data' => $responseRows->all(),
                'total' => $isAdmin ? $rows->count() : 0,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $isAdmin ? max(1, (int) ceil($rows->count() / $perPage)) : 1,
            ],
        ];

        // Compatibility for consumers of the original endpoint.
        $payload['total'] = $submissionCount;
        $payload['response_rows'] = $rows->count();
        $payload['data'] = $responseRows->all();

        return $payload;
    }

    private function normalizeResultFilters(array $filters, bool $isAdmin): array
    {
        $candidateKind = (string) ($filters['kind'] ?? 'all');
        $kind = in_array($candidateKind, ['all', 'session', 'final'], true) ? $candidateKind : 'all';

        return [
            'kind' => $kind,
            'session' => max(0, (int) ($filters['session'] ?? 0)),
            'teacher' => $isAdmin ? max(0, (int) ($filters['teacher'] ?? 0)) : 0,
            'form' => max(0, (int) ($filters['form'] ?? 0)),
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'per_page' => min(5000, max(1, (int) ($filters['per_page'] ?? 25))),
        ];
    }

    private function resultCatalogs($rows, bool $isAdmin, ?int $currentTeacherId): array
    {
        return [
            'sessions' => $rows->pluck('nro_sesion')->filter()->unique()->sort()->values()->all(),
            'teachers' => $rows->map(fn (array $row) => [
                'id' => (int) ($row['docente_id'] ?? 0),
                'name' => (string) ($row['docente'] ?? 'Docente sin identificar'),
            ])->filter(fn ($teacher) => $teacher['id'] > 0 && ($isAdmin || $teacher['id'] === $currentTeacherId))->unique('id')->values()->all(),
            'forms' => $rows->map(fn (array $row) => [
                'id' => (int) ($row['formulario_id'] ?? 0),
                'name' => (string) ($row['formulario'] ?? 'Formulario'),
                'version' => (int) ($row['formulario_version'] ?? 1),
                'kind' => (string) ($row['kind'] ?? 'session'),
            ])->unique('id')->values()->all(),
        ];
    }

    private function questionAggregates($rows): array
    {
        $seen = [];
        $groups = [];
        $observations = collect();
        $comments = collect();

        foreach ($rows as $row) {
            $hasTextAnswer = false;
            foreach ((array) ($row['answers'] ?? []) as $answer) {
                $code = (string) ($answer['code'] ?? '');
                $value = $answer['value'] ?? null;
                if ($code === '' || $value === null || $value === '') {
                    continue;
                }
                $scope = (string) ($answer['scope'] ?? 'course');
                $formId = (int) ($row['formulario_id'] ?? 0);
                $questionId = (int) ($answer['question_id'] ?? 0);
                $submission = $this->submissionKey($row);
                $dedupe = $scope === 'course' && !empty($row['submission_uuid'])
                    ? $submission.':'.$questionId
                    : 'response:'.($row['respuesta_id'] ?? 0).':'.$questionId;
                if (isset($seen[$dedupe])) {
                    continue;
                }
                $seen[$dedupe] = true;
                $groupKey = $formId.':'.$questionId;
                $groups[$groupKey] ??= [
                    'key' => $groupKey,
                    'form_id' => $formId,
                    'form_name' => (string) ($row['formulario'] ?? 'Formulario'),
                    'version' => (int) ($row['formulario_version'] ?? 1),
                    'kind' => (string) ($row['kind'] ?? 'session'),
                    'code' => $code,
                    'label' => (string) ($answer['label'] ?? $code),
                    'type' => (string) ($answer['type'] ?? 'textarea'),
                    'values' => [],
                ];
                $groups[$groupKey]['values'][] = $value;
                $observation = [
                    'question' => $groupKey,
                    'type' => (string) ($answer['type'] ?? 'textarea'),
                    'scope' => $scope,
                    'value' => $value,
                    'submission' => $submission,
                    'session' => (int) ($row['nro_sesion'] ?? 0),
                    'teacher_id' => (int) ($row['docente_id'] ?? 0),
                    'teacher' => (string) ($row['docente'] ?? 'Docente sin identificar'),
                ];
                $observations->push($observation);

                if ($observation['type'] === 'textarea') {
                    $hasTextAnswer = true;
                    if ($this->isUsefulComment((string) $value)) {
                        $comments->push([
                            'text' => trim((string) $value),
                            'session' => $observation['session'],
                            'kind' => (string) ($row['kind'] ?? 'session'),
                            'form' => (string) ($row['formulario'] ?? 'Formulario'),
                            'teacher' => $observation['teacher'],
                            'submitted_at' => $row['submitted_at'] ?? null,
                            'respondent' => $row['respondent'] ?? null,
                        ]);
                    }
                }
            }

            if (!$hasTextAnswer && $this->isUsefulComment((string) ($row['Observacion'] ?? ''))) {
                $comments->push([
                    'text' => trim((string) $row['Observacion']),
                    'session' => (int) ($row['nro_sesion'] ?? 0),
                    'kind' => (string) ($row['kind'] ?? 'session'),
                    'form' => (string) ($row['formulario'] ?? 'Formulario'),
                    'teacher' => (string) ($row['docente'] ?? 'Docente sin identificar'),
                    'submitted_at' => $row['submitted_at'] ?? null,
                    'respondent' => $row['respondent'] ?? null,
                ]);
            }
        }

        $questions = collect($groups)->map(function (array $group): array {
            $values = collect($group['values']);
            $numeric = $values->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (float) $value);
            $group['responses'] = $values->count();
            $group['average'] = $group['type'] === 'scale' && $numeric->isNotEmpty() ? round($numeric->avg(), 2) : null;
            $group['distribution'] = in_array($group['type'], ['scale', 'select'], true)
                ? $values->map(fn ($value) => (string) $value)->countBy()->sortKeys()->all()
                : [];
            $group['comments_count'] = $group['type'] === 'textarea'
                ? $values->filter(fn ($value) => $this->isUsefulComment((string) $value))->count()
                : 0;
            unset($group['values']);
            return $group;
        });

        return [$questions, $observations, $comments];
    }

    private function submissionKey(array $row): string
    {
        return !empty($row['submission_uuid'])
            ? 'submission:'.$row['submission_uuid']
            : 'response:'.($row['respuesta_id'] ?? '');
    }

    private function isUsefulComment(string $value): bool
    {
        return !in_array(mb_strtolower(trim($value), 'UTF-8'), [
            '', '-', '--', 'n/a', 'na', 'no aplica', 'ninguno', 'ninguna', 'si', 'sí', 'no',
        ], true);
    }

    private function validateAnswers(array $survey, array $payload): array
    {
        $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
        $teacherAnswers = is_array($payload['teacher_answers'] ?? null) ? $payload['teacher_answers'] : [];
        $teachers = collect($survey['teachers'])->keyBy('id');
        if ($teachers->isEmpty()) {
            return $this->failure('No hay docentes configurados para esta encuesta.', 422);
        }

        $teacherId = (int) ($payload['teacher_id'] ?? ($teachers->count() === 1 ? $teachers->keys()->first() : 0));
        if ($survey['kind'] === 'session' && !$teachers->has($teacherId)) {
            return $this->failure('Selecciona un docente válido.', 422, ['errors' => ['teacher_id' => 'Selecciona un docente.']]);
        }

        $errors = [];
        $normalized = [];
        foreach ($survey['questions'] as $question) {
            $code = $question['code'];
            if ($question['contextual']) {
                continue;
            }
            if ($survey['kind'] === 'final' && $question['scope'] === 'teacher') {
                foreach ($teachers->keys() as $id) {
                    $value = $teacherAnswers[$id][$code] ?? null;
                    $checked = $this->validateValue($question, $value);
                    if (!$checked['ok']) {
                        $errors["teacher_answers.$id.$code"] = $checked['error'];
                    } else {
                        $normalized['teachers'][$id][$code] = $checked['value'];
                    }
                }
                continue;
            }

            $checked = $this->validateValue($question, $answers[$code] ?? null);
            if (!$checked['ok']) {
                $errors["answers.$code"] = $checked['error'];
            } else {
                $normalized['answers'][$code] = $checked['value'];
            }
        }
        if ($errors !== []) {
            return $this->failure('Revisa las respuestas indicadas.', 422, ['errors' => $errors]);
        }

        return ['ok' => true, 'answers' => $normalized['answers'] ?? [], 'teacher_answers' => $normalized['teachers'] ?? [], 'teacher_id' => $teacherId];
    }

    private function validateValue(array $question, mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (($value === null || $value === '') && $question['required']) {
            return ['ok' => false, 'error' => 'Esta pregunta es obligatoria.'];
        }
        if ($value === null || $value === '') {
            return ['ok' => true, 'value' => null];
        }
        if ($question['type'] === 'scale') {
            if (!is_numeric($value) || $value < $question['scale']['min'] || $value > $question['scale']['max']) {
                return ['ok' => false, 'error' => 'Selecciona un valor válido.'];
            }
            $value = (int) $value;
        } elseif ($question['type'] === 'select' && !in_array((string) $value, $question['options'], true)) {
            return ['ok' => false, 'error' => 'Selecciona una opción válida.'];
        } elseif ($question['type'] === 'number' && !is_numeric($value)) {
            return ['ok' => false, 'error' => 'Ingresa un número válido.'];
        } elseif (is_string($value) && mb_strlen($value) > 5000) {
            return ['ok' => false, 'error' => 'La respuesta es demasiado extensa.'];
        }
        return ['ok' => true, 'value' => $value];
    }

    private function insertResponses(array $survey, string $email, array $validated): array
    {
        $timestamp = CarbonImmutable::now('America/Lima');
        $teachers = $survey['kind'] === 'final'
            ? array_column($survey['teachers'], 'id')
            : [$validated['teacher_id']];
        $submission = $survey['kind'] === 'final' ? (string) Str::uuid() : null;
        $ids = [];

        foreach ($teachers as $teacherId) {
            $answers = $validated['answers'];
            if ($survey['kind'] === 'final') {
                $answers = array_merge($answers, $validated['teacher_answers'][$teacherId] ?? []);
            }
            $numericScores = [];
            foreach ($survey['questions'] as $question) {
                if ($question['type'] === 'scale' && isset($answers[$question['code']]) && is_numeric($answers[$question['code']])) {
                    $numericScores[] = (float) $answers[$question['code']];
                }
            }
            $score = fn (string $code, string $fallback = '') => (int) ($answers[$code] ?? ($fallback !== '' ? ($answers[$fallback] ?? 0) : 0));
            $id = $this->repository->connection()->table('encuesta_respuestas')->insertGetId([
                'curso_edicion_id' => $survey['course_id'],
                'curso_edicion_sesion_id' => $survey['session_id'],
                'formulario_id' => $survey['form_id'],
                'docente_id' => $teacherId,
                'nro_sesion' => $survey['session_number'],
                'email' => $email,
                'submission_uuid' => $submission,
                'score_puntualidad' => $score('puntualidad'),
                'score_dudas' => $score('dudas'),
                'score_laboratorios' => $score('laboratorios'),
                'score_satisfaccion' => $score('satisfaccion', 'satisfaccion_curso'),
                'score_promedio' => $numericScores === [] ? 0 : round(array_sum($numericScores) / count($numericScores), 2),
                'comentario' => $answers['comentario'] ?? $answers['feedback_mejora'] ?? null,
                'submitted_at' => $timestamp,
                'created_at' => $timestamp,
            ]);
            $ids[] = (int) $id;

            $contextValues = ['email' => $email, 'docente' => $teacherId, 'nro_sesion' => $survey['session_number'], 'tipo_encuesta' => 'Final del curso'];
            foreach ($survey['questions'] as $question) {
                $code = $question['code'];
                $value = $answers[$code] ?? $contextValues[$code] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $this->repository->connection()->table('encuesta_respuesta_detalles')->insert([
                    'respuesta_id' => $id,
                    'pregunta_id' => $question['id'],
                    'valor_texto' => (string) $value,
                    'valor_numero' => is_numeric($value) ? $value : null,
                    'created_at' => $timestamp,
                ]);
            }
        }
        return $ids;
    }

    private function failure(string $message, int $status, array $extra = []): array
    {
        return ['ok' => false, 'message' => $message, 'status' => $status] + $extra;
    }
}
