<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\AttendanceService;
use App\Services\CursoService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'administrador', 'operador', 'docente', 'profesor'];

    public function __construct(
        private AttendanceService $attendance,
        private CursoService $courses
    ) {}

    public function index(Request $request)
    {
        [$role, $email, $isAdmin] = $this->authContext($request);
        [$courses, $coursesError] = $this->accessibleCourses($role, $email, $isAdmin);

        $summaryResult = $this->attendance->courseSummaries();
        $summaryError = !$summaryResult->ok();
        $summaryMap = $summaryResult->ok()
            ? collect($summaryResult->data())->keyBy('course_id')
            : collect();

        $courses = $courses->map(function (array $course) use ($summaryMap, $summaryError) {
            $summary = $summaryMap->get((int) ($course['id'] ?? 0));
            return $course + ($summary
                ? ($summary + ['summary_available' => true])
                : $this->emptySummary((int) ($course['id'] ?? 0), !$summaryError));
        });

        $metrics = [
            'courses' => $courses->count(),
            'reconciled' => $courses->sum('sessions_reconciled'),
            'pending' => $courses->sum('sessions_pending'),
            'unresolved' => $courses->sum('unresolved_count'),
        ];

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'all'));
        if (!in_array($status, ['all', 'attention', 'up_to_date', 'no_records'], true)) {
            $status = 'all';
        }

        if ($search !== '') {
            $needle = Str::lower(Str::ascii($search));
            $courses = $courses->filter(function (array $course) use ($needle) {
                $haystack = Str::lower(Str::ascii(implode(' ', [
                    $course['title'] ?? '', $course['edition'] ?? '',
                    $course['teacher'] ?? '', $course['id'] ?? '',
                ])));
                return str_contains($haystack, $needle);
            });
        }
        if ($status !== 'all') {
            $courses = $courses->where('attendance_status', $status);
        }

        $courses = $courses->values();
        $page = LengthAwarePaginator::resolveCurrentPage('page');
        $paginator = new LengthAwarePaginator(
            $courses->forPage($page, 6)->values(),
            $courses->count(),
            6,
            $page,
            ['path' => $request->url(), 'pageName' => 'page', 'query' => $request->except('page')]
        );

        return view('backoffice.attendance.index', [
            'courses' => $paginator,
            'metrics' => $metrics,
            'search' => $search,
            'status' => $status,
            'isAdmin' => $isAdmin,
            'error' => $coursesError,
            'summaryError' => $summaryError,
        ]);
    }

    public function show(Request $request, int $course)
    {
        [$role, $email, $isAdmin] = $this->authContext($request);
        [$courses, $coursesError] = $this->accessibleCourses($role, $email, $isAdmin);
        $courseData = $courses->first(fn (array $item) => (int) ($item['id'] ?? 0) === $course);
        abort_unless($courseData, 403);

        $summaryResult = $this->attendance->courseSessionSummaries($course);
        $error = $coursesError;
        $summary = $this->emptySummary($course, true);
        $sessions = collect();
        if ($summaryResult->ok()) {
            $payload = $summaryResult->data();
            $summary = array_merge($summary, $payload['summary'] ?? []);
            $sessions = collect($payload['sessions'] ?? [])->values();
        } else {
            $error = 'No se pudo cargar el resumen de asistencia del curso.';
        }

        $sessionStatus = trim((string) $request->query('session_status', 'all'));
        if (!in_array($sessionStatus, ['all', 'pending', 'reconciled', 'upcoming'], true)) {
            $sessionStatus = 'all';
        }
        $visibleSessions = $sessions->filter(function (object $session) use ($sessionStatus) {
            return match ($sessionStatus) {
                'pending' => in_array($session->status, ['pending', 'no_records'], true),
                'reconciled' => $session->status === 'reconciled',
                'upcoming' => $session->status === 'upcoming',
                default => true,
            };
        })->values();

        $selectedSessionId = (int) $request->query('session', 0);
        $selectedSession = $selectedSessionId > 0 ? $sessions->firstWhere('id', $selectedSessionId) : null;
        if ($selectedSessionId > 0 && !$selectedSession) {
            abort(404);
        }

        $attendanceData = null;
        $sessionError = null;
        if ($selectedSession) {
            $attendanceResult = $this->attendance->session($course, $selectedSession->id);
            if ($attendanceResult->ok()) {
                $attendanceData = $attendanceResult->data();
            } else {
                $sessionError = 'No se pudo cargar el detalle de esta sesión.';
            }
        }

        return view('backoffice.attendance.show', [
            'course' => (object) $courseData,
            'summary' => $summary,
            'sessions' => $sessions,
            'visibleSessions' => $visibleSessions,
            'sessionStatus' => $sessionStatus,
            'selectedSession' => $selectedSession,
            'attendanceData' => $attendanceData,
            'sessionError' => $sessionError,
            'error' => $error,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function update(Request $request, int $session, int $attendance)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:asistio,presente,tardanza,falta,justificada,no_aplica'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $result = $this->attendance->override($session, $attendance, $validated['status'], $validated['reason']);
        return $this->mutationResponse($request, $result, 'Asistencia actualizada.', 'No se pudo actualizar la asistencia.');
    }

    public function sync(Request $request, int $session)
    {
        $role = strtolower((string) $request->session()->get(AuthSessionKeys::USER_ROLE, ''));
        abort_unless(in_array($role, ['admin', 'administrador'], true), 403);
        $result = $this->attendance->sync($session);
        return $this->mutationResponse($request, $result, 'Asistencia conciliada con Zoom.', 'Zoom aun no tiene disponible el reporte.');
    }

    public function identify(Request $request, int $session)
    {
        $validated = $request->validate([
            'event_id' => ['required', 'integer', 'min:1'],
            'attendance_id' => ['required', 'integer', 'min:1'],
        ]);
        $result = $this->attendance->identify($session, (int) $validated['event_id'], (int) $validated['attendance_id']);
        return $this->mutationResponse(
            $request,
            $result,
            'Participante identificado. La asociacion se reutilizara en proximas sesiones.',
            'No se pudo asociar al participante. Verifica que corresponda a la misma sesion.'
        );
    }

    public function export(Request $request, ?int $course = null)
    {
        [$role, $email, $isAdmin] = $this->authContext($request);
        [$courses] = $this->accessibleCourses($role, $email, $isAdmin);
        $courseId = $course ?: (int) $request->query('course', 0);
        abort_unless($courseId > 0 && $courses->contains(fn (array $item) => (int) ($item['id'] ?? 0) === $courseId), 403);

        $result = $this->attendance->course($courseId);
        abort_unless($result->ok(), 502);
        $items = collect($result->data()['items'] ?? []);
        $sessionId = (int) $request->query('session_id', 0);
        $sessionNumber = (int) $request->query('session', 0);
        if ($sessionId > 0) $items = $items->where('session_id', $sessionId);
        elseif ($sessionNumber > 0) $items = $items->where('session_number', $sessionNumber);

        return response()->streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Sesion', 'Tipo', 'Participante', 'Correo', 'Estado', 'Primer ingreso', 'Minutos', 'Permanencia docente', 'Correccion']);
            foreach ($items as $item) {
                fputcsv($out, [
                    $item->session_number, $item->participant_type, $item->name, $item->email,
                    $item->status, $item->first_join_at, $item->minutes,
                    $item->participant_type === 'docente' ? $item->percentage.'%' : '',
                    $item->manual_status ? 'Manual: '.$item->reason : 'Automatica',
                ]);
            }
            fclose($out);
        }, 'asistencia-curso-'.$courseId.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authContext(Request $request): array
    {
        $email = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        $role = strtolower((string) $request->session()->get(AuthSessionKeys::USER_ROLE, ''));
        abort_unless(in_array($role, self::ALLOWED_ROLES, true), 403);
        return [$role, $email, in_array($role, ['admin', 'administrador'], true)];
    }

    private function accessibleCourses(string $role, string $email, bool $isAdmin): array
    {
        $result = $this->courses->listarCursos($isAdmin ? '' : $email);
        return [
            $result->ok() ? collect($result->data()['courses'] ?? [])->values() : collect(),
            $result->ok() ? null : 'No se pudieron cargar los cursos.',
        ];
    }

    private function emptySummary(int $courseId, bool $available): array
    {
        return [
            'course_id' => $courseId,
            'sessions_total' => 0,
            'sessions_finished' => 0,
            'sessions_reconciled' => 0,
            'sessions_pending' => 0,
            'unresolved_count' => 0,
            'last_sync_at' => null,
            'attendance_status' => $available ? 'no_records' : 'unavailable',
            'summary_available' => $available,
        ];
    }

    private function mutationResponse(Request $request, $result, string $success, string $failure)
    {
        $message = $result->ok() ? $success : $failure;
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $result->ok(),
                'message' => $message,
                'correlation_id' => data_get($result->error(), 'correlation_id'),
            ], $result->ok() ? 200 : ($result->status() ?: 502));
        }
        return back()->with($result->ok() ? 'success' : 'error', $message);
    }
}
