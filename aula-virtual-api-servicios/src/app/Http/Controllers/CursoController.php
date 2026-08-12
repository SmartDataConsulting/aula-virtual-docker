<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Routing\Controller as BaseController;
use App\Services\CursoService;
use App\Support\ApiCache;

class CursoController extends BaseController
{
    protected CursoService $service;

    public function __construct(CursoService $service)
    {
        $this->service = $service;
    }

    public function resumenAlumno()
    {
        try {
            $correo = trim((string) request('correo', request()->header('X-USER-EMAIL', '')));

            if ($correo === '') {
                return response()->json(['error' => 'correo requerido'], 400);
            }

            $cacheKey = ApiCache::courseSummaryKey('student-dashboard', 'alumno', $correo);
            $payload = Cache::remember($cacheKey, 120, function () use ($correo) {
                $courses = $this->service->listarCursosAlumno($correo);
                $suggested = $this->service->listarCursosSugeridosAlumno($correo);

                return [
                    'courses' => array_map(fn ($course) => $this->mapCourseSummary($course, 'alumno'), $courses),
                    'suggested' => array_map(fn ($course) => $this->mapCourseSummary($course, 'alumno', true), $suggested),
                ];
            });

            $all = array_merge($payload['courses'], $payload['suggested']);

            return response()->json([
                'ok' => true,
                'metrics' => [
                    'in_progress' => count(array_filter($payload['courses'], fn ($course) => $course['estado'] === 'en curso')),
                    'completed' => count(array_filter($payload['courses'], fn ($course) => $course['estado'] === 'finalizado')),
                    'pending' => array_sum(array_map(fn ($course) => (int) ($course['pending_items_count'] ?? 0), $payload['courses'])),
                    'suggested' => count($payload['suggested']),
                ],
                'courses' => $all,
            ]);
        } catch (\Throwable $e) {
            $correlationId = (string) \Illuminate\Support\Str::uuid();
            Log::error('Error en resumen alumno', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error interno',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }

    public function resumenBackoffice()
    {
        try {
            $rol = strtolower((string) request()->header('X-USER-ROL', 'admin'));
            $correo = (string) request()->header('X-USER-EMAIL', '');
            $cacheKey = ApiCache::courseSummaryKey('backoffice-dashboard', $rol, $correo);

            $courses = Cache::remember($cacheKey, 120, function () use ($correo, $rol) {
                return array_map(
                    fn ($course) => $this->mapCourseSummary($course, $rol),
                    $this->service->listarCursosBackoffice($correo, $rol)
                );
            });

            return response()->json([
                'ok' => true,
                'metrics' => [
                    'active' => count(array_filter($courses, fn ($course) => $course['estado'] === 'en curso')),
                    'scheduled' => count(array_filter($courses, fn ($course) => $course['estado'] === 'programado')),
                    'finished' => count(array_filter($courses, fn ($course) => $course['estado'] === 'finalizado')),
                    'pending' => array_sum(array_map(fn ($course) => (int) ($course['pending_items_count'] ?? 0), $courses)),
                ],
                'courses' => $courses,
            ]);
        } catch (\Throwable $e) {
            $correlationId = (string) \Illuminate\Support\Str::uuid();
            Log::error('Error en resumen backoffice', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error interno',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }

    private function mapCourseSummary(object $course, string $role, bool $suggested = false): array
    {
        $data = [
            'id' => (int) ($course->id ?? $course->curso_edicion_id ?? 0),
            'curso_id' => isset($course->curso_id) ? (int) $course->curso_id : null,
            'nombre' => $course->nombre ?? $course->curso ?? null,
            'edicion' => $course->edicion ?? null,
            'docente' => $course->docente ?? null,
            'horario' => $course->horario ?? null,
            'imagen' => $course->imagen ?? null,
            'estado' => $suggested ? 'sugerido' : ($course->estadocurso ?? $course->estado ?? null),
            'total_sesiones' => (int) ($course->total_sesiones ?? $course->numero_sesiones ?? 0),
            'sesiones_realizadas' => (int) ($course->sesiones_realizadas ?? 0),
            'pending_items_count' => (int) ($course->pending_items_count ?? 0)
                + (int) ($course->pending_evaluations_count ?? 0)
                + (int) ($course->pending_surveys_count ?? 0),
        ];

        if ($suggested || !empty($course->sugerido)) {
            $data['sugerido'] = true;
            $data['suggestion_reason'] = (string) ($course->suggestion_reason ?? 'Tambien te puede interesar');
        }

        if (in_array($role, ['admin', 'administrador', 'operador'], true)) {
            $data['alumnos_inscritos'] = (int) ($course->alumnos_inscritos ?? 0);
            $data['total_evaluaciones'] = (int) ($course->total_evaluaciones ?? 0);
            $data['sesiones_pasadas_sin_material'] = (int) ($course->sesiones_pasadas_sin_material ?? 0);
        }

        return $data;
    }

    public function obtener($id)
    {
        try {

            $curso = $this->service->obtener((int) $id);

            if (!$curso) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Curso no encontrado'
                ], 404);
            }

            return response()->json([
                'id' => (int) $curso->id,
                'curso' => $curso->curso,
                'edicion' => $curso->edicion,
                'docente' => $curso->docente,
                'horario' => $curso->horario,
                'fechainicio' => $curso->fechainicio,
                'fechafin' => $curso->fechafin,
                'estado' => $curso->estadocurso,
                'imagen' => $curso->imagen,
                'numero_sesiones' => (int) $curso->numero_sesiones,
                'horasacademicas' => (int) $curso->horasacademicas,
            ]);

        } catch (\Throwable $e) {

            $correlationId = (string) \Illuminate\Support\Str::uuid();

            Log::error('Error en obtener curso', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error interno',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }

    /**
     * Lista cursos (alumno / profesor)
     */
    public function listar()
    {
        try {

        $start = microtime(true);

        $correo = trim((string) request('correo', ''));

        // Header interno
        $rol = request()->header('X-USER-ROL', 'alumno');

        if (!in_array($rol, ['admin', 'operador', 'alumno'], true)) {
            return response()->json(['error' => 'rol invalido'], 400);
        }

        if ($rol === 'alumno' && $correo === '') {
            return response()->json(['error' => 'correo requerido'], 400);
        }

        $includeSuggestions = $rol === 'alumno' && (bool) request('include_suggestions', false);

        // Cache separado por rol
        $cacheKey = ApiCache::courseSummaryKey($includeSuggestions ? 'main:suggestions' : 'main', $rol, $correo);

        $rows = Cache::remember($cacheKey, $includeSuggestions ? 300 : 120, function () use ($correo, $rol, $includeSuggestions) {

            if ($rol === 'admin' || $rol === 'operador') {
                return $this->service->listarCursosBackoffice($correo, $rol);
            }

            $cursos = $this->service->listarCursosAlumno($correo);

            if ($includeSuggestions) {
                $cursos = array_merge($cursos, $this->service->listarCursosSugeridosAlumno($correo));
            }

            return $cursos;
        });

        $data = array_map(function ($c) use ($rol) {

            $base = [
                'id' => (int) $c->id,
                'nombre' => $c->nombre,
                'edicion' => $c->edicion ?? null,
                'docente' => $c->docente,
                'horario' => $c->horario,
                'imagen' => $c->imagen,
                'estado' => $c->estadocurso,
                'alumnos_inscritos' => (int) ($c->alumnos_inscritos ?? 0),
                'total_sesiones' => (int) $c->total_sesiones,
                'sesiones_realizadas' => (int) $c->sesiones_realizadas,
                'pending_items_count' => (int) ($c->pending_items_count ?? 0)
                    + (int) ($c->pending_evaluations_count ?? 0)
                    + (int) ($c->pending_surveys_count ?? 0),
            ];

            if (!empty($c->sugerido)) {
                $base['sugerido'] = true;
                $base['estado'] = 'sugerido';
                $base['suggestion_reason'] = (string) ($c->suggestion_reason ?? 'Tambien te puede interesar');
            }

            // Campos extra solo profesor
            if ($rol === 'admin' || $rol === 'operador') {
                $base['sesiones_hoy_sin_material'] = (int) $c->sesiones_hoy_sin_material;
                $base['sesiones_pasadas_sin_material'] = (int) $c->sesiones_pasadas_sin_material;
                $base['total_evaluaciones'] = (int) $c->total_evaluaciones;
            }

            return $base;
        }, $rows);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('cursos', [
            'correo' => $correo,
            'rol' => $rol,
            'ms' => $elapsed,
            'count' => count($data)
        ]);

        return response()->json($data); 
        } catch (\Throwable $e) {

        $correlationId = (string) \Illuminate\Support\Str::uuid();

        Log::error('Error en cursos API', [
            'correlation_id' => $correlationId,
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'ok' => false,
            'message' => 'Error interno',
            'correlation_id' => $correlationId,
        ], 500);
    }
    }

    /**
     * HISTORIA 0
     * Listar cursos para gestión de evaluaciones
     */
public function listarParaEvaluaciones()
{
    try {

        $rol = request()->header('X-USER-ROL');
        $correo = request()->header('X-USER-EMAIL');

        $cacheKey = ApiCache::courseSummaryKey('evaluations', $rol, $correo);

        $rows = Cache::remember($cacheKey, 120, function () use ($correo, $rol) {
            return $this->service->listarCursosParaEvaluaciones($correo, $rol);
        });

        $data = array_map(function ($c) {
            return [
                'curso_id' => (int) $c->curso_id,
                'edicion' => $c->edicion ?? null,
                'nombre' => $c->nombre,
                'docente' => $c->docente ?? null,
                'horario' => $c->horario ?? null,
                'alumnos_inscritos' => (int) ($c->alumnos_inscritos ?? 0),
                'nro_evaluaciones' => (int) $c->nro_evaluaciones,
                'evaluaciones_publicadas' => (int) ($c->evaluaciones_publicadas ?? 0),
                'evaluaciones_borrador' => (int) ($c->evaluaciones_borrador ?? 0),
            ];
        }, $rows);

        return response()->json($data);

    } catch (\Throwable $e) {

        $correlationId = (string) \Illuminate\Support\Str::uuid();

        Log::error('Error en listar cursos evaluaciones', [
            'correlation_id' => $correlationId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'ok' => false,
            'message' => 'Error interno',
            'correlation_id' => $correlationId,
        ], 500);
    }
}

    public function listarParaCalificaciones()
    {
        try {

            $rol = request()->header('X-USER-ROL');
            $correo = request()->header('X-USER-EMAIL');

            $cacheKey = ApiCache::courseSummaryKey('qualifications', $rol, $correo);

            $rows = Cache::remember($cacheKey, 120, function () use ($correo, $rol) {
                return $this->service->listarCursosParaCalificaciones($correo, $rol);
            });

            $data = array_map(function ($c) {
                return [
                    'id' => (int) $c->curso_edicion_id,   // para rutas Calificar / Ver notas
                    'curso_id' => (int) $c->curso_id,
                    'codigo' => $c->codigo,
                    'nombre' => $c->nombre,
                    'docente' => $c->docente,
                    'horario' => $c->horario,
                    'imagen' => $c->imagen,
                    'alumnos_inscritos' => (int) ($c->alumnos_inscritos ?? 0),
                    'total_sesiones' => (int) $c->total_sesiones,
                    'sesiones_realizadas' => (int) $c->sesiones_realizadas,
                    'exam_count' => (int) $c->exam_count,
                    'work_count' => (int) $c->work_count,
                    'survey_response_count' => (int) ($c->survey_response_count ?? 0),
                     // Certificados
                    'certificados_total' => (int) ($c->certificados_total ?? 0),
                    'certificados_pendientes' => (int) ($c->certificados_pendientes ?? 0),
                    'certificados_adjuntados' => (int) ($c->certificados_adjuntados ?? 0),
                    'certificados_enviados' => (int) ($c->certificados_enviados ?? 0),
                ];
            }, $rows);

            return response()->json($data);

        } catch (\Throwable $e) {

            $correlationId = (string) \Illuminate\Support\Str::uuid();

            Log::error('Error en listar cursos calificaciones', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error interno',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }

    public function listarParaEncuestas()
    {
        try {
            $rol = strtolower((string) request()->header('X-USER-ROL', ''));
            $rol = $rol === 'administrador' ? 'admin' : $rol;
            $correo = (string) request()->header('X-USER-EMAIL', '');
            $cacheKey = ApiCache::courseSummaryKey('surveys', $rol, $correo);

            $rows = Cache::remember($cacheKey, 120, function () use ($correo, $rol) {
                return $this->service->listarCursosParaEncuestas($correo, $rol);
            });

            $data = array_map(static function ($course) {
                return [
                    'id' => (int) $course->curso_edicion_id,
                    'curso_id' => (int) $course->curso_id,
                    'codigo' => $course->codigo,
                    'nombre' => $course->nombre,
                    'docente' => $course->docente,
                    'horario' => $course->horario,
                    'imagen' => $course->imagen,
                    'estado' => $course->estadocurso,
                    'alumnos_inscritos' => (int) ($course->alumnos_inscritos ?? 0),
                    'total_sesiones' => (int) ($course->total_sesiones ?? 0),
                    'sesiones_realizadas' => (int) ($course->sesiones_realizadas ?? 0),
                    'survey_response_count' => (int) ($course->survey_response_count ?? 0),
                ];
            }, $rows);

            return response()->json($data);
        } catch (\Throwable $e) {
            $correlationId = (string) \Illuminate\Support\Str::uuid();
            Log::error('Error en listar cursos para encuestas', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error interno',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }

    public function listarAlumnosCurso($cursoEdicionId)
    {
        try {

            $solicitanteCorreo = (string) request()->header('X-USER-EMAIL', '');
            $rows = $this->service->listarAlumnosCurso((int) $cursoEdicionId, $solicitanteCorreo);

            $data = array_map(function ($r) {
                $estadoSolicitud = strtoupper(trim((string) ($r->solicitud_contacto_estado ?? '')));
                $contactoPublico = (int) ($r->contacto_publico ?? 0);
                $contactStatus = 'private';
                $contactStatusLabel = 'Contacto privado';

                if ($contactoPublico === 1 || $estadoSolicitud === 'ACEPTADA') {
                    $contactStatus = 'available';
                    $contactStatusLabel = 'Contacto disponible';
                } elseif ($estadoSolicitud === 'PENDIENTE') {
                    $contactStatus = 'pending';
                    $contactStatusLabel = 'Solicitud enviada';
                }

                return [
                    'id' => (int) $r->id,
                    'nombres' => $r->NOMBRES,
                    'apellidos' => $r->APELLIDOS,
                    'alumno' => $r->alumno,
                    'correo_personal' => $r->CORREO_PERSONAL,
                    'correo_corporativo' => $r->correo_corporativo ?? $r->CORREO_CORPORATIVO ?? null,
                    'telefono' => $r->TELEFONO,
                    'dni' => $r->DNI,
                    'estado_pago' => $r->estado_pago,
                    'foto_url' => $r->foto_url ?? null,
                    'contact_status' => $contactStatus,
                    'contact_status_label' => $contactStatusLabel,
                ];
            }, $rows);

            return response()->json($data);

        } catch (\Throwable $e) {

            $correlationId = (string) \Illuminate\Support\Str::uuid();

            Log::error('Error en listar alumnos curso', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error interno',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }
}
