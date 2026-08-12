<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MisCursos\Traits\BuildCourseContext;
use App\Services\CursoService;
use App\Services\SesionService;
use App\Services\SurveyService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;

class SurveyController extends Controller
{
    use BuildCourseContext;

    public function __construct(
        private readonly SurveyService $surveyService,
        private readonly SesionService $sesionService,
        private readonly CursoService $cursoService
    ) {
    }

    public function index()
    {
        return redirect()->route('mis-cursos.index');
    }

    public function show(Request $request, int $course, int $session, int $link)
    {
        $email = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        $role = strtolower((string) $request->session()->get(AuthSessionKeys::USER_ROLE, ''));
        if ($email === '') {
            return redirect()->route('login');
        }
        if ($role !== 'alumno') {
            abort(403);
        }

        [$courseData, $sessions] = $this->construirCurso($course, $role);
        $selectedSession = $sessions->first(fn ($item) => (int) $item->id === $session);
        if (!$selectedSession) {
            abort(404);
        }

        $result = $this->surveyService->obtenerEncuestaAlumno($course, $session, $link);
        if (!$result->ok()) {
            return redirect()->route('mis-cursos.show', [$course, $session])
                ->withErrors(['message' => $result->error() ?: 'La encuesta no está disponible en este momento.']);
        }
        $data = $result->data();
        $survey = $data['encuesta'];
        if (($survey->answered ?? false) || !($survey->available ?? false)) {
            return redirect()->route('mis-cursos.show', [$course, $session])
                ->withErrors(['message' => ($survey->answered ?? false)
                    ? 'Esta encuesta ya fue respondida.'
                    : 'La encuesta todavía no está disponible.']);
        }

        return view('mis-cursos.surveys.show', [
            'course' => $courseData,
            'sessions' => $sessions,
            'session' => $selectedSession,
            'encuesta' => $survey,
            'preguntas' => $data['preguntas'],
            'docentes' => $data['docentes'],
        ]);
    }

    public function legacy(Request $request, int $course, int $type)
    {
        [$courseData, $sessions] = $this->construirCurso($course, 'alumno');
        $sessionId = $request->integer('session_id');
        $selected = $sessionId > 0 ? $sessions->firstWhere('id', $sessionId) : $sessions->last();
        if (!$selected) {
            return redirect()->route('mis-cursos.show', $course);
        }
        $kind = $type === 2 ? 'final' : 'session';
        $survey = collect($selected->surveys ?? [])->first(fn ($item) => ($item->kind ?? '') === $kind);
        if (!$survey) {
            return redirect()->route('mis-cursos.show', [$course, $selected->id])
                ->withErrors(['message' => 'La encuesta todavía no está disponible.']);
        }
        return redirect()->route('mis-cursos.encuestas.show', [$course, $selected->id, $survey->link_id]);
    }
}
