<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\CursoService;
use App\Services\EvaluationService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Services\EvaluationSubmissionService;
use Illuminate\Support\Str;

class QualificationsController extends Controller
{
    public function __construct(
    private readonly CursoService $courseService,
    private readonly EvaluationService $evaluationService,
    private readonly EvaluationSubmissionService $evaluationSubmissionService
) {
}

    public function index(Request $request)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $search = trim((string) $request->query('search', ''));

        Log::info('QualificationsController@index', [
            'correo' => $correo,
            'rol' => $rol,
            'search' => $search,
        ]);

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        $error = null;
        $courses = collect();

        $result = $this->courseService->listarCursosParaCalificaciones();

        if (!$result->ok()) {
            Log::error('Error listando cursos para calificaciones', [
                'correo' => $correo,
                'error' => $result->error(),
            ]);

            $error = 'No se pudieron cargar los cursos.';
        } else {
            $payload = $result->data();
            $courses = collect($payload['cursos'] ?? []);

            if ($search !== '') {
                $needle = Str::lower(Str::ascii($search));

                $courses = $courses->filter(function (array $course) use ($needle) {
                    $haystack = Str::lower(Str::ascii(implode(' ', [
                        (string) ($course['title'] ?? ''),
                        (string) ($course['name'] ?? ''),
                        (string) ($course['code'] ?? ''),
                        (string) ($course['codigo'] ?? ''),
                        (string) ($course['id'] ?? ''),
                        (string) ($course['edition'] ?? ''),
                        (string) ($course['edicion'] ?? ''),
                    ])));

                    return str_contains($haystack, $needle);
                })->values();
            }
        }

        $perPage = 6;
        $currentPage = LengthAwarePaginator::resolveCurrentPage('page');
        $courses = new LengthAwarePaginator(
            $courses->forPage($currentPage, $perPage)->values(),
            $courses->count(),
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'pageName' => 'page',
                'query' => $request->except('page'),
            ]
        );

          Log::info('QualificationsController@index payload vista', [
        'total' => $courses->total(),
        'current_page' => $courses->currentPage(),
        'error' => $error,
        'search' => $search,
        'role' => $rol,
    ]);

        return view('backoffice.qualifications.index', [
            'courses' => $courses,
            'error' => $error,
            'search' => $search,
            'role' => $rol,
        ]);
    }

    public function show(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        Log::info('QualificationsController@show', [
            'course_id' => $courseId,
            'correo' => $correo,
            'rol' => $rol,
        ]);

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        $error = null;
        $course = [
            'id' => $courseId,
            'name' => 'Curso',
            'subtitle' => 'Gestion de evaluaciones',
        ];
        $evaluations = collect();
        $result = $this->evaluationService->getCourseQualificationsDashboard($courseId);

        if (!$result->ok()) {
            Log::error('Error listando evaluaciones para calificaciones', [
                'course_id' => $courseId,
                'error' => $result->error(),
            ]);

            $error = 'No se pudieron cargar las evaluaciones del curso.';
        } else {
            $data = $result->data();
            $course = $data['course'] ?? $course;
            $evaluations = collect($data['evaluations'] ?? [])
                ->filter(function (array $evaluation) {
                    $typeId = (int) ($evaluation['type_id'] ?? 0);

                    return in_array($typeId, [3, 4], true) || !empty($evaluation['is_work']);
                })
                ->values();
        }

        return view('backoffice.qualifications.show', [
            'courseId' => $courseId,
            'course' => $course,
            'evaluations' => $evaluations,
            'error' => $error,
        ]);
    }

    public function notes(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $userId = $request->session()->get(AuthSessionKeys::USER_ID);

        Log::info('QualificationsController@notes', [
            'course_id' => $courseId,
            'correo' => $correo,
            'rol' => $rol,
            'user_id' => $userId,
        ]);

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        $course = [
            'id' => $courseId,
            'name' => 'Curso',
            'subtitle' => 'Libro de notas',
        ];
        $error = null;
        $warnings = collect();
        $evaluations = collect();
        $students = collect();
        $summary = [
            'students_total' => 0,
            'evaluations_total' => 0,
            'overall_average' => null,
            'pending_cells' => 0,
            'students_with_pending_count' => 0,
            'approved_students_count' => 0,
            'failed_students_count' => 0,
            'min_average_score' => null,
            'max_average_score' => null,
            'subsanations_total' => 0,
        ];
        $dashboardResult = $this->evaluationService->getCourseQualificationsDashboard($courseId);

        if (!$dashboardResult->ok()) {
            Log::error('Error cargando dashboard de notas del curso', [
                'course_id' => $courseId,
                'correo' => $correo,
                'error' => $dashboardResult->error(),
            ]);

            $error = 'No se pudo cargar el libro de notas del curso.';
        } else {
            $dashboardData = $dashboardResult->data();
            $course = $dashboardData['course'] ?? $course;
            $selectedEvaluations = collect($dashboardData['evaluations'] ?? [])
                ->filter(function (array $evaluation) {
                    $typeId = (int) ($evaluation['type_id'] ?? 0);

                    return in_array($typeId, [1, 2, 3, 4], true)
                        || !empty($evaluation['is_exam'])
                        || !empty($evaluation['is_work']);
                })
                ->values();

            if ($selectedEvaluations->isEmpty()) {
                $error = 'Este curso todavia no tiene evaluaciones visibles para el libro de notas.';
            } else {
                $studentMap = [];
                $evaluationRows = [];
                $totalSubsanations = 0;

                foreach ($selectedEvaluations as $evaluation) {
                    $courseSessionEvaluationId = (int) ($evaluation['id'] ?? 0);
                    $evaluationId = (int) ($evaluation['evaluation_id'] ?? $courseSessionEvaluationId);
                    $participants = collect();
                    $participantsSummary = [];
                    $subsanations = collect();

                    $participantsResult = $this->evaluationService->getEvaluationParticipants($evaluationId);

                    if (!$participantsResult->ok()) {
                        Log::warning('No se pudieron cargar participantes para notas', [
                            'course_id' => $courseId,
                            'evaluation_id' => $courseSessionEvaluationId,
                            'error' => $participantsResult->error(),
                        ]);

                        $warnings->push('No se pudieron cargar algunos participantes de ' . ($evaluation['name'] ?? 'la evaluacion') . '.');
                    } else {
                        $participantsData = $participantsResult->data();
                        $participants = collect($participantsData['participants'] ?? []);
                        $participantsSummary = $participantsData['summary'] ?? [];
                    }

                    $subsanationsResult = $this->evaluationService->listEvaluationSubsanations($evaluationId);

                    if (!$subsanationsResult->ok()) {
                        Log::warning('No se pudieron listar subsanaciones para notas', [
                            'course_id' => $courseId,
                            'evaluation_id' => $evaluationId,
                            'error' => $subsanationsResult->error(),
                        ]);
                    } else {
                        $subsanations = collect($subsanationsResult->data()['items'] ?? []);
                    }

                    $totalSubsanations += $subsanations->count();

                    $subsanationsByEmail = $subsanations
                        ->filter(fn (array $item) => $this->normalizeStudentEmail($item['student_email'] ?? '') !== '')
                        ->groupBy(fn (array $item) => $this->normalizeStudentEmail($item['student_email'] ?? ''))
                        ->map(fn (Collection $items) => $items->sortByDesc(fn (array $item) => (string) ($item['created_at'] ?? ''))->values());

                    foreach ($participants as $participant) {
                        $studentKey = $this->normalizeStudentKey($participant);

                        if ($studentKey === '') {
                            continue;
                        }

                        if (!isset($studentMap[$studentKey])) {
                            $studentMap[$studentKey] = [
                                'key' => $studentKey,
                                'id' => (int) ($participant['id'] ?? 0),
                                'name' => (string) ($participant['name'] ?? 'Estudiante'),
                                'initials' => (string) ($participant['initials'] ?? 'AV'),
                                'email' => (string) ($participant['email'] ?? ''),
                                'phone' => (string) ($participant['phone'] ?? ''),
                                'cells' => [],
                                'histories' => [],
                            ];
                        }

                        $emailKey = $this->normalizeStudentEmail($participant['email'] ?? '');
                        $history = $emailKey !== '' ? collect($subsanationsByEmail->get($emailKey, [])) : collect();
                        $latestSubsanation = $history->first();

                        $studentMap[$studentKey]['cells'][$courseSessionEvaluationId] = $this->buildNotesCell(
                            $evaluation,
                            $participant,
                            $latestSubsanation
                        );
                        $studentMap[$studentKey]['histories'][$courseSessionEvaluationId] = $history
                            ->map(fn (array $item) => $this->formatSubsanationForFrontend($item, $evaluation))
                            ->values()
                            ->all();
                    }

                    $evaluationRows[] = array_merge($evaluation, [
                        'short_type' => $this->evaluationService->isWorkType((int) ($evaluation['type_id'] ?? 0)) ? 'Trabajo' : 'Examen',
                        'students_count' => (int) ($participantsSummary['total'] ?? $participants->count()),
                        'subsanations_count' => $subsanations->count(),
                        'pass_score' => (int) ($evaluation['puntaje_aprobacion'] ?? 11),
                    ]);
                }

                $evaluations = collect($evaluationRows)->values();

                $students = collect($studentMap)
                    ->map(function (array $student) use ($evaluations) {
                        $orderedCells = [];
                        $weightedTotal = 0.0;
                        $weightedScale = 0.0;

                        foreach ($evaluations as $evaluation) {
                            $evaluationId = (int) ($evaluation['id'] ?? 0);
                            $cell = $student['cells'][$evaluationId]
                                ?? $this->buildEmptyNotesCell($evaluation);
                            $orderedCells[$evaluationId] = $cell;
                            $student['histories'][$evaluationId] = $student['histories'][$evaluationId] ?? [];

                            $weight = isset($evaluation['weight_percent']) && is_numeric($evaluation['weight_percent'])
                                ? (float) $evaluation['weight_percent']
                                : 0.0;

                            if ($weight <= 0) {
                                $weight = 1.0;
                            }

                            $weightedScale += $weight;
                            $weightedTotal += (is_numeric($cell['display_score'] ?? null) ? (float) $cell['display_score'] : 0.0) * $weight;
                        }

                        $scores = collect($orderedCells)
                            ->pluck('display_score')
                            ->filter(fn ($value) => is_numeric($value));

                        $average = $weightedScale > 0
                            ? round($weightedTotal / $weightedScale, 2)
                            : ($scores->isNotEmpty() ? round((float) $scores->avg(), 2) : null);

                        return array_merge($student, [
                            'cells' => $orderedCells,
                            'average_score' => $average,
                            'approved' => $average !== null ? $average >= 11 : false,
                        ]);
                    })
                    ->sortBy(fn (array $student) => mb_strtolower($student['name']))
                    ->values();

                $allCells = $students->flatMap(fn (array $student) => array_values($student['cells']));
                $studentAverages = $students
                    ->pluck('average_score')
                    ->filter(fn ($value) => is_numeric($value))
                    ->map(fn ($value) => (float) $value)
                    ->values();

                $summary = [
                    'students_total' => $students->count(),
                    'evaluations_total' => $evaluations->count(),
                    'overall_average' => $studentAverages->isNotEmpty()
                        ? round((float) $studentAverages->avg(), 2)
                        : null,
                    'pending_cells' => $allCells
                        ->filter(fn (array $cell) => in_array($cell['status_key'] ?? '', ['pending', 'reviewing'], true))
                        ->count(),
                    'students_with_pending_count' => $students
                        ->filter(function (array $student) {
                            return collect($student['cells'] ?? [])
                                ->contains(fn (array $cell) => in_array($cell['status_key'] ?? '', ['pending', 'reviewing', 'missing'], true));
                        })
                        ->count(),
                    'approved_students_count' => $students
                        ->filter(fn (array $student) => is_numeric($student['average_score'] ?? null) && (float) $student['average_score'] >= 11)
                        ->count(),
                    'failed_students_count' => $students
                        ->filter(fn (array $student) => is_numeric($student['average_score'] ?? null) && (float) $student['average_score'] < 11)
                        ->count(),
                    'min_average_score' => $studentAverages->isNotEmpty()
                        ? round((float) $studentAverages->min(), 2)
                        : null,
                    'max_average_score' => $studentAverages->isNotEmpty()
                        ? round((float) $studentAverages->max(), 2)
                        : null,
                    'subsanations_total' => $totalSubsanations,
                ];

            }
        }

        return view('backoffice.qualifications.notes', [
            'courseId' => $courseId,
            'course' => $course,
            'evaluations' => $evaluations,
            'students' => $students,
            'summary' => $summary,
            'error' => $error,
            'warnings' => $warnings,
            'backofficeUserId' => $userId,
        ]);
    }

    public function subsanation(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
            $userId = $request->session()->get(AuthSessionKeys::USER_ID);
        $evaluationId = (int) $request->query('evaluation_id', 0);
        $courseSessionEvaluationId = (int) $request->query('course_session_evaluation_id', 0);
        $studentEmail = $this->normalizeStudentEmail((string) $request->query('alumno_correo', ''));

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        $course = [
            'id' => $courseId,
            'name' => 'Curso',
            'subtitle' => 'Subsanacion',
        ];
        $evaluation = null;
        $student = [
            'name' => 'Estudiante',
            'email' => $studentEmail,
            'phone' => '',
        ];
        $cell = null;
        $history = collect();
        $error = null;
        $warnings = collect();

        if ($evaluationId <= 0 || $studentEmail === '') {
            $error = 'No se encontro la evaluacion o el alumno para registrar la subsanacion.';
        }

        $dashboardResult = $error ? null : $this->evaluationService->getCourseQualificationsDashboard($courseId);

        if (!$error && !$dashboardResult->ok()) {
            Log::error('Error cargando pantalla de subsanacion', [
                'course_id' => $courseId,
                'evaluation_id' => $evaluationId,
                'alumno_correo' => $studentEmail,
                'error' => $dashboardResult->error(),
            ]);

            $error = 'No se pudo cargar la informacion de la subsanacion.';
        }

        if (!$error) {
            $dashboardData = $dashboardResult->data();
            $course = $dashboardData['course'] ?? $course;
            $selectedEvaluations = collect($dashboardData['evaluations'] ?? [])
                ->filter(function (array $item) {
                    $typeId = (int) ($item['type_id'] ?? 0);

                    return in_array($typeId, [1, 2, 3, 4], true)
                        || !empty($item['is_exam'])
                        || !empty($item['is_work']);
                })
                ->values();

            $evaluation = $selectedEvaluations->first(function (array $item) use ($evaluationId) {
                return (int) ($item['evaluation_id'] ?? $item['id'] ?? 0) === $evaluationId;
            });

            if (!is_array($evaluation)) {
                $error = 'La evaluacion seleccionada no pertenece a este curso.';
            } else {
                if ($courseSessionEvaluationId <= 0) {
                    $courseSessionEvaluationId = (int) ($evaluation['id'] ?? 0);
                }

                $participants = collect();
                $participantsResult = $this->evaluationService->getEvaluationParticipants($evaluationId);

                if (!$participantsResult->ok()) {
                    Log::warning('No se pudieron cargar participantes para subsanacion', [
                        'course_id' => $courseId,
                        'evaluation_id' => $courseSessionEvaluationId,
                        'error' => $participantsResult->error(),
                    ]);

                    $warnings->push('No se pudieron cargar los participantes de esta evaluacion.');
                } else {
                    $participants = collect($participantsResult->data()['participants'] ?? []);
                }

                $participant = $participants->first(function (array $item) use ($studentEmail) {
                    return $this->normalizeStudentEmail($item['email'] ?? '') === $studentEmail;
                });

                if (!is_array($participant)) {
                    foreach ($selectedEvaluations as $candidateEvaluation) {
                        $candidateId = (int) ($candidateEvaluation['evaluation_id'] ?? $candidateEvaluation['id'] ?? 0);

                        if ($candidateId <= 0 || $candidateId === $evaluationId) {
                            continue;
                        }

                        $candidateResult = $this->evaluationService->getEvaluationParticipants($candidateId);

                        if (!$candidateResult->ok()) {
                            continue;
                        }

                        $participant = collect($candidateResult->data()['participants'] ?? [])
                            ->first(function (array $item) use ($studentEmail) {
                                return $this->normalizeStudentEmail($item['email'] ?? '') === $studentEmail;
                            });

                        if (is_array($participant)) {
                            $participant['score'] = null;
                            $participant['status_key'] = 'missing';
                            $participant['status'] = 'Sin entrega';
                            $participant['has_delivery'] = false;
                            $participant['delivery_id'] = 0;
                            break;
                        }
                    }
                }

                if (!is_array($participant)) {
                    $participant = [
                        'id' => 0,
                        'delivery_id' => 0,
                        'name' => 'Estudiante',
                        'email' => $studentEmail,
                        'phone' => '',
                        'score' => null,
                        'status_key' => 'missing',
                        'status' => 'Sin entrega',
                        'has_delivery' => false,
                    ];
                }

                $student = [
                    'name' => (string) ($participant['name'] ?? 'Estudiante'),
                    'email' => (string) ($participant['email'] ?? $studentEmail),
                    'phone' => (string) ($participant['phone'] ?? ''),
                ];

                $subsanationsResult = $this->evaluationService->listEvaluationSubsanations($evaluationId);
                $subsanations = collect();

                if (!$subsanationsResult->ok()) {
                    Log::warning('No se pudieron listar subsanaciones para pantalla de subsanacion', [
                        'course_id' => $courseId,
                        'evaluation_id' => $evaluationId,
                        'error' => $subsanationsResult->error(),
                    ]);
                } else {
                    $subsanations = collect($subsanationsResult->data()['items'] ?? []);
                }

                $history = $subsanations
                    ->filter(fn (array $item) => $this->normalizeStudentEmail($item['student_email'] ?? '') === $studentEmail)
                    ->sortByDesc(fn (array $item) => (string) ($item['created_at'] ?? ''))
                    ->map(fn (array $item) => $this->formatSubsanationForFrontend($item, $evaluation))
                    ->values();

                $cell = $this->buildNotesCell(
                    $evaluation,
                    $participant,
                    $history->isNotEmpty() ? $history->first() : null
                );

                if (($cell['status_key'] ?? '') !== 'missing' && empty($cell['is_subsanated'])) {
                    $warnings->push('Esta nota ya no esta disponible para subsanacion.');
                }
            }
        }

        return view('backoffice.qualifications.subsanation', [
            'courseId' => $courseId,
            'course' => $course,
            'evaluation' => $evaluation,
            'student' => $student,
            'cell' => $cell,
            'history' => $history,
            'evidenceFile' => $this->buildSubsanationEvidenceFile(
                $courseId,
                $history->first()['evidence'] ?? '',
                $history->first()['evidence_name'] ?? ''
            ),
            'error' => $error,
            'warnings' => $warnings,
            'backofficeUserId' => $userId,
            'backUrl' => route('backoffice.qualifications.notes', $courseId),
            'saveUrl' => route('backoffice.qualifications.notes.subsanation.save', $courseId),
            'updateUrl' => route('backoffice.qualifications.notes.subsanation.update', $courseId),
        ]);
    }

    public function saveSubsanation(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $userId = $request->session()->get(AuthSessionKeys::USER_ID);

        if ($correo === '') {
            if (!$request->expectsJson() && !$request->ajax()) {
                return redirect()->route('login');
            }

            return response()->json([
                'ok' => false,
                'message' => 'La sesion expiro. Vuelve a iniciar sesion.',
            ], 401);
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        if (!$userId) {
            return $this->subsanationSaveErrorResponse(
                $request,
                'No se encontro el usuario_id del backoffice en la sesion.',
                422
            );
        }

        $validated = $request->validate([
            'evaluation_id' => ['required', 'integer', 'min:1'],
            'alumno_correo' => ['required', 'email'],
            'score' => ['required', 'numeric', 'min:0', 'max:20'],
            'motivo' => ['nullable', 'string', 'max:200'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'evidencia_archivo' => ['nullable', 'file', 'max:10240'],
        ]);

        $dashboardResult = $this->evaluationService->getCourseQualificationsDashboard($courseId);

        if (!$dashboardResult->ok()) {
            return $this->subsanationSaveErrorResponse(
                $request,
                'No se pudo validar la evaluacion seleccionada.',
                500
            );
        }

        $evaluation = collect($dashboardResult->data()['evaluations'] ?? [])
            ->first(function (array $item) use ($validated) {
                return (int) ($item['evaluation_id'] ?? $item['id'] ?? 0) === (int) $validated['evaluation_id'];
            });

        if (!is_array($evaluation)) {
            return $this->subsanationSaveErrorResponse(
                $request,
                'La evaluacion seleccionada no pertenece a este curso.',
                404
            );
        }

        $scoreOnTwenty = round((float) $validated['score'], 2);
        $payload = [
            'alumno_correo' => trim((string) $validated['alumno_correo']),
            'usuario_id' => (int) $userId,
            'puntaje_total' => $scoreOnTwenty,
            'aprobado' => $scoreOnTwenty >= (int) ($evaluation['pass_score'] ?? 11),
        ];

        foreach (['motivo', 'observacion'] as $field) {
            $value = trim((string) ($validated[$field] ?? ''));

            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        if ($request->hasFile('evidencia_archivo')) {
            $payload['evidencia_archivo'] = $request->file('evidencia_archivo');
        }

        $result = $this->evaluationService->isWorkType((int) ($evaluation['type_id'] ?? 0))
            ? $this->evaluationService->registerWorkSubsanation((int) $validated['evaluation_id'], $payload)
            : $this->evaluationService->registerExamSubsanation((int) $validated['evaluation_id'], $payload);

        if (!$result->ok()) {
            Log::error('Error registrando subsanacion desde libro de notas', [
                'course_id' => $courseId,
                'evaluation_id' => $validated['evaluation_id'],
                'alumno_correo' => $validated['alumno_correo'],
                'error' => $result->error(),
            ]);

            return $this->subsanationSaveErrorResponse(
                $request,
                'No se pudo registrar la subsanacion.',
                500
            );
        }

        $subsanation = $this->formatSubsanationForFrontend($result->data(), $evaluation);
        $studentKey = $this->normalizeStudentEmail($validated['alumno_correo']);
        $cell = $this->buildNotesCell(
            $evaluation,
            [
                'email' => $validated['alumno_correo'],
                'score' => $payload['puntaje_total'],
                'max_score' => 20,
                'has_delivery' => true,
                'status_key' => 'corrected',
                'status' => 'Corregido',
            ],
            $result->data()
        );

        $responsePayload = [
            'ok' => true,
            'message' => 'Subsanacion registrada correctamente.',
            'lookup_key' => $studentKey . '::' . (int) $validated['evaluation_id'],
            'history_entry' => $subsanation,
            'cell' => $cell,
        ];

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($responsePayload);
        }

        return redirect()
            ->route('backoffice.qualifications.notes', $courseId)
            ->with('qualification_subsanation_success', $responsePayload['message']);
    }

    private function subsanationSaveErrorResponse(Request $request, string $message, int $status = 422)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
            ], $status);
        }

        return redirect()
            ->back()
            ->withInput()
            ->with('qualification_subsanation_error', $message);
    }

    public function updateSubsanation(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $userId = $request->session()->get(AuthSessionKeys::USER_ID);

        if ($correo === '') {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        if (!$userId) {
            return $this->subsanationSaveErrorResponse(
                $request,
                'No se encontro el usuario_id del backoffice en la sesion.',
                422
            );
        }

        $validated = $request->validate([
            'subsanacion_id' => ['required', 'integer', 'min:1'],
            'evaluation_id' => ['required', 'integer', 'min:1'],
            'alumno_correo' => ['required', 'email'],
            'score' => ['required', 'numeric', 'min:0', 'max:20'],
            'motivo' => ['nullable', 'string', 'max:200'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'evidencia_archivo' => ['nullable', 'file', 'max:10240'],
        ]);

        $dashboardResult = $this->evaluationService->getCourseQualificationsDashboard($courseId);

        if (!$dashboardResult->ok()) {
            return $this->subsanationSaveErrorResponse(
                $request,
                'No se pudo validar la evaluacion seleccionada.',
                500
            );
        }

        $evaluation = collect($dashboardResult->data()['evaluations'] ?? [])
            ->first(function (array $item) use ($validated) {
                return (int) ($item['evaluation_id'] ?? $item['id'] ?? 0) === (int) $validated['evaluation_id'];
            });

        if (!is_array($evaluation)) {
            return $this->subsanationSaveErrorResponse(
                $request,
                'La evaluacion seleccionada no pertenece a este curso.',
                404
            );
        }

        $scoreOnTwenty = round((float) $validated['score'], 2);
        $payload = [
            'alumno_correo' => trim((string) $validated['alumno_correo']),
            'usuario_id' => (int) $userId,
            'puntaje_total' => $scoreOnTwenty,
            'aprobado' => $scoreOnTwenty >= (int) ($evaluation['pass_score'] ?? 11),
        ];

        foreach (['motivo', 'observacion'] as $field) {
            $value = trim((string) ($validated[$field] ?? ''));

            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        if ($request->hasFile('evidencia_archivo')) {
            $payload['evidencia_archivo'] = $request->file('evidencia_archivo');
        }

        $result = $this->evaluationService->updateEvaluationSubsanation(
            (int) $validated['evaluation_id'],
            (int) $validated['subsanacion_id'],
            $payload
        );

        if (!$result->ok()) {
            Log::error('Error actualizando subsanacion desde libro de notas', [
                'course_id' => $courseId,
                'evaluation_id' => $validated['evaluation_id'],
                'subsanacion_id' => $validated['subsanacion_id'],
                'alumno_correo' => $validated['alumno_correo'],
                'error' => $result->error(),
            ]);

            return $this->subsanationSaveErrorResponse(
                $request,
                'No se pudo actualizar la subsanacion.',
                500
            );
        }

        return redirect()
            ->route('backoffice.qualifications.notes', $courseId)
            ->with('qualification_subsanation_success', 'Subsanacion actualizada correctamente.');
    }

    public function downloadSubsanationEvidence(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $path = trim((string) $request->query('path', ''));
        $filename = trim((string) $request->query('name', ''));

        if ($correo === '') {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        if (!$this->isValidSubsanationEvidencePath($path)) {
            abort(404);
        }

        $result = $this->evaluationService->downloadSubsanationEvidence($path);

        if (!$result->ok()) {
            abort($result->status() ?? 404);
        }

        $apiResponse = $result->data();
        $contentType = $apiResponse->header('Content-Type') ?? 'application/octet-stream';
        $contentDisposition = $apiResponse->header('Content-Disposition');

        if ($contentDisposition && preg_match('/filename="?([^"]+)"?/', $contentDisposition, $matches)) {
            $filename = $matches[1];
        }

        if ($filename === '') {
            $filename = $this->buildEvidenceDisplayName($path);
        }

        return response()->stream(
            function () use ($apiResponse) {
                $stream = $apiResponse->toPsrResponse()->getBody();

                while (!$stream->eof()) {
                    echo $stream->read(8192);
                }
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => $this->buildDisposition($contentType, $filename),
            ]
        );
    }

    public function evaluate(Request $request, int $courseId, int $evaluationId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $search = trim((string) $request->query('search', ''));
        $rawDeliveryId = $request->query('entregaId', $request->query('entrega', 0));
        $selectedDeliveryId = $this->parsePositiveIntegerQuery($rawDeliveryId);

        Log::info('QualificationsController@evaluate', [
            'course_id' => $courseId,
            'evaluation_id' => $evaluationId,
            'correo' => $correo,
            'rol' => $rol,
            'search' => $search,
            'selected_delivery_id' => $selectedDeliveryId,
        ]);

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        $canonicalEvaluationId = $this->resolveCanonicalEvaluationId($courseId, $evaluationId);
        $deliveryQueryNeedsNormalization = $rawDeliveryId !== null
            && $rawDeliveryId !== ''
            && (string) $selectedDeliveryId !== trim((string) $rawDeliveryId);

        if (($canonicalEvaluationId > 0 && $canonicalEvaluationId !== $evaluationId) || $deliveryQueryNeedsNormalization) {
            $query = array_filter([
                'entregaId' => $selectedDeliveryId > 0 ? $selectedDeliveryId : null,
                'search' => $search !== '' ? $search : null,
            ], fn ($value) => $value !== null && $value !== '');
            $url = route('backoffice.qualifications.evaluate', [
                $courseId,
                $canonicalEvaluationId > 0 ? $canonicalEvaluationId : $evaluationId,
            ]);

            return redirect()->to(empty($query) ? $url : $url . '?' . http_build_query($query));
        }

        $error = null;
        $reviewError = null;
        $evaluation = [];
        $work = [];
        $criteria = collect();
        $participants = collect();
        $selectedParticipant = null;
        $previousParticipant = null;
        $nextParticipant = null;
        $review = null;
        $participantsSummary = [];

        $workResult = $this->evaluationService->getWorkEvaluation($evaluationId);

        if ($workResult->ok()) {
            $workData = $workResult->data();
            $evaluation = $workData['evaluacion'] ?? [];
            $work = $workData['trabajo'] ?? [];
            $criteria = collect($work['rubrica']['criterios'] ?? [])
                ->map(function (array $criterion) {
                    return [
                        'label' => $criterion['nombre'] ?? 'Criterio',
                        'max_score' => (float) ($criterion['puntaje_max'] ?? 0),
                    ];
                })
                ->values();
        } else {
            Log::warning('No se pudo cargar trabajo evaluacion para calificaciones', [
                'evaluation_id' => $evaluationId,
                'error' => $workResult->error(),
            ]);
        }

        if ($criteria->isEmpty()) {
            $result = $this->evaluationService->getEvaluation($evaluationId);

            if (!$result->ok()) {
                Log::error('Error cargando evaluacion para calificaciones', [
                    'evaluation_id' => $evaluationId,
                    'error' => $result->error(),
                ]);

                $error = 'No se pudo cargar el detalle de la evaluacion.';
            } else {
                $data = $result->data();
                $evaluation = $data['evaluacion'] ?? $evaluation;
                $criteria = collect($data['preguntas'] ?? [])
                    ->map(function (array $question) {
                        return [
                            'label' => $question['text'] ?? 'Criterio',
                            'max_score' => (float) ($question['points'] ?? 0),
                        ];
                    })
                    ->values();
            }
        }

        $participantsResult = $this->evaluationService->getEvaluationParticipants($evaluationId);

        if (!$participantsResult->ok()) {
            Log::error('Error cargando participantes para calificaciones', [
                'evaluation_id' => $evaluationId,
                'error' => $participantsResult->error(),
            ]);

            $error = $error ?: 'No se pudieron cargar los participantes de la evaluacion.';
        } else {
            $participantsData = $participantsResult->data();
            $participantsSummary = is_array($participantsData['summary'] ?? null)
                ? $participantsData['summary']
                : [];
            $participants = collect($participantsData['participants'] ?? []);

            if ($participants->isNotEmpty()) {
                $selectedParticipant = $selectedDeliveryId > 0
                    ? $participants->firstWhere('delivery_id', $selectedDeliveryId)
                    : null;

                if ($selectedDeliveryId > 0) {
                    $selectedParticipant = $participants->firstWhere('delivery_id', $selectedDeliveryId);
                } else {
                    $selectedParticipant = null;
                }

                $navigableParticipants = $participants
                    ->filter(fn (array $participant) => (int) ($participant['delivery_id'] ?? 0) > 0)
                    ->values();

                $selectedIndex = $navigableParticipants->search(function (array $participant) use ($selectedParticipant) {
                    return (int) ($participant['delivery_id'] ?? 0) === (int) ($selectedParticipant['delivery_id'] ?? 0)
                        && (int) ($participant['id'] ?? 0) === (int) ($selectedParticipant['id'] ?? 0);
                });

                if ($selectedIndex !== false) {
                    $previousParticipant = $selectedIndex > 0
                        ? $navigableParticipants->get($selectedIndex - 1)
                        : null;
                    $nextParticipant = $selectedIndex < ($navigableParticipants->count() - 1)
                        ? $navigableParticipants->get($selectedIndex + 1)
                        : null;
                }

                if ($selectedParticipant && (int) ($selectedParticipant['delivery_id'] ?? 0) > 0) {
                    $reviewResult = $this->evaluationService->getEvaluationRevision(
                        $evaluationId,
                        (int) $selectedParticipant['delivery_id']
                    );

                    if (!$reviewResult->ok()) {
                        Log::error('Error cargando revision de entrega para calificaciones', [
                            'evaluation_id' => $evaluationId,
                            'delivery_id' => $selectedParticipant['delivery_id'] ?? null,
                            'error' => $reviewResult->error(),
                        ]);

                        $reviewError = 'No se pudo cargar el detalle de la revision.';
                    } else {
                        $review = $reviewResult->data();
                        if (is_array($review['participant'] ?? null)) {
                            $selectedParticipant = array_merge(
                                $selectedParticipant ?? [],
                                $review['participant']
                            );
                        }

                        $reviewCriteria = collect($review['rubric']['criteria'] ?? [])
                            ->map(function (array $criterion) {
                                return [
                                    'label' => $criterion['name'] ?? 'Criterio',
                                    'max_score' => (float) ($criterion['max_score'] ?? 0),
                                ];
                            })
                            ->values();

                        if ($reviewCriteria->isNotEmpty()) {
                            $criteria = $reviewCriteria;
                        }
                    }
                }
            }
        }

        $deliveredCount = $participants->where('has_delivery', true)->count();
        $correctedCount = $participants->where('status_key', 'corrected')->count();
        $pendingCount = $participants
            ->filter(fn (array $participant) => in_array($participant['status_key'], ['draft', 'pending', 'reviewing'], true))
            ->count();

        return view('backoffice.qualifications.evaluate', [
            'courseId' => $courseId,
            'evaluationId' => $evaluationId,
            'evaluation' => $evaluation,
            'work' => $work,
            'criteria' => $criteria,
            'participants' => $participants,
            'selectedParticipant' => $selectedParticipant,
            'previousParticipant' => $previousParticipant,
            'nextParticipant' => $nextParticipant,
            'review' => $review,
            'error' => $error,
            'reviewError' => $reviewError,
            'search' => $search,
            'selectedDeliveryId' => $selectedDeliveryId,
            'summary' => [
                'total' => (int) ($participantsSummary['total'] ?? $participants->count()),
                'delivered_count' => (int) ($participantsSummary['delivered_count'] ?? $deliveredCount),
                'corrected_count' => (int) ($participantsSummary['corrected_count'] ?? $correctedCount),
                'pending_count' => (int) ($participantsSummary['pending_count'] ?? $pendingCount),
            ],
        ]);
    }

    public function saveReview(
        Request $request,
        int $courseId,
        int $evaluationId,
        int $deliveryId
    ) {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $userId = $request->session()->get(AuthSessionKeys::USER_ID);
        $nextDeliveryId = (int) $request->input('next_delivery_id', 0);
        $saveAction = (string) $request->input('save_action', 'stay');

        Log::info('QualificationsController@saveReview', [
            'course_id' => $courseId,
            'evaluation_id' => $evaluationId,
            'delivery_id' => $deliveryId,
            'correo' => $correo,
            'rol' => $rol,
            'save_action' => $saveAction,
        ]);

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

        if (!$userId) {
            return $this->reviewSaveErrorResponse(
                $request,
                $courseId,
                $evaluationId,
                $deliveryId,
                'No se encontro el usuario_id del backoffice en la sesion.',
                422,
                true
            );
        }

        $criteriaPayload = collect($request->input('criteria', []))
            ->map(function ($item, $criterionId) {
                if (!is_array($item)) {
                    return null;
                }

                return [
                    'criterio_id' => (int) $criterionId,
                    'nivel' => (int) ($item['level'] ?? 0),
                    'comentario' => trim((string) ($item['comment'] ?? '')),
                ];
            })
            ->filter(fn ($item) => is_array($item) && $item['criterio_id'] > 0)
            ->values();

        if ($criteriaPayload->isEmpty()) {
            return $this->reviewSaveErrorResponse(
                $request,
                $courseId,
                $evaluationId,
                $deliveryId,
                'Debes calificar al menos un criterio.',
                422,
                false
            );
        }

        foreach ($criteriaPayload as $criterion) {
            if (($criterion['nivel'] ?? 0) < 1 || ($criterion['nivel'] ?? 0) > 5) {
                return $this->reviewSaveErrorResponse(
                    $request,
                    $courseId,
                    $evaluationId,
                    $deliveryId,
                    'Cada criterio debe tener una calificacion del 1 al 5.',
                    422,
                    true
                );
            }
        }

        $payload = [
            'usuario_id' => (int) $userId,
            'observacion_docente' => trim((string) $request->input('observacion_docente', '')),
            'criterios' => $criteriaPayload->all(),
        ];

        $result = $this->evaluationService->saveEvaluationReview(
            $evaluationId,
            $deliveryId,
            $payload
        );

        if (!$result->ok()) {
            Log::error('Error guardando revision de entrega', [
                'evaluation_id' => $evaluationId,
                'delivery_id' => $deliveryId,
                'error' => $result->error(),
            ]);

            return $this->reviewSaveErrorResponse(
                $request,
                $courseId,
                $evaluationId,
                $deliveryId,
                'No se pudo guardar la calificacion.',
                500,
                true
            );
        }

        $redirectDeliveryId = $deliveryId;

        if ($saveAction === 'next' && $nextDeliveryId > 0) {
            $redirectDeliveryId = $nextDeliveryId;
        }

        $loadedNextDelivery = $saveAction === 'next' && $redirectDeliveryId !== $deliveryId;
        $successMessage = $loadedNextDelivery
            ? 'Calificacion guardada y siguiente entrega cargada.'
            : 'Calificacion guardada correctamente.';
        $redirectUrl = $this->buildEvaluateRedirectUrl($courseId, $evaluationId, $redirectDeliveryId);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => $successMessage,
                'redirect_url' => $redirectUrl,
                'saved_delivery_id' => $deliveryId,
                'redirect_delivery_id' => $redirectDeliveryId,
                'save_action' => $saveAction,
            ]);
        }

        return redirect()
            ->to($redirectUrl)
            ->with('qualification_review_success', $successMessage);
    }

    private function buildEvaluateRedirectUrl(
        int $courseId,
        int $evaluationId,
        int $deliveryId
    ): string {
        $query = array_filter([
            'entregaId' => $deliveryId > 0 ? $deliveryId : null,
        ], fn ($value) => $value !== null && $value !== '');

        $url = route('backoffice.qualifications.evaluate', [$courseId, $evaluationId]);

        return empty($query) ? $url : $url . '?' . http_build_query($query);
    }

    private function resolveCanonicalEvaluationId(int $courseId, int $routeEvaluationId): int
    {
        $dashboardResult = $this->evaluationService->getCourseQualificationsDashboard($courseId);

        if (!$dashboardResult->ok()) {
            return $routeEvaluationId;
        }

        $evaluation = collect($dashboardResult->data()['evaluations'] ?? [])
            ->first(function (array $item) use ($routeEvaluationId) {
                return (int) ($item['evaluation_id'] ?? 0) === $routeEvaluationId
                    || (int) ($item['course_session_evaluation_id'] ?? $item['id'] ?? 0) === $routeEvaluationId;
            });

        return is_array($evaluation)
            ? (int) ($evaluation['evaluation_id'] ?? $routeEvaluationId)
            : $routeEvaluationId;
    }

    private function parsePositiveIntegerQuery(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        $raw = trim((string) $value);

        if (!preg_match('/^(\d+),?$/', $raw, $matches)) {
            return 0;
        }

        return max(0, (int) $matches[1]);
    }

    private function reviewSaveErrorResponse(
        Request $request,
        int $courseId,
        int $evaluationId,
        int $deliveryId,
        string $message,
        int $status = 422,
        bool $withInput = false
    ) {
        $redirectUrl = $this->buildEvaluateRedirectUrl($courseId, $evaluationId, $deliveryId);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
                'redirect_url' => $redirectUrl,
                'saved_delivery_id' => $deliveryId,
            ], $status);
        }

        $response = redirect()->to($redirectUrl);

        if ($withInput) {
            $response->withInput();
        }

        return $response->with('qualification_review_error', $message);
    }

    public function downloadAttachment(
        Request $request,
        int $courseId,
        int $evaluationId,
        int $attachmentId
    ) {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if ($correo === '') {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'operador'])) {
            abort(403);
        }

       $result = $this->evaluationSubmissionService
            ->descargarArchivoEntregaTrabajoBackoffice($attachmentId);

        if (!$result->ok()) {
            abort($result->status() ?? 500);
        }

        $apiResponse = $result->data();
        $contentType = $apiResponse->header('Content-Type') ?? 'application/octet-stream';
        $contentDisposition = $apiResponse->header('Content-Disposition');
        $filename = 'archivo';

        if ($contentDisposition && preg_match('/filename="?([^"]+)"?/', $contentDisposition, $matches)) {
            $filename = $matches[1];
        }

        return response()->stream(
            function () use ($apiResponse) {
                $stream = $apiResponse->toPsrResponse()->getBody();

                while (!$stream->eof()) {
                    echo $stream->read(8192);
                }
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => $this->buildDisposition($contentType, $filename),
            ]
        );
    }

    private function buildDisposition(string $contentType, string $filename): string
    {
        $inlineTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'text/plain',
        ];

        foreach ($inlineTypes as $type) {
            if (str_contains($contentType, $type)) {
                return 'inline; filename="' . $filename . '"';
            }
        }

        return 'attachment; filename="' . $filename . '"';
    }

    private function buildSubsanationEvidenceFile(int $courseId, ?string $path, ?string $name = ''): ?array
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return [
                'name' => trim((string) $name) !== '' ? trim((string) $name) : $this->buildEvidenceDisplayName($path),
                'url' => $path,
            ];
        }

        if (!$this->isValidSubsanationEvidencePath($path)) {
            return [
                'name' => trim((string) $name) !== '' ? trim((string) $name) : $this->buildEvidenceDisplayName($path),
                'url' => null,
            ];
        }

        $displayName = trim((string) $name) !== ''
            ? trim((string) $name)
            : $this->buildEvidenceDisplayName($path);

        return [
            'name' => $displayName,
            'url' => route('backoffice.qualifications.notes.subsanation.evidence', [
                'courseId' => $courseId,
                'path' => $path,
                'name' => $displayName,
            ]),
        ];
    }

    private function isValidSubsanationEvidencePath(string $path): bool
    {
        $path = ltrim(trim($path), '/');

        return $path !== ''
            && str_starts_with($path, 'subsanaciones/')
            && !str_contains($path, '..')
            && !str_contains($path, '\\');
    }

    private function buildEvidenceDisplayName(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== ''
            ? 'Evidencia de subsanacion.' . $extension
            : 'Evidencia de subsanacion';
    }

    private function normalizeStudentEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    private function normalizeStudentKey(array $participant): string
    {
        $email = $this->normalizeStudentEmail($participant['email'] ?? '');

        if ($email !== '') {
            return $email;
        }

        $id = (int) ($participant['id'] ?? 0);

        return $id > 0 ? 'participant:' . $id : '';
    }

    private function notesScoreToTwenty(?float $score): ?float
    {
        return $score === null ? null : round($score, 2);
    }

    private function buildNotesCell(array $evaluation, array $participant, ?array $subsanation = null): array
    {
        $isWork = $this->evaluationService->isWorkType((int) ($evaluation['type_id'] ?? 0));
        $sourceScore = $subsanation['score'] ?? $participant['score'] ?? null;

        $displayScore = $this->notesScoreToTwenty(
            is_numeric($sourceScore) ? (float) $sourceScore : null
        );
        $passScore = (int) ($evaluation['pass_score'] ?? 11);

        $statusKey = (string) ($participant['status_key'] ?? 'missing');

        if ($displayScore !== null) {
            $tone = $displayScore >= $passScore ? 'approved' : 'failed';
            $label = $subsanation ? 'Subsanado' : 'Calificado';
            $statusKey = 'corrected';
        } elseif (in_array($statusKey, ['pending', 'reviewing'], true)) {
            $tone = 'pending';
            $label = $isWork ? 'Por corregir' : 'Pendiente';
        } else {
            $tone = 'missing';
            $label = $isWork ? 'No presento' : 'No rindio';
            $statusKey = 'missing';
        }

        return [
            'display_score' => $displayScore,
            'badge' => $displayScore !== null ? number_format($displayScore, 2, '.', '') : null,
            'label' => $label,
            'status_key' => $statusKey,
            'tone' => $tone,
            'is_subsanated' => $subsanation !== null,
            'pass_score' => $passScore,
            'raw_score' => is_numeric($sourceScore) ? round((float) $sourceScore, 2) : null,
            'delivery_id' => (int) ($participant['delivery_id'] ?? 0),
        ];
    }

    private function buildEmptyNotesCell(array $evaluation): array
    {
        $isWork = $this->evaluationService->isWorkType((int) ($evaluation['type_id'] ?? 0));

        return [
            'display_score' => null,
            'badge' => null,
            'label' => $isWork ? 'No presento' : 'No rindio',
            'status_key' => 'missing',
            'tone' => 'missing',
            'is_subsanated' => false,
            'pass_score' => (int) ($evaluation['pass_score'] ?? 11),
            'raw_score' => null,
            'delivery_id' => 0,
        ];
    }

    private function formatSubsanationForFrontend(array $subsanation, array $evaluation): array
    {

        $score = $this->notesScoreToTwenty(
            isset($subsanation['score']) && is_numeric($subsanation['score']) ? (float) $subsanation['score'] : null
        );

        return [
            'id' => (int) ($subsanation['id'] ?? 0),
            'student_email' => (string) ($subsanation['student_email'] ?? ''),
            'score' => $score,
            'approved' => isset($subsanation['approved']) ? (bool) $subsanation['approved'] : ($score !== null ? $score >= (int) ($evaluation['pass_score'] ?? 11): null),
            'reason' => (string) ($subsanation['reason'] ?? ''),
            'observation' => (string) ($subsanation['observation'] ?? ''),
            'evidence' => (string) ($subsanation['evidence'] ?? ''),
            'evidence_name' => (string) ($subsanation['evidence_name'] ?? ''),
            'created_at' => $subsanation['created_at'] ?? null,
            'updated_at' => $subsanation['updated_at'] ?? null,
            'user_id' => isset($subsanation['user_id']) ? (int) $subsanation['user_id'] : null,
        ];
    }
}
