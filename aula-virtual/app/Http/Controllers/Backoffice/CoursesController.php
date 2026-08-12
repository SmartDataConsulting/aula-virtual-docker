<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\CursoService;
use App\Services\SesionService;
use App\Services\MaterialService;
use App\Services\AnnouncementService;
use App\Services\ChatService;
use App\Services\AttendanceService;
use App\Support\AuthSessionKeys;
use App\Support\SessionPresentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Domain\Cursos\Scheduling\SessionScheduleResolver;
use Illuminate\Pagination\LengthAwarePaginator;
    
class CoursesController extends Controller
{
    private CursoService $courseService;
    private SesionService $sesionService;
    private MaterialService $materialService;
    private AnnouncementService $announcementService;
    private ChatService $chatService;
    private AttendanceService $attendanceService;

   public function __construct(
    CursoService $courseService,
    SesionService $sesionService,
    MaterialService $materialService,
    AnnouncementService $announcementService,
    ChatService $chatService,
    AttendanceService $attendanceService
    ) {
        $this->courseService = $courseService;
        $this->sesionService = $sesionService;
        $this->materialService = $materialService;
        $this->announcementService = $announcementService;
        $this->chatService = $chatService;
        $this->attendanceService = $attendanceService;
    }

    /**
     * Lista cursos del profesor
     */


public function index(Request $request)
{
    $start = microtime(true);
    $search = trim((string) $request->query('search', ''));
    $activeTab = in_array($request->query('tab'), ['activos', 'programados', 'finalizados'], true)
        ? $request->query('tab')
        : 'activos';

    $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
    $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

    Log::debug('CoursesController@index START', [
        'correo' => $correo,
        'rol' => $rol,
        'search' => $search,
        'tab' => $activeTab,
        'timestamp' => now()->toDateTimeString(),
    ]);

    if (!$correo) {
        return redirect()->route('login');
    }

    if ($rol === 'admin') {
        $correo = '';
    }

    $error = null;

    $perPage = 6;
    $baseQuery = $request->except(['activos_page', 'programados_page', 'finalizados_page']);
    $pageNames = [
        'activos' => 'activos_page',
        'programados' => 'programados_page',
        'finalizados' => 'finalizados_page',
    ];
    $makePaginator = function ($items, string $group) use ($request, $baseQuery, $pageNames, $perPage) {
        $items = collect($items)->values();
        $pageName = $pageNames[$group];
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => $pageName,
                'query' => array_merge($baseQuery, ['tab' => $group]),
            ]
        );
    };

    $groups = [
        'activos' => $makePaginator([], 'activos'),
        'programados' => $makePaginator([], 'programados'),
        'finalizados' => $makePaginator([], 'finalizados'),
    ];

    $counts = [
        'activos' => 0,
        'programados' => 0,
        'finalizados' => 0,
    ];

    $result = $this->courseService->listarCursos($correo);

    if (!$result->ok()) {

        Log::error('Error listando cursos backoffice', [
            'correo' => $correo,
            'error' => $result->error(),
        ]);

        $error = 'No se pudieron cargar los cursos.';

    } else {

        $payload = $result->data();

        $activos = collect($payload['groups']['activos'] ?? []);
        $programados = collect($payload['groups']['programados'] ?? []);
        $finalizados = collect($payload['groups']['finalizados'] ?? $payload['groups']['completados'] ?? []);

        if ($rol === 'admin' && mb_strlen($search) >= 4) {
            $needle = mb_strtolower($search);

            $matchCourse = static function (array $course) use ($needle): bool {
                $title = mb_strtolower((string) ($course['title'] ?? ''));
                $edition = mb_strtolower((string) ($course['edition'] ?? ''));
                $teacher = mb_strtolower((string) ($course['teacher'] ?? ''));

                return str_contains($title, $needle)
                    || str_contains($edition, $needle)
                    || str_contains($teacher, $needle);
            };

            $activos = $activos->filter($matchCourse)->values();
            $programados = $programados->filter($matchCourse)->values();
            $finalizados = $finalizados->filter($matchCourse)->values();
        }

        $counts['activos'] = $activos->count();
        $counts['programados'] = $programados->count();
        $counts['finalizados'] = $finalizados->count();

        $groups['activos'] = $makePaginator($activos, 'activos');
        $groups['programados'] = $makePaginator($programados, 'programados');
        $groups['finalizados'] = $makePaginator($finalizados, 'finalizados');
    }

    $end = microtime(true);
    $durationMs = round(($end - $start) * 1000, 2);
    Log::debug('CoursesController@index END', [
        'correo' => $correo,
        'rol' => $rol,
        'search' => $search,
        'tab' => $activeTab,
        'duration_ms' => $durationMs,
        'timestamp' => now()->toDateTimeString(),
    ]);

    return view('backoffice.courses.index', [
        'groups' => $groups,
        'counts' => $counts,
        'error'  => $error,
        'search' => $search,
        'activeTab' => $activeTab,
    ]);
}

    /**
 * Detalle del curso (profesor)
 */
    public function show(Request $request, SessionScheduleResolver $sessionScheduleResolver,int $cursoId, ?int $sessionId = null)
    {
        $materials = collect();

        // ==========================
        // AUTENTICACIÓN
        // ==========================

        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        if ($correo === '') {
            return redirect()->route('login');
        }

        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        if (!$rol) abort(401);

        $course = (object)['id' => $cursoId];

        $error = null;
        $sessions = collect();

        // ==========================
        // SESIONES DESDE API
        // ==========================

        $result = $this->sesionService->listarSesionesCurso($cursoId, $rol);

        if (!$result->ok()) {

            Log::error('Error listando sesiones backoffice', [
                'curso_id' => $cursoId,
                'error' => $result->error(),
            ]);

            $error = 'No se pudieron cargar las sesiones.';

        } else {

            $sessions = collect($result->data()['sessions'] ?? []);
        }

        // ==========================
        // CURSO + MÉTRICAS
        // ==========================

        $course = $this->buildCourseWithMetrics($cursoId, $sessions);

        // ==========================
        // SESIÓN ACTUAL
        // ==========================


        [$sessions, $session] = $sessionScheduleResolver->resolve(
            $sessions,
            $sessionId ? (int)$sessionId : null
        );

        $deferSecondaryPanels = true;

        // ==========================
        // ASEGURAR EVALUACIONES
        // ==========================

        if (!empty($session?->id)) {
            $session->evaluaciones = $session->evaluaciones ?? [];
            $session->evaluaciones_asignadas = $session->evaluaciones_asignadas ?? [];
            $session->evaluaciones_disponibles = $session->evaluaciones_disponibles ?? [];
            $session->materials = collect($session->materials ?? []);
            $session->announcements = collect($session->announcements ?? []);
        }

        // ==========================
        // MATERIALES DE LA SESIÓN
        // ==========================

        if (!$deferSecondaryPanels && !empty($session?->id)) {

            $materialsResult = $this->materialService->listarMaterialesPorSesion($session->id);

            if ($materialsResult->ok()) {
                $session->materials = collect($materialsResult->data()['materials'] ?? []);
            } else {
                Log::error('Error cargando materiales', [
                    'session_id' => $session->id,
                    'error' => $materialsResult->error(),
                ]);
            }
        }

        // ==========================
        // ANUNCIOS DE LA SESIÓN
        // ==========================

        if (!$deferSecondaryPanels && !empty($session?->id)) {

            $announcementsResult =
                $this->announcementService->listarAnuncios('sesion', $session->id);

            if ($announcementsResult->ok()) {

                // 👇 IMPORTANTE: acceder correctamente a la clave
                $session->announcements =
                    $announcementsResult->data()['announcements'] ?? collect();

            } else {

                Log::error('Error cargando anuncios', [
                    'session_id' => $session->id,
                    'error' => $announcementsResult->error(),
                ]);
            }
        }

        // ==========================
        // EVALUACIONES
        // ==========================
        if (!$deferSecondaryPanels && !empty($session?->id)) {

            $evaluacionesResult =
                $this->sesionService->obtenerEvaluacionesSesion(
                    $cursoId,
                    $session->id
                );

            if ($evaluacionesResult->ok()) {

                $session->evaluaciones_asignadas =
                    $evaluacionesResult->data()['asignadas'] ?? [];

                $session->evaluaciones_disponibles =
                    $evaluacionesResult->data()['disponibles'] ?? [];

            } else {

                Log::error('Error cargando evaluaciones', [
                    'session_id' => $session->id,
                    'error' => $evaluacionesResult->error(),
                ]);
            }
        }
        // ==========================
        // CHAT DEL CURSO
        // ==========================

        $chat = [
            'context_id' => $this->resolverChatCourseId($course, (int) $cursoId),
            'total_mensajes' => 0,
            'mensajes' => collect(),
            'loading' => true,
        ];

        // ==========================
        // VIEW
        // ==========================
    
        return view('backoffice.courses.show', [
            'course'   => $course,
            'sessions' => $sessions,
            'session'  => $session,
            'error'    => $error,
            'chat'     => $chat,
        ]);
    }

    public function workspace(
        Request $request,
        SessionScheduleResolver $resolver,
        int $course,
        int $session
    ) {
        $context = $this->loadWorkspaceContext($request, $resolver, $course, $session);

        if (empty($context['session']?->id) || (int) $context['session']->id !== $session) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontró la sesión solicitada.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'html' => view('backoffice.courses.partials.session', $context)->render(),
            'meta' => $this->workspaceMeta($context['session'], $context['sessions']),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }

    public function panel(
        Request $request,
        SessionScheduleResolver $resolver,
        int $course,
        int $session,
        string $panel
    ) {
        abort_unless(in_array($panel, ['materials', 'evaluations', 'announcements', 'attendance'], true), 404);

        $context = $this->loadWorkspaceContext($request, $resolver, $course, $session);
        $selectedSession = $context['session'];

        if (empty($selectedSession?->id) || (int) $selectedSession->id !== $session) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada.'], 404);
        }

        $result = match ($panel) {
            'materials' => $this->materialService->listarMaterialesPorSesion($selectedSession->id),
            'evaluations' => $this->sesionService->obtenerEvaluacionesSesion($course, $selectedSession->id),
            'announcements' => $this->announcementService->listarAnuncios('sesion', $selectedSession->id),
            'attendance' => $this->attendanceService->session($course, $selectedSession->id),
        };

        if (!$result->ok()) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo cargar esta sección.',
                'correlation_id' => data_get($result->error(), 'correlation_id'),
            ], $result->status() ?: 502);
        }

        $data = $result->data();

        if ($panel === 'attendance') {
            $role = strtolower((string) $request->session()->get(AuthSessionKeys::USER_ROLE, ''));
            $standalone = $request->boolean('standalone');
            $meta = array_merge(
                ['count' => 0, 'present' => 0, 'absent' => 0, 'pending' => 0, 'unresolved' => 0],
                $data['summary'] ?? [],
                ['unresolved' => collect($data['unresolved'] ?? [])->count()]
            );

            return response()->json([
                'ok' => true,
                'html' => view('backoffice.courses.partials.session-attendance', [
                    'course' => $context['course'],
                    'session' => $selectedSession,
                    'attendance' => $data,
                    'isAdmin' => in_array($role, ['admin', 'administrador'], true),
                    'refreshUrl' => route('backoffice.courses.sessions.panels.show', [$course, $selectedSession->id, 'attendance']).($standalone ? '?standalone=1' : ''),
                    'showFullAttendanceLink' => !$standalone,
                    'attendanceExportUrl' => route('backoffice.attendance.course.export', [$course, 'session_id' => $selectedSession->id]),
                    'workspaceUrl' => $standalone
                        ? route('backoffice.courses.show', [$course, $selectedSession->id]).'?tab=attendance'
                        : null,
                ])->render(),
                'meta' => $meta,
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]);
        }

        $view = match ($panel) {
            'materials' => 'backoffice.courses.partials.session-materials',
            'evaluations' => 'backoffice.courses.partials.session-evaluation',
            'announcements' => 'backoffice.courses.partials.session-announcements',
        };

        if ($panel === 'materials') {
            $selectedSession->materials = collect($data['materials'] ?? []);
        } elseif ($panel === 'evaluations') {
            $selectedSession->evaluaciones_asignadas = $data['asignadas'] ?? [];
            $selectedSession->evaluaciones_disponibles = $data['disponibles'] ?? [];
            $planResult = $this->sesionService->obtenerPlanEvaluacionCurso($course);
            $evaluationPlan = $planResult->ok() ? $planResult->data() : ['sessions' => []];
        } else {
            $selectedSession->announcements = collect($data['announcements'] ?? []);
        }

        return response()->json([
            'ok' => true,
            'html' => view($view, [
                'course' => $context['course'],
                'session' => $selectedSession,
                'sessions' => $context['sessions'],
                'evaluationPlan' => $evaluationPlan ?? ['sessions' => []],
            ])->render(),
            'meta' => ['count' => $this->panelCount($panel, $selectedSession)],
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }

    public function community(Request $request, SessionScheduleResolver $resolver, int $course)
    {
        $context = $this->loadWorkspaceContext($request, $resolver, $course, null);
        $chat = $this->cargarChatCurso($context['course'], $course);

        return response()->json([
            'ok' => true,
            'html' => view('backoffice.courses.partials.session-conversation', [
                'course' => $context['course'],
                'chat' => $chat,
            ])->render(),
            'meta' => [
                'comments' => (int) data_get($chat, 'total_mensajes', 0),
                'participants' => 0,
            ],
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }

    public function storeMaterial(Request $request, MaterialService $materialService
                                , int $cursoId, int $sessionId)
    {
        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'tipo' => ['required', 'in:archivo,link,video'],
            'archivo' => [
                'required_if:tipo,archivo',
                'nullable',
                'file',
                'max:30720',
                'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,zip,jpg,jpeg,png,gif,webp',
            ],
            'url_externa' => ['required_if:tipo,link,video', 'nullable', 'url', 'max:2048'],
        ], [
            'archivo.required_if' => 'Selecciona el archivo que deseas subir.',
            'archivo.max' => 'El archivo no debe superar los 30 MB.',
            'archivo.mimes' => 'El formato del archivo no esta permitido.',
            'url_externa.required_if' => 'Ingresa la URL del material.',
        ]);

        $payload = collect($validated)->only([
            'titulo',
            'descripcion',
            'tipo',
            'url_externa',
        ])->all();

        if ($request->hasFile('archivo')) {
            $payload['archivo'] = $request->file('archivo');
        }

        $result = $materialService->crearMaterialSesion($sessionId, $payload);

        if (!$result->ok()) {
            $error = $result->error();
            $correlationId = $error['correlation_id'] ?? 'N/A';

            return back()->withErrors([
                'material' => "No se pudo crear material. Código: {$correlationId}"
            ]);
        }

        $this->sesionService->forgetCourseSessions(
            $cursoId,
            (string) $request->session()->get(AuthSessionKeys::USER_ROLE, '')
        );

        return back()->with('success', 'Material agregado correctamente');
    }

    public function updateMaterial(
    Request $request,
    MaterialService $materialService,
    int $cursoId,
    int $sessionId,
    int $materialId
) {

    $payload = $request->only([
        'titulo',
        'descripcion',
        'tipo',
        'url_externa',
    ]);

    if ($request->hasFile('archivo')) {
        $payload['archivo'] = $request->file('archivo');
    }

    $result = $materialService->actualizarMaterialSesion(
        $sessionId,
        $materialId,
        $payload
    );

    if (!$result->ok()) {

        $error = $result->error();
        $correlationId = $error['correlation_id'] ?? 'N/A';

        return back()->withErrors([
            'material' => "No se pudo actualizar material. Código: {$correlationId}"
        ]);
    }

    $this->sesionService->forgetCourseSessions(
        $cursoId,
        (string) $request->session()->get(AuthSessionKeys::USER_ROLE, '')
    );

    return redirect()
        ->route('backoffice.courses.show', [$cursoId, $sessionId])
        ->with('success', 'Material actualizado correctamente');
}

    public function destroyMaterial(Request $request, MaterialService $materialService, int $cursoId
                                 , int $sessionId,  int $materialId )
    {
        $result = $materialService->eliminarMaterialSesion($sessionId, $materialId);

        if(!$result->ok()){
        return back()->withErrors('No se pudo eliminar material.');
        }

        $this->sesionService->forgetCourseSessions(
            $cursoId,
            (string) $request->session()->get(AuthSessionKeys::USER_ROLE, '')
        );

        return back()->with('success','Material eliminado.');
    }

  public function downloadMaterial(
    \Illuminate\Http\Request $request,
    int $material
) {
    return $this->materialBinaryResponse($request, $material, 'attachment');
}

public function previewMaterial(
    \Illuminate\Http\Request $request,
    int $material
) {
    $correo = (string) $request->session()
        ->get(\App\Support\AuthSessionKeys::USER_EMAIL, '');

    if ($correo === '') {
        return redirect()->route('login');
    }

    $result = $this->materialService->descargarMaterial($material);

    if (!$result->ok()) {
        abort($result->status() ?? 500);
    }

    $apiResponse = $result->data();
    $contentType = $apiResponse->header('Content-Type') ?? 'application/octet-stream';
    $contentDisposition = $apiResponse->header('Content-Disposition');

    if (!$this->isPreviewableMaterialType($contentType)) {
        abort(415);
    }

    $filename = 'archivo';

    if ($contentDisposition && preg_match('/filename="?([^"]+)"?/', $contentDisposition, $matches)) {
        $filename = $matches[1];
    }

    $body = $this->apiBinaryBody($apiResponse);
    $mime = strtolower(trim(strtok($contentType, ';') ?: $contentType));

    if (str_starts_with($mime, 'image/')) {
        $safeTitle = e($filename);
        $dataUri = 'data:'.$mime.';base64,'.base64_encode($body);

        return response(
            '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'.
            '<title>'.$safeTitle.'</title><style>html,body{margin:0;min-height:100%;background:#f1f5f9;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'.
            'main{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}'.
            'img{display:block;max-width:100%;max-height:calc(100vh - 32px);object-fit:contain;background:white;border-radius:8px;box-shadow:0 1px 3px rgba(15,23,42,.12)}</style></head>'.
            '<body><main><img src="'.$dataUri.'" alt="'.$safeTitle.'"></main></body></html>',
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]
        );
    }

    $disposition = $this->contentDisposition('inline', $filename);

    return response($body, 200, [
        'Content-Type'              => $contentType,
        'Content-Length'            => (string) strlen($body),
        'Content-Disposition'       => $disposition,
        'Cache-Control'             => 'no-store, no-cache, must-revalidate',
        'Pragma'                    => 'no-cache',
        'X-Content-Type-Options'    => 'nosniff',
    ]);
}

private function materialBinaryResponse(
    \Illuminate\Http\Request $request,
    int $material,
    string $dispositionType,
    bool $previewOnly = false
) {
    $correo = (string) $request->session()
        ->get(\App\Support\AuthSessionKeys::USER_EMAIL, '');

    if ($correo === '') {
        return redirect()->route('login');
    }

    $result = $this->materialService->descargarMaterial($material);

    if (!$result->ok()) {
        abort($result->status() ?? 500);
    }

    $apiResponse = $result->data();

    $contentType = $apiResponse->header('Content-Type') ?? 'application/octet-stream';
    $contentDisposition = $apiResponse->header('Content-Disposition');

    if ($previewOnly && !$this->isPreviewableMaterialType($contentType)) {
        abort(415);
    }

    $filename = 'archivo';

    if ($contentDisposition && preg_match('/filename="?([^"]+)"?/', $contentDisposition, $matches)) {
        $filename = $matches[1];
    }

    $body = $this->apiBinaryBody($apiResponse);

    if ($dispositionType === 'attachment') {
        $tempPath = tempnam(storage_path('app'), 'material_download_');
        file_put_contents($tempPath, $body);

        return response()
            ->download($tempPath, $filename, [
                'Content-Type' => $contentType,
                'Content-Length' => (string) strlen($body),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend(true);
    }

    $disposition = $this->contentDisposition($dispositionType, $filename);

    return response($body, 200, [
        'Content-Type'              => $contentType,
        'Content-Length'            => (string) strlen($body),
        'Content-Disposition'       => $disposition,
        'Cache-Control'             => 'no-store, no-cache, must-revalidate',
        'Pragma'                    => 'no-cache',
        'X-Content-Type-Options'    => 'nosniff',
    ]);
}

private function apiBinaryBody($apiResponse): string
{
    $stream = $apiResponse->toPsrResponse()->getBody();

    if ($stream->isSeekable()) {
        $stream->rewind();
    }

    $body = $stream->getContents();

    return $body !== '' ? $body : $apiResponse->body();
}

private function isPreviewableMaterialType(string $contentType): bool
{
    $mime = strtolower(trim(strtok($contentType, ';') ?: $contentType));

    return $mime === 'application/pdf' || str_starts_with($mime, 'image/');
}

private function contentDisposition(string $type, string $filename): string
{
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'archivo';
    $encoded = rawurlencode($filename);

    return "{$type}; filename=\"{$fallback}\"; filename*=UTF-8''{$encoded}";
}

public function storeAnnouncement(
    Request $request,
    int $cursoId
) {

    $payload = [
        'titulo'       => $request->title,
        'contenido'    => $request->content,
        'tipo'         => $request->type,
        'entidad_tipo' => $request->entidad_tipo,
        'entidad_id'   => $request->entidad_id,
    ];
    
    $result = $this->announcementService->crearAnuncio($payload);

    if (!$result->ok()) {

    $error = $result->error();
    $correlationId = $error['correlation_id'] ?? 'N/A';

    return back()
        ->withInput() // 👈 AQUI
        ->withErrors([
            'announcement' =>
                "No se pudo crear anuncio. Código: {$correlationId}"
        ]);
    }

    return back()
    ->withInput(['active_tab' => 'anuncios'])
    ->with('success_annuncio', 'Anuncio agregado correctamente');
}

public function updateAnnouncement(
    Request $request,
    int $cursoId,
    int $announcementId
) {

    $payload = [
        'titulo'    => $request->title,
        'contenido' => $request->content,
        'tipo'      => $request->type,
    ];

    $result = $this->announcementService
        ->actualizarAnuncio($announcementId, $payload);

    if (!$result->ok()) {

        $error = $result->error();
        $correlationId = $error['correlation_id'] ?? 'N/A';

        return back()->withErrors([
            'announcement' =>
                "No se pudo actualizar anuncio. Código: {$correlationId}"
        ]);
    }

    return back()
        ->withInput(['active_tab' => 'anuncios'])
        ->with('success_annuncio', 'Anuncio actualizado correctamente');
    }

public function destroyAnnouncement(
    int $cursoId,
    int $announcementId
) {

    $result = $this->announcementService
        ->eliminarAnuncio($announcementId);

    if (!$result->ok()) {

        return back()
            ->withInput(['active_tab' => 'anuncios'])
            ->withErrors([
                'announcement' => 'No se pudo eliminar el anuncio.'
            ]);
    }

    return back()
        ->withInput(['active_tab' => 'anuncios']) // 👈 CLAVE
        ->with('success_annuncio', 'Anuncio eliminado correctamente');
}

  public function courseAnnouncements(Request $request, int $course, ?int $session = null)
{
    $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

    if ($correo === '') {
        return redirect()->route('login');
    }

    $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

    // 🔹 Traer sesiones
    $sessionsResult = $this->sesionService->listarSesionesCurso($course, $rol);

    $sessions = $sessionsResult->ok()
        ? collect($sessionsResult->data()['sessions'] ?? [])
        : collect();

    // 🔹 Construir curso con métricas (método que SÍ existe aquí)
    $courseData = $this->buildCourseWithMetrics($course, $sessions);

    // 🔹 Resolver sesión seleccionada
    $selectedSession = null;

    if ($session) {
        $selectedSession = $sessions->firstWhere('id', $session);
    }

    // 🔹 Traer anuncios del curso
    $result = $this->announcementService->listarAnuncios('curso', $course);

    $announcements = $result->ok()
        ? collect($result->data()['announcements'] ?? [])
        : collect();

    return view('backoffice.courses.announcements.index', [
        'course'        => $courseData,
        'sessions'      => $sessions,
        'session'       => $selectedSession,
        'announcements' => $announcements,
    ]);
}

    private function loadWorkspaceContext(
        Request $request,
        SessionScheduleResolver $resolver,
        int $courseId,
        ?int $sessionId
    ): array {
        abort_if(!(string) $request->session()->get(AuthSessionKeys::USER_EMAIL, ''), 401);

        $role = (string) $request->session()->get(AuthSessionKeys::USER_ROLE, '');
        abort_if($role === '', 401);

        $result = $this->sesionService->listarSesionesCurso($courseId, $role);
        $error = null;
        $sessions = collect();

        if ($result->ok()) {
            $sessions = collect($result->data()['sessions'] ?? []);
        } else {
            $error = 'No se pudieron cargar las sesiones.';
        }

        [$sessions, $session] = $resolver->resolve($sessions, $sessionId);

        if (!empty($session?->id)) {
            $session->evaluaciones = $session->evaluaciones ?? [];
            $session->evaluaciones_asignadas = $session->evaluaciones_asignadas ?? [];
            $session->evaluaciones_disponibles = $session->evaluaciones_disponibles ?? [];
            $session->materials = collect($session->materials ?? []);
            $session->announcements = collect($session->announcements ?? []);
        }

        return [
            'course' => $this->buildCourseWithMetrics($courseId, $sessions),
            'sessions' => $sessions,
            'session' => $session,
            'error' => $error,
            'chat' => ['total_mensajes' => 0, 'mensajes' => collect(), 'loading' => true],
        ];
    }

    private function workspaceMeta(object $session, $sessions): array
    {
        $items = collect($sessions)->values();
        $index = $items->search(fn ($item) => (int) $item->id === (int) $session->id);

        return [
            'session_id' => (int) $session->id,
            'position' => $index === false ? 1 : $index + 1,
            'total' => $items->count(),
            'previous_id' => $index !== false && $index > 0 ? (int) $items[$index - 1]->id : null,
            'next_id' => $index !== false && $index < $items->count() - 1 ? (int) $items[$index + 1]->id : null,
        ];
    }

    private function panelCount(string $panel, object $session): int
    {
        return match ($panel) {
            'materials' => collect($session->materials ?? [])->count(),
            'evaluations' => count($session->evaluaciones_asignadas ?? []),
            'announcements' => collect($session->announcements ?? [])->count(),
            default => 0,
        };
    }

    private function buildCourseWithMetrics(int $cursoId, $sessions): object
    {
        $course = (object)['id' => $cursoId];

        if ($sessions->isNotEmpty()) {
            $first = $sessions->first();

            $course->title = $first->course ?? $first->curso ?? 'Curso';
            $course->teacher_name = $first->teacher ?? $first->docente ?? '';
            $course->curso_edicion_id = $first->curso_edicion_id ?? null;
            $course->curso_id = $first->curso_id ?? null;
            $course->edition = $first->edition ?? null;
            $course->state = $first->course_state ?? null;
        }

        $totalSessions = $sessions->count();

        $actionableSessions = $sessions
            ->filter(fn ($session) => in_array(SessionPresentation::lifecycle($session), ['in_progress', 'finished'], true));

        $materialPending = $actionableSessions
            ->filter(fn ($session) => (bool) ($session->material_pending ?? $session->falta_material ?? false))
            ->count();

        $evaluationPending = 0;

        $doneSessions = $actionableSessions
            ->filter(fn ($session) => SessionPresentation::isComplete($session))
            ->count();

        $course->material_pending = $materialPending;
        $course->evaluations_pending = $evaluationPending;
        $course->progress = "{$doneSessions} de {$totalSessions}";
        $course->progress_percent = $totalSessions
            ? round(($doneSessions / $totalSessions) * 100)
            : 0;

        return $course;
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

        Log::error('Error cargando chat de curso docente', [
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

    /**
 * Lista cursos para gestión de evaluaciones (admin / operador)
 */
public function evaluaciones(Request $request)
{
    $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
    $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

    Log::info('CoursesController@evaluaciones', [
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
    $cursos = collect();

    $result = $this->courseService->listarCursosParaEvaluaciones();

    if (!$result->ok()) {

        Log::error('Error listando cursos evaluaciones', [
            'correo' => $correo,
            'error' => $result->error(),
        ]);

        $error = 'No se pudieron cargar los cursos.';

    } else {

        $cursos = collect($result->data()['cursos'] ?? []);
    }

    return view('backoffice.evaluations.index', [
        'cursos' => $cursos,
        'error'  => $error,
    ]);
}



}
