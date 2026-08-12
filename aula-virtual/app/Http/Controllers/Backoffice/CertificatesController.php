<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\CursoService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class CertificatesController extends Controller
{
    protected CursoService $courseService;

    public function __construct(CursoService $courseService)
    {
        $this->courseService = $courseService;
    }

    public function index(Request $request)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        $search = trim((string) $request->query('search', ''));

        Log::info('CertificatesController@index', [
            'correo' => $correo,
            'rol' => $rol,
            'search' => $search,
        ]);

        if (!$correo) {
            return redirect()->route('login');
        }

        if ($rol !== 'admin') {
            abort(403);
        }

        $error = null;
        $courses = collect();

        $result = $this->courseService->listarCursosParaCertificados();

        if (!$result->ok()) {
            Log::error('Error listando cursos para certificados', [
                'correo' => $correo,
                'rol' => $rol,
                'error' => $result->error(),
            ]);

            $error = 'No se pudieron cargar los cursos.';
        } else {
            $payload = $result->data();
            $courses = collect($payload['cursos'] ?? []);

            if ($rol === 'admin' && mb_strlen($search) >= 4) {
                $needle = mb_strtolower($search);

                $courses = $courses->filter(function (array $course) use ($needle) {
                    $haystack = mb_strtolower(implode(' ', [
                        (string) ($course['title'] ?? ''),
                        (string) ($course['code'] ?? ''),
                        (string) ($course['edition'] ?? ''),
                        (string) ($course['teacher'] ?? ''),
                        (string) ($course['id'] ?? ''),
                    ]));

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

        Log::info('CertificatesController@index payload vista', [
            'total' => $courses->total(),
            'current_page' => $courses->currentPage(),
            'error' => $error,
            'search' => $search,
            'role' => $rol,
        ]);

        return view('backoffice.certificates.index', [
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

        if (!$correo) {
            return redirect()->route('login');
        }

        if ($rol !== 'admin') {
            abort(403);
        }

        $error = null;
        $course = [
            'id' => $courseId,
            'title' => 'Curso',
            'teacher' => '',
            'schedule' => '',
        ];
        $summary = [
            'total' => 0,
            'sent' => 0,
            'pending' => 0,
        ];
        $students = collect();

        $result = $this->courseService->obtenerCertificadosPorCurso($courseId);

        if (!$result->ok()) {
            Log::error('Error cargando certificados del curso', [
                'curso_edicion_id' => $courseId,
                'correo' => $correo,
                'rol' => $rol,
                'status' => $result->status(),
                'error' => $result->error(),
            ]);

            $error = 'No se pudieron cargar los certificados del curso.';
        } else {
            $payload = $result->data();
            $course = $payload['course'] ?? $course;
            $summary = $payload['summary'] ?? $summary;
            $students = collect($payload['students'] ?? []);
        }

        return view('backoffice.certificates.show', [
            'course' => $course,
            'summary' => $summary,
            'students' => $students,
            'error' => $error,
            'userEmail' => $correo,
        ]);
    }

    public function attach(Request $request, int $courseId, string $studentEmail)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return response()->json([
                'ok' => false,
                'message' => 'Sesion no valida.',
            ], 401);
        }

        if ($rol !== 'admin') {
            abort(403);
        }

        $request->validate([
            'certificado' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
        ]);

        $result = $this->courseService->adjuntarCertificado([
            'alumno_correo' => strtolower(trim(urldecode($studentEmail))),
            'curso_edicion_id' => $courseId,
            'usuario_adjunta' => $correo,
            'certificado' => $request->file('certificado'),
        ]);

        if (!$result->ok()) {
            return response()->json([
                'ok' => false,
                'message' => $result->error()['message'] ?? 'No se pudo adjuntar el certificado.',
            ], $result->status() ?: 400);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Certificado adjuntado correctamente.',
            'certificate' => $result->data()['certificate'] ?? [],
        ]);
    }

    public function send(Request $request, int $courseId, int $certificateId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return response()->json([
                'ok' => false,
                'message' => 'Sesion no valida.',
            ], 401);
        }

        if ($rol !== 'admin') {
            abort(403);
        }

        $result = $this->courseService->enviarCertificado($certificateId, [
            'usuario_envia' => $correo,
        ]);

        if (!$result->ok()) {
            return response()->json([
                'ok' => false,
                'message' => $result->error()['message'] ?? 'No se pudo enviar el certificado.',
            ], $result->status() ?: 400);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Certificado enviado correctamente.',
            'certificate' => $result->data()['certificate'] ?? [],
        ]);
    }

    public function syncSga(Request $request, int $courseId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);
        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);

        if (!$correo) {
            return response()->json([
                'ok' => false,
                'message' => 'Sesion no valida.',
            ], 401);
        }

        if ($rol !== 'admin') {
            abort(403);
        }

        $result = $this->courseService->sincronizarDiplomasSgaCurso($courseId, [
            'usuario_sincroniza' => $correo,
        ]);

        if (!$result->ok()) {
            return response()->json([
                'ok' => false,
                'message' => $result->error()['message'] ?? 'No se pudo sincronizar con SGA.',
            ], $result->status() ?: 400);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Diplomas sincronizados desde SGA.',
            'sync' => $result->data()['sync'] ?? [],
        ]);
    }
}
