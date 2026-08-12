<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\CursoService;
use App\Services\SurveyService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class SurveysController extends Controller
{
    public function __construct(
        private readonly CursoService $courseService,
        private readonly SurveyService $surveyService
    ) {
    }

    public function index(Request $request)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $search = trim((string) $request->query('search', ''));

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'administrador', 'operador'], true)) {
            abort(403);
        }

        $error = null;
        $courses = collect();

        $result = $this->courseService->listarCursosParaEncuestas();

        if (!$result->ok()) {
            Log::error('Error listando cursos para encuestas', [
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
        $surveyMetrics = [
            'courses' => $courses->count(),
            'responses' => $courses->sum(fn (array $course) => (int) ($course['survey_response_count'] ?? 0)),
            'with_responses' => $courses->filter(fn (array $course) => (int) ($course['survey_response_count'] ?? 0) > 0)->count(),
        ];
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

        return view('backoffice.surveys.index', [
            'courses' => $courses,
            'error' => $error,
            'search' => $search,
            'role' => $rol,
            'surveyMetrics' => $surveyMetrics,
        ]);
    }

    public function results(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return redirect()->route('login');
        }

        if (!in_array($rol, ['admin', 'administrador', 'operador'], true)) {
            abort(403);
        }

        $isAdmin = in_array($rol, ['admin', 'administrador'], true);
        $filters = $this->resultFilters($request, $isAdmin);
        $error = null;
        $resultados = new LengthAwarePaginator([], 0, 25, 1, ['path' => $request->url()]);
        $surveySummary = $this->emptySurveySummary();
        $catalogs = ['sessions' => [], 'teachers' => [], 'forms' => []];
        $questionResults = collect();
        $comparisons = ['sessions' => [], 'teachers' => [], 'course_average' => null];
        $comments = collect();
        $course = $this->resolveCourseSummary($courseId);
        $courseTitle = $course['title'] ?? null;

        $result = $this->surveyService->obtenerDetalleResultadosCurso($courseId, $filters);

        if (!$result->ok()) {
            Log::error('Error obteniendo resultados de encuestas', [
                'curso_edicion_id' => $courseId,
                'rol' => $rol,
                'status' => $result->status(),
            ]);

            $error = 'No se pudieron cargar los resultados.';
        } else {
            $payload = $result->data();
            $responseRows = collect($payload['resultados'] ?? []);
            $firstRow = $responseRows->first();
            $firstRow = is_array($firstRow) ? $firstRow : (array) $firstRow;
            $courseTitle = $firstRow['curso'] ?? $courseTitle;
            $course['title'] = $courseTitle ?? $course['title'];
            $surveySummary = array_merge($surveySummary, (array) ($payload['summary'] ?? []));
            $catalogs = array_merge($catalogs, (array) ($payload['catalogs'] ?? []));
            $questionResults = collect($payload['questions'] ?? []);
            $comparisons = array_merge($comparisons, (array) ($payload['comparisons'] ?? []));
            $comments = collect($payload['comments'] ?? []);
            $responseMeta = (array) ($payload['responses'] ?? []);
            $resultados = new LengthAwarePaginator(
                $responseRows,
                (int) ($responseMeta['total'] ?? $responseRows->count()),
                (int) ($responseMeta['per_page'] ?? 25),
                (int) ($responseMeta['page'] ?? 1),
                [
                    'path' => $request->url(),
                    'query' => $request->except('page'),
                ]
            );
        }

        return view('backoffice.surveys.results', [
            'cursoEdicionId' => $courseId,
            'course' => $course,
            'courseTitle' => $courseTitle,
            'resultados' => $resultados,
            'error' => $error,
            'filters' => $filters,
            'catalogs' => $catalogs,
            'surveySummary' => $surveySummary,
            'questionResults' => $questionResults,
            'comparisons' => $comparisons,
            'comments' => $comments,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function export(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = (string) $request->session()->get(AuthSessionKeys::USER_ROLE);
        if ($correo === '') {
            return redirect()->route('login');
        }
        if (!in_array($rol, ['admin', 'administrador', 'operador'], true)) {
            abort(403);
        }

        $isAdmin = in_array($rol, ['admin', 'administrador'], true);
        $scope = $isAdmin && $request->query('scope') === 'detail' ? 'detail' : 'summary';
        $filters = $this->resultFilters($request, $isAdmin);
        $filters['page'] = 1;
        $filters['per_page'] = 5000;
        $result = $this->surveyService->obtenerDetalleResultadosCurso($courseId, $filters);
        abort_unless($result->ok(), 503, 'No se pudo generar la exportación.');
        $payload = $result->data();
        $filename = 'encuestas-curso-'.$courseId.'-'.$scope.'.csv';

        return response()->streamDownload(function () use ($payload, $scope): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            if ($scope === 'detail') {
                $this->writeDetailedExport($stream, collect($payload['resultados'] ?? []));
            } else {
                $this->writeSummaryExport($stream, $payload);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function resultFilters(Request $request, bool $isAdmin): array
    {
        $kind = (string) $request->query('kind', 'all');

        return [
            'kind' => in_array($kind, ['all', 'session', 'final'], true) ? $kind : 'all',
            'session' => max(0, $request->integer('session')),
            'teacher' => $isAdmin ? max(0, $request->integer('teacher')) : 0,
            'form' => max(0, $request->integer('form')),
            'page' => max(1, $request->integer('page', 1)),
            'per_page' => 25,
            'view' => in_array($request->query('view'), ['summary', 'questions', 'comments', 'responses'], true)
                ? (string) $request->query('view')
                : 'summary',
        ];
    }

    private function emptySurveySummary(): array
    {
        return [
            'submissions' => 0, 'participants' => 0, 'eligible_students' => 0,
            'participation_percent' => null, 'sessions' => 0, 'average' => null,
            'comments' => 0, 'sample_small' => false, 'privacy_protected' => false,
            'roster_mismatch' => false,
        ];
    }

    private function writeSummaryExport($stream, array $payload): void
    {
        $summary = array_merge($this->emptySurveySummary(), (array) ($payload['summary'] ?? []));
        fputcsv($stream, ['Métrica', 'Valor'], ';');
        fputcsv($stream, ['Participantes', $summary['participants'].' de '.$summary['eligible_students']], ';');
        fputcsv($stream, ['Participación', $summary['participation_percent'] !== null ? $summary['participation_percent'].'%' : '-'], ';');
        fputcsv($stream, ['Encuestas recibidas', $summary['submissions']], ';');
        fputcsv($stream, ['Promedio general', $summary['average'] ?? '-'], ';');
        fputcsv($stream, ['Comentarios útiles', $summary['comments']], ';');
        fputcsv($stream, [], ';');
        fputcsv($stream, ['Formulario', 'Versión', 'Tipo', 'Pregunta', 'Respuestas', 'Promedio', 'Distribución'], ';');
        foreach ((array) ($payload['questions'] ?? []) as $question) {
            $distribution = collect($question['distribution'] ?? [])->map(fn ($count, $value) => $value.': '.$count)->implode(' | ');
            fputcsv($stream, [
                $question['form_name'] ?? '', $question['version'] ?? '', $question['kind'] ?? '',
                $question['label'] ?? '', $question['responses'] ?? 0, $question['average'] ?? '', $distribution,
            ], ';');
        }
    }

    private function writeDetailedExport($stream, Collection $rows): void
    {
        $questions = $rows->flatMap(fn ($row) => collect($row['answers'] ?? [])->mapWithKeys(
            fn ($answer) => [($answer['code'] ?? '') => ($answer['label'] ?? $answer['code'] ?? '')]
        ))->filter()->unique();
        fputcsv($stream, array_merge(['Sesión', 'Formulario', 'Versión', 'Tipo', 'Docente', 'Participante', 'Fecha'], $questions->values()->all()), ';');
        foreach ($rows as $row) {
            $answers = collect($row['answers'] ?? [])->mapWithKeys(fn ($answer) => [($answer['code'] ?? '') => $answer['value'] ?? '']);
            fputcsv($stream, array_merge([
                $row['nro_sesion'] ?? '', $row['formulario'] ?? '', $row['formulario_version'] ?? '',
                $row['kind'] ?? '', $row['docente'] ?? '', $row['respondent'] ?? '', $row['submitted_at'] ?? '',
            ], $questions->keys()->map(fn ($code) => $answers->get($code, ''))->all()), ';');
        }
    }

    private function resolveCourseSummary(int $courseId): array
    {
        $fallback = [
            'id' => $courseId,
            'code' => '',
            'title' => 'Curso sin identificar',
            'teacher' => null,
            'schedule' => null,
            'total_sessions' => 0,
            'sessions_done' => 0,
            'progress_label' => '0 de 0',
            'progress_percent' => 0,
        ];

        $result = $this->courseService->listarCursosParaEncuestas();

        if (!$result->ok()) {
            Log::warning('No se pudo resolver curso para resultados de encuestas', [
                'curso_edicion_id' => $courseId,
                'error' => $result->error(),
            ]);

            return $fallback;
        }

        $courses = collect($result->data()['cursos'] ?? []);
        $course = $courses->first(fn (array $item) => (int) ($item['id'] ?? 0) === $courseId);

        return is_array($course) ? array_merge($fallback, $course) : $fallback;
    }

    private function construirResumenResultados(Collection $resultados): Collection
    {
        $metrics = ['Puntualidad', 'Entendimiento', 'Laboratorios', 'Satisfaccion'];
        $groups = [];

        foreach ($resultados as $resultado) {
            $row = is_array($resultado) ? $resultado : (array) $resultado;
            $session = $row['nro_sesion'] ?? '-';
            $key = (string) $session;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'sesion' => $session,
                    'respuestas' => 0,
                    'sums' => array_fill_keys($metrics, 0.0),
                    'counts' => array_fill_keys($metrics, 0),
                ];
            }

            $groups[$key]['respuestas']++;

            foreach ($metrics as $metric) {
                if (isset($row[$metric]) && is_numeric($row[$metric])) {
                    $groups[$key]['sums'][$metric] += (float) $row[$metric];
                    $groups[$key]['counts'][$metric]++;
                }
            }
        }

        return collect($groups)->values()->map(function (array $group) use ($metrics) {
            $summary = [
                'sesion' => $group['sesion'],
                'respuestas' => $group['respuestas'],
            ];

            $metricValues = [];

            foreach ($metrics as $metric) {
                $value = $group['counts'][$metric] > 0
                    ? round($group['sums'][$metric] / $group['counts'][$metric], 2)
                    : null;

                $summary[$metric] = $value;

                if ($value !== null) {
                    $metricValues[] = $value;
                }
            }

            $summary['TOTAL'] = !empty($metricValues)
                ? round(array_sum($metricValues) / count($metricValues), 2)
                : null;

            return $summary;
        });
    }

    private function construirResultadosPorPregunta(Collection $resultados): Collection
    {
        $seen = [];
        $groups = [];

        foreach ($resultados as $resultado) {
            $row = is_array($resultado) ? $resultado : (array) $resultado;
            foreach ((array) ($row['answers'] ?? []) as $answer) {
                $answer = is_array($answer) ? $answer : (array) $answer;
                $code = (string) ($answer['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $scope = (string) ($answer['scope'] ?? 'course');
                $submission = (string) ($row['submission_uuid'] ?? '');
                $dedupeKey = $scope === 'course' && $submission !== ''
                    ? $submission.':'.$code
                    : (string) ($row['respuesta_id'] ?? '').':'.$code;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $groups[$code] ??= [
                    'code' => $code,
                    'label' => (string) ($answer['label'] ?? $code),
                    'type' => (string) ($answer['type'] ?? 'textarea'),
                    'values' => [],
                ];
                $value = $answer['value'] ?? null;
                if ($value !== null && $value !== '') {
                    $groups[$code]['values'][] = $value;
                }
            }
        }

        return collect($groups)->map(function (array $group) {
            $values = collect($group['values']);
            $numeric = $values->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (float) $value);
            $group['responses'] = $values->count();
            $group['average'] = $group['type'] === 'scale' && $numeric->isNotEmpty()
                ? round($numeric->avg(), 2)
                : null;
            $group['distribution'] = in_array($group['type'], ['scale', 'select'], true)
                ? $values->map(fn ($value) => (string) $value)->countBy()->sortKeys()->all()
                : [];
            $group['comments'] = $group['type'] === 'textarea' ? $values->values()->all() : [];
            unset($group['values']);

            return $group;
        })->values();
    }
}
