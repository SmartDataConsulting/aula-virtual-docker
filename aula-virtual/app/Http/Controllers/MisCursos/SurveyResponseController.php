<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use App\Services\SesionService;
use App\Services\SurveyService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SurveyResponseController extends Controller
{
    public function __construct(private readonly SurveyService $service, private readonly SesionService $sesionService)
    {
    }

    public function store(Request $request, int $courseId, int $sesionId, int $linkId)
    {
        $email = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        $role = strtolower((string) $request->session()->get(AuthSessionKeys::USER_ROLE, ''));
        if ($email === '') {
            return redirect()->route('login');
        }
        if ($role !== 'alumno') {
            abort(403);
        }

        $validated = $request->validate([
            'teacher_id' => ['nullable', 'integer', 'min:1'],
            'answers' => ['nullable', 'array'],
            'teacher_answers' => ['nullable', 'array'],
        ]);
        $result = $this->service->registrarEncuestaAlumno($courseId, $sesionId, $linkId, $validated);
        if (!$result->ok()) {
            Log::warning('survey_response_failed', [
                'course_id' => $courseId,
                'session_id' => $sesionId,
                'link_id' => $linkId,
                'status' => $result->status(),
            ]);
            $error = $result->error();
            $message = (string) ($error['message'] ?? ($result->status() === 409
                ? 'Esta encuesta ya fue respondida o todavía no está disponible.'
                : 'No se pudo guardar la encuesta. Revisa tus respuestas e inténtalo nuevamente.'));
            $fieldErrors = is_array($error['errors'] ?? null) ? $error['errors'] : [];

            return back()->withInput()->withErrors(['message' => $message] + $fieldErrors);
        }

        $this->sesionService->forgetStudentCourseSessions($courseId, $email);
        $this->sesionService->forgetStudentSessionDetail($courseId, $sesionId, $email);

        return redirect()->route('mis-cursos.show', [$courseId, $sesionId, 'tab' => 'surveys'])
            ->with('invalidate_course_panel', 'surveys')
            ->with('invalidate_course_session', $sesionId)
            ->with('success', 'Gracias. Tu encuesta fue registrada correctamente.');
    }
}
