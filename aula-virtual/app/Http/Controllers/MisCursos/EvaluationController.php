<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MisCursos\Traits\BuildCourseContext;
use App\Services\CursoService;
use Illuminate\Http\Request;
use App\Services\EvaluationService;
use App\Services\EvaluationSubmissionService;
use App\Services\SesionService;
use App\Domain\Cursos\Scheduling\SessionScheduleResolver;
use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Log;

class EvaluationController extends Controller
{
    use BuildCourseContext;

    private const WORK_TYPES = [3, 4];

    public function __construct(
        private EvaluationService $evaluationService,
        private EvaluationSubmissionService $evaluationSubmissionService,
        private SesionService $sesionService,
        private CursoService $cursoService
    ) {}

    public function take(Request $request, $courseId, $sessionId, $evaluationId)
    {
        $result = $this->evaluationService->getEvaluation($evaluationId);

        if (!$result->ok()) {
            abort(500, 'No se pudo cargar la evaluación');
        }

        $data = $result->data();

        $evaluacion = $data['evaluacion'];
        $preguntas = $data['preguntas'];

        if ($this->isWorkType((int) ($evaluacion['type_id'] ?? 0))) {
            return redirect()->route('mis-cursos.evaluaciones.trabajo.show', [
                'course' => $courseId,
                'session' => $sessionId,
                'evaluation' => $evaluationId,
            ]);
        }

        return view('mis-cursos.evaluation.take', [
            'courseId' => $courseId,
            'sessionId' => $sessionId,
            'evaluationId' => $evaluationId,
            'evaluation' => $evaluacion,
            'questionCount' => count($preguntas),
        ]);
    }

    public function work(Request $request, int $courseId, int $sessionId, int $evaluationId)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return redirect()->route('login');
        }

        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        if (!$rol) {
            abort(401);
        }

        [$course, $sessions] = $this->construirCurso($courseId, $rol);
        $session = $sessions->firstWhere('id', $sessionId) ?? $sessions->first();

        $result = $this->evaluationService->getStudentWorkEvaluation($evaluationId);

        if (!$result->ok()) {
            Log::error('EvaluationController@work error', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'error' => $result->error(),
            ]);

            abort(500, 'No se pudo cargar el trabajo');
        }

        $data = $result->data();

        return view('mis-cursos.evaluation.work', [
            'course' => $course,
            'sessions' => $sessions,
            'session' => $session,
            'courseId' => $courseId,
            'sessionId' => $sessionId,
            'evaluationId' => $evaluationId,
            'evaluation' => $data['evaluacion'],
            'trabajo' => $data['trabajo'],
            'entrega' => $data['entrega'],
            'sidebarDefaultTab' => 'evaluations',
            'sessionNavigationMode' => 'page',
        ]);
    }

    public function run($courseId, $sessionId, $evaluationId)
    {
        $result = $this->evaluationService->getEvaluation($evaluationId);

        if (!$result->ok()) {
            abort(500, 'No se pudo cargar la evaluación');
        }

        $data = $result->data();

        return view('mis-cursos.evaluation.run', [
            'courseId' => $courseId,
            'sessionId' => $sessionId,
            'evaluationId' => $evaluationId,
            'evaluacion' => $data['evaluacion'],
            'preguntas' => $data['preguntas']
        ]);
    }

    public function evaluate(
    Request $request,
    $courseId,
    $sessionId,
    $evaluationId
    ) {
        $answers = $request->input('answers', []);

        $result = $this->evaluationService->evaluate(
            (int)$evaluationId,
            $answers
        );

        if (!$result->ok()) {
            return response()->json([
                'message' => 'Error al evaluar'
            ], 500);
        }

        return response()->json($result->data());
    }

    public function listarNotasAlumnoPorCurso(
        Request $request,
        SessionScheduleResolver $sessionScheduleResolver,
        int $courseId
    ) {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return redirect()->route('login');
        }

        $rol = $request->session()->get(AuthSessionKeys::USER_ROLE);
        if (!$rol) {
            abort(401);
        }

        if ($request->header('X-Panel-Request') === 'true') {
            $html = view('mis-cursos.partials.notas', [
                'courseId' => $courseId,
            ])->render();

            return response()->json([
                'html' => $html,
            ]);
        }

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            $response = $this->evaluationSubmissionService->listarNotasAlumnoPorCurso($courseId);

            if (!($response['ok'] ?? false)) {
                return response()->json([
                    'message' => $response['message'] ?? 'No se pudieron obtener las notas.',
                ], 500);
            }

            return response()->json($response['data'] ?? [], 200);
        }

        [$course, $sessions] = $this->construirCurso($courseId, $rol);
        [$sessions, $session] = $sessionScheduleResolver->resolve($sessions, null);

        $course->sessions = $sessions;

        return view('mis-cursos.notas', [
            'course' => $course,
            'courseId' => $courseId,
            'sessions' => $sessions,
            'session' => $session,
        ]);
    }

    public function saveWorkSubmission(
        Request $request,
        int $courseId,
        int $sessionId,
        int $evaluationId
    ) {
        try {
            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

            if ($correo === '') {
                return response()->json(['message' => 'No autorizado'], 401);
            }

            $files = $request->file('archivos', []);

            $payload = [
                'observacion_alumno' => $request->input('observacion_alumno'),
                'archivos' => is_array($files) ? $files : [$files],
                'archivos_eliminar' => array_values(array_filter(
                    (array) $request->input('archivos_eliminar', []),
                    fn ($value) => $value !== null && $value !== ''
                )),
            ];

            $result = $this->evaluationService->saveStudentWorkSubmission(
                $evaluationId,
                $payload
            );

            if (!$result->ok()) {
                return response()->json([
                    'message' => $this->resolveApiErrorMessage($result->error(), 'No se pudo guardar la entrega'),
                ], $result->status() ?? 500);
            }

            return response()->json($result->data());
        } catch (\Throwable $e) {
            Log::error('EvaluationController@saveWorkSubmission exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo guardar la entrega',
            ], 500);
        }
    }

    public function finalizeWorkSubmission(
        Request $request,
        int $courseId,
        int $sessionId,
        int $evaluationId
    ) {
        try {
            $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

            if ($correo === '') {
                return response()->json(['message' => 'No autorizado'], 401);
            }

            $result = $this->evaluationService->finalizeStudentWorkSubmission(
                $evaluationId,
                [
                    'observacion_alumno' => $request->input('observacion_alumno'),
                ]
            );

            if (!$result->ok()) {
                return response()->json([
                    'message' => $this->resolveApiErrorMessage($result->error(), 'No se pudo finalizar la entrega'),
                ], $result->status() ?? 500);
            }

            return response()->json($result->data());
        } catch (\Throwable $e) {
            Log::error('EvaluationController@finalizeWorkSubmission exception', [
                'evaluation_id' => $evaluationId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo finalizar la entrega',
            ], 500);
        }
    }

    public function downloadWorkAttachment(
        Request $request,
        int $courseId,
        int $sessionId,
        int $evaluationId,
        int $attachmentId
    ) {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return redirect()->route('login');
        }

        $result = $this->evaluationService->downloadStudentWorkAttachment($attachmentId);

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

    private function isWorkType(int $typeId): bool
    {
        return in_array($typeId, self::WORK_TYPES, true);
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

    private function buildDisposition(string $contentType, string $filename): string
    {
        $disposition = str_starts_with($contentType, 'application/pdf')
            ? 'inline'
            : 'attachment';

        return sprintf('%s; filename="%s"', $disposition, addslashes($filename));
    }
}
