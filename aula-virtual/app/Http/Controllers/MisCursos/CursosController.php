<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use App\Services\CursoService;
use App\Services\SesionService;
use App\Domain\Cursos\Scheduling\SessionScheduleResolver;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\MaterialService;
use App\Services\AnnouncementService;
use App\Services\ChatService;
use App\Services\AttendanceService;
use App\Http\Controllers\MisCursos\Traits\BuildCourseContext;

/**
 * Controlador de cursos y sesiones del alumno.
 */
class CursosController extends Controller
{
    use BuildCourseContext;
    private CursoService $cursoService;
    private SesionService $sesionService;
    private MaterialService $materialService;
    private AnnouncementService $announcementService;
    private ChatService $chatService;

    public function __construct(
        CursoService $cursoService,
        SesionService $sesionService,
        MaterialService $materialService,
        AnnouncementService $announcementService,
        ChatService $chatService,
        private AttendanceService $attendanceService
    ) {
        $this->cursoService = $cursoService;
        $this->sesionService = $sesionService;
        $this->materialService = $materialService;
        $this->announcementService = $announcementService;
        $this->chatService = $chatService;
    }

    /**
     * Lista cursos del alumno autenticado.
     */
    public function index(Request $request)
    {

        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        if ($correo === '') {
            return redirect()->route('login');
        }

        $activeTab = (string) $request->query('tab', 'activos');
        if (!in_array($activeTab, ['activos', 'completados', 'sugeridos'], true)) {
            $activeTab = 'activos';
        }

        $search = trim((string) $request->query('search', ''));
        
        $error = null;
        $courses = collect();
        
        $groups = [
            'activos' => collect(),
            'completados' => collect(),
            'sugeridos' => collect(),
        ];
        $counts = [
            'activos' => 0,
            'completados' => 0,
            'sugeridos' => 0,
        ];

        $result = $this->cursoService->listarCursos($correo);

        if (!$result->ok()) {
            Log::error('API Servicios error when listing courses.', [
                'correo' => $correo,
                'status' => $result->status(),
                'error' => $result->error(),
            ]);
            $error = 'No se pudieron cargar tus cursos en este momento.';
        } else {
            $payload = $result->data();
            $courses = $payload['courses'] ?? $courses;
            $groups = $payload['groups'] ?? $groups;
            $counts = $payload['counts'] ?? $counts;
        }

        if ($search !== '') {
            $groups = collect($groups)
                ->map(fn ($items) => collect($items)->filter(fn ($course) => $this->matchesCourseSearch($course, $search))->values())
                ->all();

            $counts['activos'] = $groups['activos']->count();
            $counts['completados'] = $groups['completados']->count();
            $counts['sugeridos'] = $groups['sugeridos']->count();
        }

        return view('mis-cursos.index', [
            'courses' => $courses,
            'groups' => $groups,
            'counts' => $counts,
            'error' => $error,
            'activeTab' => $activeTab,
            'search' => $search,
        ]);
    }

    private function matchesCourseSearch(mixed $course, string $search): bool
    {
        $course = is_array($course) ? $course : (array) $course;
        $needle = mb_strtolower($search, 'UTF-8');

        $haystack = mb_strtolower(implode(' ', [
            $course['title'] ?? '',
            $course['edition'] ?? '',
            $course['teacher'] ?? '',
            $course['schedule_label'] ?? '',
        ]), 'UTF-8');

        return str_contains($haystack, $needle);
    }

    /**
     * Muestra el detalle del curso y la sesion seleccionada.
     */
    public function show(
        Request $request,
        SessionScheduleResolver $sessionScheduleResolver,
        int $cursoId,
        ?int $sessionId = null
    )
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        if ($correo === '') {
            return redirect()->route('login');
        }

    // ===== Curso + sesiones + progreso (reutilizable)
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        if (!$rol) abort(401);
        [$course, $sessions] = $this->construirCurso((int)$cursoId, $rol);
        $studentCertificate = null;
        $studentCertificateError = null;

        if ($rol === 'alumno') {
            $certificateResult = $this->cursoService->obtenerCertificadoAlumnoCurso((int) $cursoId);

            if ($certificateResult->ok()) {
                $studentCertificate = $certificateResult->data()['certificate'] ?? null;
            } else {
                $studentCertificateError = 'No pudimos consultar tu certificado en este momento.';
                Log::warning('No se pudo cargar certificado del alumno', [
                    'course_id' => $cursoId,
                    'status' => $certificateResult->status(),
                ]);
            }
        }

    

    // ===== resolver sesión actual
        [$sessions, $session] = $sessionScheduleResolver->resolve(
            $sessions,
            $sessionId ? (int)$sessionId : null
        );

    // ===== anuncios no leidos

        $anuncioSesionNoLeido = ['existen' => false, 'pendiente' => null];
        $detalleSesionCargado = false;

        if (!empty($session?->id)) {
            $detalleResult = $this->sesionService->obtenerDetalleSesionAlumno(
                (int) $cursoId,
                (int) $session->id,
                $correo
            );

            if ($detalleResult->ok()) {
                $detalle = $detalleResult->data();
                $session = $detalle['session'] ?? $session;
                $anuncioSesionNoLeido = $detalle['anuncioSesionNoLeido'] ?? $anuncioSesionNoLeido;
                $detalleSesionCargado = true;
            } else {
                Log::error('Error cargando detalle de sesion inicial alumno', [
                    'course_id' => $cursoId,
                    'session_id' => $session->id,
                    'correo' => $correo,
                    'status' => $detalleResult->status(),
                    'error' => $detalleResult->error(),
                ]);

                $anuncioSesionNoLeido = $this->obtenerAnunciosSesionNoLeido(
                    (int) $session->id,
                    $correo
                );
            }
        }

        $anuncioCursoNoLeido = $this->obtenerAnuncioNoLeido('curso',(int) $cursoId,$correo);

    // ==========================
// MATERIALES DE LA SESIÓN
// ==========================

        if (!$detalleSesionCargado && !empty($session?->id)) {

            $materialsResult = $this->materialService
                ->listarMaterialesPorSesion($session->id);

            if ($materialsResult->ok()) {

                $session->materials =
                    collect($materialsResult->data()['materials'] ?? []);

            } else {

                Log::error('Error cargando materiales alumno', [
                    'session_id' => $session->id,
                    'error' => $materialsResult->error(),
                ]);

                $session->materials = collect();
            }
        }

        if (!$session) {
            $session = (object)[
                'id' => null,
                'number' => null,
                'title' => null,
                'subtitle' => null,
                'date' => null,
                'duration' => null,
                'video_url' => null,
                'materials' => collect(), //    
            ];
        }

        $requestedTab = (string) $request->query('tab', '');
        $requestedTab = [
            'evaluacion' => 'evaluations',
        ][$requestedTab] ?? $requestedTab;

        if ($requestedTab === 'evaluations' && collect($session->evaluaciones ?? [])->isEmpty()) {
            return redirect()->route('mis-cursos.show', [$cursoId, $session->id]);
        }

    // actualiza sesiones dentro del curso luego del resolver
        $course->sessions = $sessions;
        return view('mis-cursos.show', [
            'course'   => $course,
            'session'  => $session,
            'sessions' => $sessions,
            'anuncioSesionNoLeido' => $anuncioSesionNoLeido,
            'anuncioCursoNoLeido' => $anuncioCursoNoLeido,
            'studentCertificate' => $studentCertificate,
            'studentCertificateError' => $studentCertificateError,
            'bodyView' => 'mis-cursos.partials.session'
        ]);
    }

    public function sessionDetail(Request $request, int $course, int $session)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $result = $this->sesionService->obtenerDetalleSesionAlumno($course, $session, $correo);

        if (!$result->ok()) {
            Log::error('Error obteniendo detalle de sesion alumno', [
                'course_id' => $course,
                'session_id' => $session,
                'correo' => $correo,
                'status' => $result->status(),
                'error' => $result->error(),
            ]);

            return response()->json([
                'message' => 'No se pudo cargar la sesion.',
            ], $result->status() ?: 500);
        }

        $payload = $result->data();
        $selectedSession = $payload['session'];

        $courseData = (object) [
            'id' => $course,
        ];

        $html = view('mis-cursos.partials.session', [
            'course' => $courseData,
            'session' => $selectedSession,
            'anuncioSesionNoLeido' => $payload['anuncioSesionNoLeido'] ?? [
                'existen' => false,
                'pendiente' => null,
            ],
        ])->render();

        return response()->json([
            'html' => $html,
            'session_id' => $selectedSession->id,
        ]);
    }

    public function workspace(Request $request, int $course, int $session)
    {
        $context = $this->studentSessionContext($request, $course, $session);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $html = view('mis-cursos.partials.session', $context)->render();
        $sessions = collect($context['sessions'])->values();
        $position = $sessions->search(fn ($item) => (int) $item->id === $session);

        return response()->json([
            'ok' => true,
            'html' => $html,
            'meta' => [
                'position' => $position === false ? 1 : $position + 1,
                'total' => $sessions->count(),
            ],
        ]);
    }

    public function panel(Request $request, int $course, int $session, string $panel)
    {
        $allowed = ['video', 'materials', 'evaluations', 'surveys', 'announcements', 'attendance'];
        abort_unless(in_array($panel, $allowed, true), 404);

        $context = $this->studentSessionContext($request, $course, $session);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        if ($panel === 'announcements') {
            $email = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
            $result = $this->announcementService->listarAnunciosAlumno('sesion', $session, $email);
            $context['announcements'] = collect($result->ok() ? ($result->data()['anuncios'] ?? []) : []);
        }

        if ($panel === 'attendance') {
            $result = $this->attendanceService->student($course);
            $context['attendanceItem'] = collect($result->ok() ? $result->data() : [])
                ->first(fn ($item) => (int) data_get($item, 'session_id') === $session);
            $context['attendanceError'] = $result->ok() ? null : 'No se pudo cargar tu asistencia.';
        }

        $count = match ($panel) {
            'materials' => collect($context['session']->materials ?? [])->count(),
            'evaluations' => collect($context['session']->evaluaciones ?? [])->count(),
            'surveys' => collect($context['session']->surveys ?? [])->count(),
            'announcements' => collect($context['announcements'] ?? [])->count(),
            default => 0,
        };

        return response()->json([
            'ok' => true,
            'html' => view('mis-cursos.partials.panels.'.$panel, $context)->render(),
            'meta' => ['count' => $count],
        ]);
    }

    public function community(Request $request, int $course)
    {
        $email = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        if ($email === '') {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $role = (string) $request->session()->get(AuthSessionKeys::USER_ROLE, '');
        try {
            [$courseData, $sessions] = $this->construirCurso($course, $role);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'message' => 'No tienes acceso a este curso.'], 403);
        }

        if (collect($sessions)->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No tienes acceso a este curso.'], 403);
        }

        $chat = $this->cargarChatCurso($courseData, $course);
        $html = view('mis-cursos.partials.session-conversation', [
            'course' => $courseData,
            'chat' => $chat,
            'userRole' => 'ALUMNO',
        ])->render();

        return response()->json([
            'ok' => true,
            'html' => $html,
            'meta' => [
                'comments' => (int) data_get($chat, 'total_mensajes', 0),
                'participants' => 0,
            ],
        ]);
    }

    private function studentSessionContext(Request $request, int $course, int $session): array|\Illuminate\Http\JsonResponse
    {
        $email = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        if ($email === '') {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $role = (string) $request->session()->get(AuthSessionKeys::USER_ROLE, '');
        try {
            [$courseData, $sessions] = $this->construirCurso($course, $role);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'message' => 'No tienes acceso a este curso.'], 403);
        }

        if (!collect($sessions)->contains(fn ($item) => (int) $item->id === $session)) {
            return response()->json(['ok' => false, 'message' => 'La sesion no pertenece al curso.'], 404);
        }

        $result = $this->sesionService->obtenerDetalleSesionAlumno($course, $session, $email);
        if (!$result->ok()) {
            return response()->json(['ok' => false, 'message' => 'No se pudo cargar la sesion.'], $result->status() ?: 500);
        }

        return [
            'course' => $courseData,
            'sessions' => collect($sessions),
            'session' => $result->data()['session'],
            'anuncioSesionNoLeido' => $result->data()['anuncioSesionNoLeido'] ?? ['existen' => false, 'pendiente' => null],
        ];
    }

    private function obtenerAnuncios(
    string $entidadTipo,
    int $entidadId
) {
    $anuncios = collect();

    $result = $this->announcementService
        ->listarAnuncios($entidadTipo, $entidadId);

    if ($result->ok()) {
        return collect($result->data()['announcements'] ?? []);
    }

    Log::error('API Servicios error when listing announcements.', [
        'entidad_tipo' => $entidadTipo,
        'entidad_id'   => $entidadId,
        'status'       => $result->status(),
        'error'        => $result->error(),
    ]);

    return $anuncios;
}


public function anuncios(
    SessionScheduleResolver $sessionScheduleResolver,
    int $courseId,
    string $entidadTipo,
    int $entidadId
) {
    $rol = session(AuthSessionKeys::USER_ROLE);
    if (!$rol) {
        abort(401);
    }

    [$course, $sessions] = $this->construirCurso(
        $courseId,
        $rol
    );

    $anuncios = $this->obtenerAnuncios(
        $entidadTipo,
        (int) $entidadId
    );

    // marcar sesión actual igual que en show()
    [$sessions, $session] = $sessionScheduleResolver
        ->resolve($sessions, null);

    $course->sessions = $sessions;

    return view('mis-cursos.show', [
        'course'   => $course,
        'sessions' => $sessions,
        'session'  => $session,
        'anuncios' => $anuncios,
        'bodyView' => 'mis-cursos.partials.anuncios'
    ]);
}

public function courseAnnouncements(
    Request $request,
    int $course,
    ?int $session = null
    ) {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return redirect()->route('login');
        }

        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        if (!$rol) abort(401);

        // 🔹 Construir curso y sesiones (ya tienes este método perfecto)
        [$courseData, $sessions] = $this->construirCurso($course, $rol);

        // 🔹 Resolver sesión seleccionada
        $selectedSession = null;

        if ($session) {
            $selectedSession = $sessions->firstWhere('id', $session);
        }

        // 🔹 Traer anuncios del curso (con lectura)
        $result = $this->announcementService
            ->listarAnunciosAlumno('curso', $course, $correo);

        $announcements = collect();

        if ($result->ok()) {
            $announcements = collect($result->data()['anuncios'] ?? []);
        }
        /*if ($result->ok()) {
            $announcements = collect($result->data()['anuncios'] ?? [])
                ->map(function ($a) {
                    return (object)[
                        'id'         => $a['id'] ?? null,
                        'title'      => $a['titulo'] ?? null,
                        'content'    => $a['contenido'] ?? null,
                        'type'       => $a['tipo'] ?? 'general',
                        'created_at' => $a['created_at'] ?? null,
                        'leido'      => $a['leido'] ?? 0,
                    ];
                });
        }*/

        if ($request->ajax() || $request->wantsJson()) {
            $html = view('mis-cursos.partials.announcements', [
                'course'        => $courseData,
                'session'       => $selectedSession,
                'announcements' => $announcements,
            ])->render();

            return response()->json([
                'html' => $html,
            ]);
        }

        return view('mis-cursos.announcements.index', [
            'course'        => $courseData,
            'sessions'      => $sessions,
            'session'       => $selectedSession,
            'announcements' => $announcements,
        ]);
    }

public function sessionAnnouncements(Request $request, int $courseId, int $sessionId)
{
    $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
    if ($correo === '') {
        return redirect()->route('login');
    }

    $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
    if (!$rol) abort(401);

    [$course, $sessions] = $this->construirCurso($courseId, $rol);

    $result = $this->announcementService
        ->listarAnunciosAlumno('sesion', $sessionId, $correo);

    $announcements = collect();

    if ($result->ok()) {
        // 👇 SIN map, el service ya devuelve objetos
        $announcements = collect($result->data()['anuncios'] ?? []);
    } else {
        Log::error('Error obteniendo anuncios sesión', [
            'session_id' => $sessionId,
            'correo'     => $correo,
            'status'     => $result->status(),
            'error'      => $result->error(),
        ]);
    }

    $session = $sessions->firstWhere('id', $sessionId);

    if ($request->ajax() || $request->wantsJson()) {
        $html = view('mis-cursos.partials.announcements', [
            'course'        => $course,
            'session'       => $session,
            'announcements' => $announcements,
        ])->render();

        return response()->json([
            'html' => $html,
        ]);
    }

    return view('mis-cursos.announcements.index', [
        'course'        => $course,
        'sessions'      => $sessions,
        'session'       => $session,
        'announcements' => $announcements,
    ]);
}

   private function obtenerAnuncioNoLeido(
    string $entidadTipo,
    int $entidadId,
    string $correo
): ?object {

    $result = $this->announcementService
        ->listarAnunciosAlumno(
            $entidadTipo,
            $entidadId,
            $correo
        );

    if (!$result->ok()) {

        Log::error('Error obteniendo anuncio no leído', [
            'entidad_tipo' => $entidadTipo,
            'entidad_id'   => $entidadId,
            'correo'       => $correo,
            'status'       => $result->status(),
            'error'        => $result->error(),
        ]);

        return null;
    }

    $anuncios = collect($result->data()['anuncios'] ?? [])
        ->sortByDesc(fn ($a) => $a->created_at);

    $noLeido = $anuncios->firstWhere('leido', 0);

    return $this->prepararAnuncioUI($noLeido);
}

private function obtenerAnunciosSesionNoLeido(
    int $sessionId,
    string $correo
): array {

    $result = $this->announcementService
        ->listarAnunciosAlumno(
            'sesion',
            $sessionId,
            $correo
        );

    if (!$result->ok()) {
        Log::error('Error obteniendo anuncios sesión', [
            'session_id' => $sessionId,
            'correo'     => $correo,
            'status'     => $result->status(),
            'error'      => $result->error(),
        ]);

        return [
            'existen'   => false,
            'pendiente' => null
        ];
    }

    $anuncios = collect($result->data()['anuncios'] ?? [])
        ->sortByDesc(fn ($a) => $a->created_at);

    if ($anuncios->isEmpty()) {
        return [
            'existen'   => false,
            'pendiente' => null
        ];
    }

    $ultimo = $anuncios->first();

    return [
        'existen'   => true, // 👈 hay anuncios
        'pendiente' => ((int)$ultimo->leido === 0)
            ? $this->prepararAnuncioUI($ultimo)
            : null
    ];
}

private function prepararAnuncioUI(?object $a): ?object
{
    if (!$a) {
        return null;
    }

    // si está leído → no mostrar
    if ((int) ($a->leido ?? 0) === 1) {
        return null;
    }

    $tipo = strtolower($a->tipo ?? 'general');

    $class = match ($tipo) {
        'importante'  => 'announcement-important',
        'informativo' => 'announcement-info',
        default       => 'announcement-general',
    };

    // agregar propiedad dinámica al objeto
    $a->ui = (object) [
        'class' => $class
    ];

    return $a;
}

private function cargarChatCurso(object $course, int $fallbackCourseId): array
{
    $contextId = $this->resolverChatCourseId($course, $fallbackCourseId);
    $result = $this->chatService->obtenerConversacionCurso($contextId);

    if ($result->ok()) {
        return array_merge($result->data(), [
            'context_id' => $contextId,
            'loading' => false,
        ]);
    }

    Log::error('Error cargando chat de curso alumno', [
        'course_id' => $fallbackCourseId,
        'context_id' => $contextId,
        'status' => $result->status(),
        'error' => $result->error(),
    ]);

    return [
        'sala' => $result->error()['sala'] ?? null,
        'sala_id' => $result->error()['sala']['id'] ?? null,
        'context_id' => $contextId,
        'total_mensajes' => 0,
        'mensajes' => collect(),
        'loading' => false,
        'error' => $result->error()['message'] ?? 'No se pudo cargar la conversación del curso.',
    ];
}

private function resolverChatCourseId(object $course, int $fallbackCourseId): int
{
    foreach (['chat_context_id', 'curso_edicion_id', 'course_id', 'curso_id', 'id'] as $field) {
        $value = $course->{$field} ?? null;

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }
    }

    return $fallbackCourseId;
}

}
