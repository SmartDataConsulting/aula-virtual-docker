<?php

namespace App\Http\Controllers\MisCursos\Traits;
use App\Services\CursoService;
use App\Services\SesionService;
use Illuminate\Support\Facades\Log;

trait BuildCourseContext
{
    protected function construirCurso(int $cursoId, $rol): array
    {
        $meta = ['title' => 'Curso', 'teacher' => 'Docente'];
        $sessions = collect();

        $correo = session('user_email');
        $result = $rol === 'alumno' && $correo
            ? $this->sesionService->listarSesionesCursoLight($cursoId, (string) $correo)
            : $this->sesionService->listarSesionesCurso($cursoId, $rol);

        if ($result->ok()) {
            $sessions = collect($result->data()['sessions'] ?? []);
        }

        if ($sessions->isNotEmpty()) {
            $first = $sessions->first(fn($i) => !empty($i->course));
            if ($first) {
                $meta['title'] = $first->course;
                $meta['teacher'] = $first->teacher;
                $meta['edition'] = $first->edition ?? null;
                $meta['curso_edicion_id'] = $first->curso_edicion_id ?? null;
                $meta['curso_id'] = $first->curso_id ?? null;
            }
        }

        $total = $sessions->count();
        $completed = $sessions->where('state', 'realizada')->count();

        if ($completed === 0) {
            $completed = $sessions->where('state', 'past')->count();
        }

        $percent = $total > 0
            ? round(($completed / $total) * 100, 1)
            : 0;

        $mostrarEncuesta = false;

        if ($rol === 'alumno') {
            $cursoData = $this->cursoService->obtener($cursoId);

            if ($cursoData) {
                $mostrarEncuesta = $this->cursoService->debeMostrarEncuestaFinal(
                    $cursoData['id'],
                    $cursoData['fechafin']
                );
            }
        }

        $course = (object)[
            'id' => $cursoId,
            'curso_edicion_id' => $meta['curso_edicion_id'] ?? null,
            'curso_id' => $meta['curso_id'] ?? null,
            'title' => $meta['title'],
            'teacher_name' => $meta['teacher'],
            'edition' => $meta['edition'] ?? null,
            'progress' => sprintf('%d de %d', $completed, $total),
            'progress_percent' => $percent,
            'mostrar_encuesta_final' => $mostrarEncuesta,
            'sessions' => $sessions,
        ];

         Log::info('COURSE_CONTEXT_BUILT', [
            'id' => $course->id,
            'title' => $course->title,
            'progress' => $course->progress,
            'progress_percent' => $course->progress_percent,
            'mostrar_encuesta_final' => $course->mostrar_encuesta_final,
            'sessions_count' => is_countable($course->sessions) ? count($course->sessions) : $course->sessions->count()
        ]);

        return [$course, $sessions];
    }

    
}
