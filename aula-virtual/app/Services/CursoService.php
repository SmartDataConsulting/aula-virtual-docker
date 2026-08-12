<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;
use App\Support\PerformanceCache;
use Illuminate\Support\Facades\Log;

class CursoService
{
    public function __construct(private readonly ApiServiciosClient $client)
    {
    }

    /**
     * Lista cursos (alumno / profesor según X-USER-ROL)
     */
    public function listarCursos(string $correo): ServiceResult
    {
        $role = (string) session(AuthSessionKeys::USER_ROLE, 'guest');
        $cacheKey = PerformanceCache::courseListKey('main', $role, $correo);

        return PerformanceCache::remember($cacheKey, PerformanceCache::COURSE_LIST_TTL, function () use ($correo, $role) {
            return $this->listarCursosFresh($correo, $role);
        });
    }

    private function listarCursosFresh(string $correo, string $role): ServiceResult
    {
        $isStudent = in_array($role, ['alumno', 'student'], true);
        $result = $isStudent
            ? $this->client->resumenAlumno($correo)
            : $this->client->resumenBackoffice($correo, $role);

        if (!$result->ok()) {
            $includeSuggestions = $isStudent;
            $result = $this->client->listarCursos($correo, $includeSuggestions);
        }

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $items = $this->extractCourseItems($result->data());

        $courses = collect($items)
            ->values()
            ->map(fn ($item, $index) => $this->normalizeCourse($item, $index + 1, $isStudent));

        $groups = [
            'activos' => $courses->where('tab', 'activos')->values(),
            'programados' => $courses->where('tab', 'programados')->values(),
            'finalizados' => $courses->where('tab', 'finalizados')->values(),
            'completados' => $courses->where('tab', 'completados')->values(),
            'sugeridos' => $courses->where('tab', 'sugeridos')->values(),
        ];

        $counts = [
            'activos' => $groups['activos']->count(),
            'programados' => $groups['programados']->count(),
            'finalizados' => $groups['finalizados']->count(),
            'completados' => $groups['completados']->count(),
            'sugeridos' => $groups['sugeridos']->count(),
            'pendientes' => $courses->whereIn('tab', ['activos', 'completados'])
                ->sum(fn ($course) => (int) ($course['pending_items_count'] ?? 0)),
        ];

        return ServiceResult::success([
            'courses' => $courses,
            'groups' => $groups,
            'counts' => $counts,
        ]);
    }

    public function listarParticipantesCurso(int $cursoEdicionId, mixed $usuarioAutenticadoId = null, string $correoAutenticado = ''): ServiceResult
    {
        $result = $this->client->listarAlumnosCurso($cursoEdicionId);

        if (!$result->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudieron cargar los participantes. Intenta nuevamente.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        $items = $this->extractAlumnoItems($result->data());
        $usuarioAutenticadoId = $usuarioAutenticadoId !== null ? (string) $usuarioAutenticadoId : '';
        $correoAutenticado = mb_strtolower(trim($correoAutenticado));

        $participantes = collect($items)
            ->filter(function ($alumno) use ($usuarioAutenticadoId, $correoAutenticado) {
                $data = is_array($alumno) ? $alumno : (array) $alumno;
                $alumnoId = (string) ($data['id'] ?? $data['alumno_id'] ?? $data['usuario_id'] ?? '');
                $correos = collect([
                    $data['correo_personal'] ?? null,
                    $data['correo_corporativo'] ?? null,
                    $data['email'] ?? null,
                    $data['correo'] ?? null,
                ])
                    ->filter()
                    ->map(fn ($correo) => mb_strtolower(trim((string) $correo)));

                if ($usuarioAutenticadoId !== '' && $alumnoId !== '' && $alumnoId === $usuarioAutenticadoId) {
                    return false;
                }

                if ($correoAutenticado !== '' && $correos->contains($correoAutenticado)) {
                    return false;
                }

                return true;
            })
            ->map(function ($alumno) {
                $data = is_array($alumno) ? $alumno : (array) $alumno;
                $nombre = trim((string) (
                    $data['alumno']
                    ?? trim((string) ($data['nombres'] ?? '') . ' ' . (string) ($data['apellidos'] ?? ''))
                ));

                return [
                    'id' => $data['id'] ?? $data['alumno_id'] ?? $data['usuario_id'] ?? null,
                    'nombre' => $nombre !== '' ? $nombre : 'Participante',
                    'correo' => mb_strtolower(trim((string) (
                        $data['correo_personal']
                        ?? $data['correo']
                        ?? $data['email']
                        ?? $data['correo_corporativo']
                        ?? ''
                    ))),
                    'contact_status' => $this->normalizeParticipantContactStatus($data['contact_status'] ?? null),
                    'contact_status_label' => $this->participantContactStatusLabel($data['contact_status'] ?? null),
                    'foto_url' => trim((string) ($data['foto_url'] ?? '')),
                ];
            })
            ->values()
            ->all();

        return ServiceResult::success([
            'participants' => $participantes,
            'total' => count($participantes),
        ]);
    }

    public function obtenerPerfilPublicoParticipante(int $cursoEdicionId, string $correo, string $correoAutenticado = ''): ServiceResult
    {
        $correo = mb_strtolower(trim($correo));
        $correoAutenticado = mb_strtolower(trim($correoAutenticado));

        if ($cursoEdicionId <= 0 || $correo === '') {
            return ServiceResult::failure([
                'message' => 'No se pudo identificar el participante.',
            ], 422);
        }

        Log::info('Community participant profile click: before API call', [
            'curso_edicion_id' => $cursoEdicionId,
            'participante_correo' => $correo,
            'solicitante_correo' => $correoAutenticado,
        ]);

        $result = $this->client->obtenerPerfilPublicoAlumno($correo, $cursoEdicionId, $correoAutenticado);

        Log::info('Community participant profile click: after API call', [
            'curso_edicion_id' => $cursoEdicionId,
            'participante_correo' => $correo,
            'solicitante_correo' => $correoAutenticado,
            'ok' => $result->ok(),
            'status' => $result->status(),
        ]);

        if (!$result->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudo cargar el perfil del participante.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        $profile = $this->normalizePublicParticipantProfile($result->data(), $correo);
        $profile['es_propio'] = $correoAutenticado !== '' && $profile['correo'] === $correoAutenticado;
        $profile['solicitud_contacto_estado'] = null;

        if (!$profile['es_propio'] && $correoAutenticado !== '') {
            $profile['solicitud_contacto_estado'] = $this->resolveSolicitudContactoEstado(
                $cursoEdicionId,
                $correoAutenticado,
                $profile['correo']
            );
        }

        return ServiceResult::success([
            'participant' => $profile,
        ], $result->status() ?: 200);
    }

    public function descargarCvPerfilPublicoParticipante(int $cursoEdicionId, string $correo, string $correoAutenticado = ''): ServiceResult
    {
        $profileResult = $this->obtenerPerfilPublicoParticipante($cursoEdicionId, $correo, $correoAutenticado);

        if (!$profileResult->ok()) {
            return ServiceResult::failure($profileResult->error(), $profileResult->status());
        }

        $profile = $profileResult->data()['participant'] ?? [];
        $cvUrl = trim((string) ($profile['cv_url'] ?? ''));

        if ($cvUrl === '') {
            return ServiceResult::failure([
                'message' => 'Este participante no tiene CV registrado.',
            ], 404);
        }

        $download = $this->client->descargarAdjuntoPerfilAlumno($correo, 'cv');

        if (!$download->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudo descargar el CV del participante.',
                'api_error' => $download->error(),
            ], $download->status());
        }

        return ServiceResult::success([
            'response' => $download->data(),
            'filename' => basename($cvUrl) ?: 'cv.pdf',
        ], 200);
    }

    public function descargarFotoPerfilPublicoParticipante(int $cursoEdicionId, string $correo, string $correoAutenticado = ''): ServiceResult
    {
        $correo = mb_strtolower(trim($correo));

        if ($cursoEdicionId <= 0 || $correo === '') {
            return ServiceResult::failure([
                'message' => 'No se pudo identificar la foto del participante.',
            ], 422);
        }

        $download = $this->client->descargarAdjuntoPerfilAlumno($correo, 'foto');

        if (!$download->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudo descargar la foto del participante.',
                'api_error' => $download->error(),
            ], $download->status());
        }

        return ServiceResult::success([
            'response' => $download->data(),
            'filename' => 'foto.jpg',
        ], 200);
    }

    private function extractCourseItems(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['courses', 'cursos', 'data', 'items'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [];
    }

    private function extractAlumnoItems(mixed $payload): array
    {
        $data = is_array($payload) ? $payload : [];

        foreach (['alumnos', 'participants', 'participantes', 'data', 'items'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return array_is_list($data) ? $data : [];
    }

    private function normalizePublicParticipantProfile(mixed $payload, string $fallbackCorreo): array
    {
        $data = is_array($payload) ? $payload : [];
        $profile = data_get($data, 'participante')
            ?? data_get($data, 'participant')
            ?? data_get($data, 'alumno')
            ?? data_get($data, 'data')
            ?? $data;

        $profile = is_array($profile) ? $profile : (array) $profile;
        $contact = is_array($profile['contacto'] ?? null) ? $profile['contacto'] : [];
        $nombres = trim((string) ($profile['nombres'] ?? ''));
        $apellidos = trim((string) ($profile['apellidos'] ?? ''));
        $nombreCompleto = trim((string) ($profile['nombre_completo'] ?? trim($nombres.' '.$apellidos)));
        $contactoPublico = (int) ($profile['contacto_publico'] ?? 0);
        $puedeVerContacto = (bool) ($profile['puede_ver_contacto'] ?? ($contactoPublico === 1));

        return [
            'correo' => mb_strtolower(trim((string) ($profile['correo'] ?? $contact['correo'] ?? $fallbackCorreo))),
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'nombre_completo' => $nombreCompleto !== '' ? $nombreCompleto : 'Participante',
            'iniciales' => trim((string) ($profile['iniciales'] ?? '')),
            'foto_url' => $profile['foto_url'] ?? null,
            'presentacion_profesional' => trim((string) ($profile['presentacion_profesional'] ?? '')),
            'cv_url' => $profile['cv_url'] ?? null,
            'contacto_publico' => $contactoPublico,
            'puede_ver_contacto' => $puedeVerContacto,
            'contacto' => $puedeVerContacto ? [
                'correo' => mb_strtolower(trim((string) ($contact['correo'] ?? $profile['correo'] ?? $fallbackCorreo))),
                'correo_corporativo' => trim((string) ($contact['correo_corporativo'] ?? $profile['correo_corporativo'] ?? '')),
                'telefono' => trim((string) ($contact['telefono'] ?? $profile['telefono'] ?? '')),
                'linkedin_url' => trim((string) ($contact['linkedin_url'] ?? $profile['linkedin_url'] ?? '')),
            ] : null,
        ];
    }

    private function resolveSolicitudContactoEstado(int $cursoEdicionId, string $solicitanteCorreo, string $destinatarioCorreo): ?string
    {
        $result = $this->client->consultarSolicitudesContactoAlumno($solicitanteCorreo, 'ENVIADAS');

        if (!$result->ok()) {
            return null;
        }

        $payload = is_array($result->data()) ? $result->data() : [];
        $solicitudes = is_array($payload['solicitudes'] ?? null) ? $payload['solicitudes'] : [];

        foreach ($solicitudes as $solicitud) {
            $sameCourse = (string) ($solicitud['curso_edicion_id'] ?? '') === (string) $cursoEdicionId;
            $sameRecipient = strtolower(trim((string) ($solicitud['destinatario_correo'] ?? ''))) === strtolower(trim($destinatarioCorreo));
            $estado = strtoupper(trim((string) ($solicitud['estado'] ?? '')));

            if ($sameCourse && $sameRecipient && in_array($estado, ['PENDIENTE', 'ACEPTADA', 'RECHAZADA'], true)) {
                return $estado;
            }
        }

        return null;
    }

    private function normalizeParticipantContactStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, ['available', 'private', 'pending'], true)
            ? $status
            : 'private';
    }

    private function participantContactStatusLabel(mixed $status): string
    {
        return match ($this->normalizeParticipantContactStatus($status)) {
            'available' => 'Contacto disponible',
            'pending' => 'Solicitud enviada',
            default => 'Contacto privado',
        };
    }

    private function normalizeCourse(mixed $item, int $fallbackId, bool $isStudent = false): array
    {
        $data = is_array($item) ? $item : [];

        $total = (int) ($data['total_sesiones'] ?? 0);
        $done = (int) ($data['sesiones_realizadas'] ?? 0);

        $progress = $total > 0 ? round(($done / $total) * 100, 1) : 0;

        $isSuggestion = filter_var($data['sugerido'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $estado = strtolower(trim((string) ($data['estadocurso'] ?? $data['estado'] ?? 'activo')));

        $tab = match (true) {
            $isSuggestion || $estado === 'sugerido' => 'sugeridos',
            in_array($estado, ['programado', 'programada', 'scheduled'], true) => $isStudent ? 'activos' : 'programados',
            in_array($estado, ['finalizado', 'finalizada', 'completado', 'completada', 'completed'], true) => $isStudent ? 'completados' : 'finalizados',
            default => 'activos',
        };

        $pendingItems = (int) ($data['pending_items_count'] ?? $data['pending_count'] ?? 0);
        if ($tab === 'completados' || $tab === 'sugeridos') {
            $pendingItems = 0;
        }
        $nextStep = $this->studentCourseNextStep($tab, $pendingItems, $progress, $isSuggestion);

        $result = [
            'id' => $data['id'] ?? $fallbackId,
            'title' => $data['nombre'] ?? 'Curso',
            'edition' => $data['edicion'] ?? null,
            'teacher' => $data['docente'] ?? 'Docente',
            'schedule' => $data['horario'] ?? null,
            'schedule_label' => $this->formatScheduleLabel($data['horario'] ?? null),
            'image' => $data['imagen'] ?? null,
            'students_count' => (int) ($data['alumnos_inscritos'] ?? $data['students_count'] ?? 0),

            'total_sessions' => $total,
            'sessions_done' => $done,
            'progress_label' => $total > 0 ? "{$done} de {$total}" : '0 de 0',
            'progress_percent' => $progress,
            'pending_items_count' => $pendingItems,
            'is_suggestion' => $isSuggestion,
            'suggestion_reason' => $data['suggestion_reason'] ?? null,
            'next_step_label' => $nextStep['label'],
            'next_step_description' => $nextStep['description'],
            'cta_label' => $nextStep['cta'],

            // métricas profesor (opcionales)
            'sesiones_hoy_sin_material' => (int) ($data['sesiones_hoy_sin_material'] ?? 0),
            'sesiones_pasadas_sin_material' => (int) ($data['sesiones_pasadas_sin_material'] ?? 0),
            'total_evaluaciones' => (int) ($data['total_evaluaciones'] ?? 0),

            'tab' => $tab,
        ];

        return $result;
    }

    private function studentCourseNextStep(string $tab, int $pendingItems, float $progress, bool $isSuggestion): array
    {
        if ($isSuggestion || $tab === 'sugeridos') {
            return [
                'label' => 'Curso recomendado',
                'description' => 'Revisa si encaja con tu ruta de aprendizaje.',
                'cta' => 'Ver informacion',
            ];
        }

        if ($pendingItems > 0) {
            return [
                'label' => $pendingItems === 1 ? 'Tienes 1 pendiente' : "Tienes {$pendingItems} pendientes",
                'description' => 'Continua desde las actividades disponibles.',
                'cta' => 'Continuar curso',
            ];
        }

        if ($tab === 'completados' || $progress >= 100) {
            return [
                'label' => 'Curso completado',
                'description' => 'Puedes revisar el contenido cuando lo necesites.',
                'cta' => 'Ver curso',
            ];
        }

        return [
            'label' => 'Continuar curso',
            'description' => 'Retoma la siguiente sesion o revisa tus materiales.',
            'cta' => 'Continuar curso',
        ];
    }

     public function listarAnunciosCurso(int $courseId): ServiceResult
    {
        // Consulta anuncios del curso.
        $result = $this->client->listarAnunciosCurso($courseId);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $items = is_array($result->data()) ? $result->data() : [];

        $anuncios = collect($items)
            ->values()
            ->map(function ($item) {
                return [
                    'id' => (int) ($item['id'] ?? 0),
                    'titulo' => (string) ($item['titulo'] ?? ''),
                    'contenido' => (string) ($item['contenido'] ?? ''),
                    'tipo' => (string) ($item['tipo'] ?? 'info'),
                    'creado_por' => (int) ($item['creado_por'] ?? 0),
                    'creado_en' => (string) ($item['creado_en'] ?? ''),
                    'actualizado_en' => (string) ($item['actualizado_en'] ?? ''),
                ];
            });

        return ServiceResult::success([
            'anuncios' => $anuncios,
        ]);
    }
    
    public function listarAnunciosCursoConLectura(int $courseId, string $correo): ServiceResult
    {
        // Consulta anuncios del curso con estado de lectura.
        $result = $this->client->listarAnunciosCursoConLectura($courseId, $correo);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $items = is_array($result->data()) ? $result->data() : [];

        $anuncios = collect($items)
            ->values()
            ->map(function ($item) {
                return [
                    'id'             => (int) ($item['id'] ?? 0),
                    'titulo'         => (string) ($item['titulo'] ?? ''),
                    'contenido'      => (string) ($item['contenido'] ?? ''),
                    'tipo'           => (string) ($item['tipo'] ?? 'info'),
                    'creado_por'     => (int) ($item['creado_por'] ?? 0),
                    'creado_en'      => (string) ($item['creado_en'] ?? ''),
                    'actualizado_en' => (string) ($item['actualizado_en'] ?? ''),
                    'leido'          => (int) ($item['leido'] ?? 0),
                ];
            });

        return ServiceResult::success([
            'anuncios' => $anuncios,
        ]);
    }

    public function obtenerDetalleCursoAlumno(int $courseId): ?array
    {
        // 👉 llama al método limpio
        $curso = $this->obtener($courseId);

        if (!$curso) {
            return null;
        }

        // 👉 lógica adicional (encuesta)
        $mostrarEncuesta = $this->debeMostrarEncuestaFinal(
            $curso['id'],
            $curso['fechafin']
        );

        return [
            ...$curso,
            'mostrar_encuesta_final' => $mostrarEncuesta
        ];
    }

    public function obtener(int $courseId): ?array
    {
        $result = $this->client->obtenerCurso($courseId);

        if (!$result->ok()) {
            return null;
        }

        $data = is_array($result->data()) ? $result->data() : [];

        if (empty($data)) {
            return null;
        }

        return [
            'id' => (int) $data['id'],
            'curso' => (string) $data['curso'],
            'edicion' => (string) $data['edicion'],
            'docente' => (string) $data['docente'],
            'horario' => (string) $data['horario'],
            'fechainicio' => (string) $data['fechainicio'],
            'fechafin' => (string) $data['fechafin'],
            'estado' => (string) $data['estado'], // ✅ correcto
            'imagen' => (string) $data['imagen'],
            'numero_sesiones' => (int) $data['numero_sesiones'],
            'horasacademicas' => (int) $data['horasacademicas'],
        ];
    }  

    public function debeMostrarEncuestaFinal(int $cursoId, string $fechaFin): bool
    {
        Log::info('SHOW_SURVEY', [
            'cursoId' => $cursoId,
            'fechaFin' => $fechaFin
        ]);

        // 1. Si el curso NO ha terminado → no mostrar
        if (now()->lt($fechaFin)) {
            return false;
        }

        // 2. Verificar si ya respondió la encuesta final
        $correo = session(AuthSessionKeys::USER_EMAIL);

        if (!$correo) {
            return false;
        }

        // ⚠️ Este endpoint debe ser por CURSO (no por sesión)
        $result = $this->client->verificarEncuestaRespondida(null, $cursoId, $correo);

        if (!$result->ok()) {
            return false; // fallback seguro
        }

        $yaRespondio = (bool) ($result->data()['respondio'] ?? false);

        // 3. Mostrar solo si NO ha respondido
        return !$yaRespondio;
    }

    /**
 * Lista cursos para gestión de evaluaciones (admin / operador)
 */
public function listarCursosParaEvaluaciones(): ServiceResult
{
    $role = (string) session(AuthSessionKeys::USER_ROLE, 'guest');
    $email = (string) session(AuthSessionKeys::USER_EMAIL, '');
    $cacheKey = PerformanceCache::courseListKey('evaluations', $role, $email);

    return PerformanceCache::remember($cacheKey, PerformanceCache::COURSE_LIST_TTL, function () {
        return $this->listarCursosParaEvaluacionesFresh();
    });
}

private function listarCursosParaEvaluacionesFresh(): ServiceResult
{
    $result = $this->client->listarCursosParaEvaluaciones();

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    $items = is_array($result->data())
        ? $result->data()
        : [];

    $cursos = collect($items)
        ->values()
        ->map(function ($item) {

            return [
                'curso_id' => (int) ($item['curso_id'] ?? 0),
                'edicion' => (string) ($item['edicion'] ?? ''),
                'nombre' => (string) ($item['nombre'] ?? ''),
                'docente' => (string) ($item['docente'] ?? ''),
                'horario' => (string) ($item['horario'] ?? ''),
                'schedule_label' => $this->formatScheduleLabel($item['horario'] ?? null),
                'alumnos_inscritos' => (int) ($item['alumnos_inscritos'] ?? 0),
                'nro_evaluaciones' => (int) ($item['nro_evaluaciones'] ?? 0),
                'evaluaciones_publicadas' => (int) ($item['evaluaciones_publicadas'] ?? 0),
                'evaluaciones_borrador' => (int) ($item['evaluaciones_borrador'] ?? 0),
            ];
        });

    return ServiceResult::success([
        'cursos' => $cursos
    ]);
}

public function listarCursosParaCalificaciones(): ServiceResult
{
    $role = (string) session(AuthSessionKeys::USER_ROLE, 'guest');
    $email = (string) session(AuthSessionKeys::USER_EMAIL, '');
    $cacheKey = PerformanceCache::courseListKey('qualifications', $role, $email);

    return PerformanceCache::remember($cacheKey, PerformanceCache::COURSE_LIST_TTL, function () {
        return $this->listarCursosParaCalificacionesFresh();
    });
}

private function listarCursosParaCalificacionesFresh(): ServiceResult
{
    $result = $this->client->listarCursosParaCalificaciones();

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    $items = is_array($result->data())
        ? $result->data()
        : [];

    $cursos = collect($items)
        ->values()
        ->map(function ($item) {
            $total = (int) ($item['total_sesiones'] ?? 0);
            $done = (int) ($item['sesiones_realizadas'] ?? 0);
            $progress = $total > 0 ? round(($done / $total) * 100, 1) : 0;

            return [
                'id' => (int) ($item['id'] ?? 0),
                'code' => (string) ($item['codigo'] ?? ''),
                'title' => (string) ($item['nombre'] ?? 'Curso'),
                'edition' => $this->normalizeEdition($item['edicion'] ?? null, $item['codigo'] ?? null),
                'teacher' => (string) ($item['docente'] ?? 'Docente'),
                'schedule' => (string) ($item['horario'] ?? ''),
                'schedule_label' => $this->formatScheduleLabel($item['horario'] ?? null),
                'image' => $item['imagen'] ?? null,
                'students_count' => (int) ($item['alumnos_inscritos'] ?? 0),
                'total_sessions' => $total,
                'sessions_done' => $done,
                'progress_label' => $total > 0 ? "{$done} de {$total}" : '0 de 0',
                'progress_percent' => $progress,
                'exam_count' => (int) ($item['exam_count'] ?? 0),
                'work_count' => (int) ($item['work_count'] ?? 0),
                'survey_response_count' => (int) ($item['survey_response_count'] ?? 0),
            ];
        });

    return ServiceResult::success([
        'cursos' => $cursos,
    ]);
}

public function listarCursosParaEncuestas(): ServiceResult
{
    $role = (string) session(AuthSessionKeys::USER_ROLE, 'guest');
    $email = (string) session(AuthSessionKeys::USER_EMAIL, '');
    $cacheKey = PerformanceCache::courseListKey('surveys', $role, $email);

    return PerformanceCache::remember($cacheKey, PerformanceCache::COURSE_LIST_TTL, function () {
        $result = $this->client->listarCursosParaEncuestas();

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $courses = collect(is_array($result->data()) ? $result->data() : [])
            ->values()
            ->map(function (array $item) {
                $total = (int) ($item['total_sesiones'] ?? 0);
                $done = (int) ($item['sesiones_realizadas'] ?? 0);

                return [
                    'id' => (int) ($item['id'] ?? 0),
                    'code' => (string) ($item['codigo'] ?? ''),
                    'title' => (string) ($item['nombre'] ?? 'Curso'),
                    'edition' => $this->normalizeEdition($item['edicion'] ?? null, $item['codigo'] ?? null),
                    'teacher' => (string) ($item['docente'] ?? ''),
                    'schedule' => (string) ($item['horario'] ?? ''),
                    'schedule_label' => $this->formatScheduleLabel($item['horario'] ?? null),
                    'state' => (string) ($item['estado'] ?? ''),
                    'students_count' => (int) ($item['alumnos_inscritos'] ?? 0),
                    'total_sessions' => $total,
                    'sessions_done' => $done,
                    'progress_label' => $total > 0 ? "{$done} de {$total}" : '0 de 0',
                    'progress_percent' => $total > 0 ? round(($done / $total) * 100, 1) : 0,
                    'survey_response_count' => (int) ($item['survey_response_count'] ?? 0),
                ];
            });

        return ServiceResult::success(['cursos' => $courses]);
    });
}

public function listarCursosParaCertificados(): ServiceResult
{
    $role = (string) session(AuthSessionKeys::USER_ROLE, 'guest');
    $email = (string) session(AuthSessionKeys::USER_EMAIL, '');
    $cacheKey = PerformanceCache::courseListKey('certificates', $role, $email);

    return PerformanceCache::remember($cacheKey, PerformanceCache::COURSE_LIST_TTL, function () {
        return $this->listarCursosParaCertificadosFresh();
    });
}

private function listarCursosParaCertificadosFresh(): ServiceResult
{
    $result = $this->client->listarCursosParaCalificaciones();

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    $items = is_array($result->data())
        ? $result->data()
        : [];

    $cursos = collect($items)
        ->values()
        ->map(function ($item) {
            $total = (int) ($item['total_sesiones'] ?? 0);
            $done = (int) ($item['sesiones_realizadas'] ?? 0);
            $progress = $total > 0 ? round(($done / $total) * 100, 1) : 0;

            return [
                'id' => (int) ($item['id'] ?? 0),
                'curso_id' => (int) ($item['curso_id'] ?? 0),
                'code' => (string) ($item['codigo'] ?? ''),
                'edition' => $this->normalizeEdition($item['edicion'] ?? null, $item['codigo'] ?? null),
                'title' => (string) ($item['nombre'] ?? 'Curso'),
                'teacher' => (string) ($item['docente'] ?? 'Docente'),
                'schedule' => (string) ($item['horario'] ?? ''),
                'schedule_label' => $this->formatScheduleLabel($item['horario'] ?? null),
                'image' => $item['imagen'] ?? null,
                'students_count' => (int) ($item['alumnos_inscritos'] ?? 0),

                'total_sessions' => $total,
                'sessions_done' => $done,
                'progress_label' => $total > 0 ? "{$done} de {$total}" : '0 de 0',
                'progress_percent' => $progress,

                'certificates_total' => (int) ($item['certificados_total'] ?? 0),
                'certificates_pending' => (int) ($item['certificados_pendientes'] ?? 0),
                'certificates_attached' => (int) ($item['certificados_adjuntados'] ?? 0),
                'certificates_sent' => (int) ($item['certificados_enviados'] ?? 0),
            ];
        });

    return ServiceResult::success([
        'cursos' => $cursos,
    ]);
}

public function obtenerCertificadosPorCurso(int $courseId): ServiceResult
{
    $result = $this->client->obtenerCertificadosPorCurso($courseId);

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    $payload = is_array($result->data()) ? $result->data() : [];
    $course = $payload['curso'] ?? [];
    $summary = $payload['resumen'] ?? [];
    $students = $payload['alumnos'] ?? [];

    return ServiceResult::success([
        'course' => [
            'id' => (int) ($course['curso_edicion_id'] ?? $course['id'] ?? $courseId),
            'title' => (string) ($course['nombre'] ?? $course['curso'] ?? 'Curso'),
            'teacher' => (string) ($course['docente'] ?? ''),
            'schedule' => (string) ($course['horario'] ?? ''),
            'schedule_label' => $this->formatScheduleLabel($course['horario'] ?? null),
        ],
        'summary' => [
            'total' => (int) ($summary['total_certificados'] ?? 0),
            'generated' => (int) ($summary['diplomas_generados'] ?? $summary['certificados_adjuntados'] ?? 0),
            'sent' => (int) ($summary['certificados_enviados'] ?? 0),
            'pending' => (int) ($summary['certificados_pendientes'] ?? 0),
            'without_diploma' => (int) ($summary['sin_diploma'] ?? 0),
            'requires_review' => (int) ($summary['requieren_revision'] ?? 0),
            'sga_available' => (bool) ($summary['sga_disponible'] ?? true),
            'sga_message' => $summary['sga_message'] ?? null,
            'sga_config' => $summary['sga_config'] ?? [],
            'sga_detected' => (int) ($summary['sga_diplomas_detectados'] ?? 0),
            'sga_unidentified' => (int) ($summary['sga_diplomas_sin_identificar'] ?? 0),
        ],
        'students' => collect($students)->map(fn ($student) => $this->mapCertificateStudent((array) $student))->values(),
    ]);
}

public function obtenerCertificadoAlumnoCurso(int $courseId): ServiceResult
{
    $result = $this->client->obtenerCertificadoAlumnoCurso($courseId);

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    $certificate = (array) ($result->data()['certificado'] ?? []);

    return ServiceResult::success([
        'certificate' => [
            'status' => (string) ($certificate['status'] ?? 'no_disponible'),
            'label' => (string) ($certificate['label'] ?? 'No disponible'),
            'code' => $certificate['code'] ?? null,
            'sent_at' => $certificate['sent_at'] ?? null,
            'public_url' => $this->normalizeCertificatePublicLink($certificate['public_url'] ?? null),
            'download_url' => $this->normalizeCertificatePublicLink($certificate['download_url'] ?? $certificate['public_url'] ?? null),
            'preview_url' => $this->normalizeCertificatePublicLink($certificate['preview_url'] ?? $certificate['public_url'] ?? null),
            'message' => (string) ($certificate['message'] ?? 'Consulta el estado de tu certificado.'),
        ],
    ]);
}

public function adjuntarCertificado(array $data): ServiceResult
{
    $result = $this->client->adjuntarCertificado($data);

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    return ServiceResult::success([
        'certificate' => $this->mapCertificate((array) (($result->data()['certificado'] ?? []))),
    ]);
}

public function enviarCertificado(int $certificadoId, array $data): ServiceResult
{
    $result = $this->client->enviarCertificado($certificadoId, $data);

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    return ServiceResult::success([
        'certificate' => $this->mapCertificate((array) (($result->data()['certificado'] ?? []))),
    ]);
}

public function sincronizarDiplomasSgaCurso(int $courseId, array $data): ServiceResult
{
    $result = $this->client->sincronizarDiplomasSgaCurso($courseId, $data);

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    return ServiceResult::success($result->data());
}

private function mapCertificateStudent(array $student): array
{
    return [
        'certificate_id' => $student['certificado_id'] ?? null,
        'source' => $student['source'] ?? null,
        'diploma' => $student['diploma'] ?? null,
        'diploma_id' => $student['diploma']['diploma_id'] ?? null,
        'diploma_code' => $student['diploma']['code'] ?? null,
        'diploma_type' => $student['diploma']['type'] ?? null,
        'diploma_image_url' => $student['diploma']['image_url'] ?? null,
        'diploma_file_url' => $student['diploma']['file_url'] ?? null,
        'email' => (string) ($student['alumno_correo'] ?? ''),
        'names' => (string) ($student['nombres'] ?? ''),
        'last_names' => (string) ($student['apellidos'] ?? ''),
        'full_name' => (string) ($student['nombre_completo'] ?? trim(($student['nombres'] ?? '') . ' ' . ($student['apellidos'] ?? ''))),
        'status' => (string) ($student['estado'] ?? 'pendiente'),
        'file_name' => $student['archivo_nombre'] ?? null,
        'public_link' => $this->normalizeCertificatePublicLink($student['link_publico'] ?? null),
        'attached_at' => $student['fecha_adjunta'] ?? null,
        'attached_by' => $student['usuario_adjunta'] ?? null,
        'sent_at' => $student['fecha_envia'] ?? null,
        'sent_by' => $student['usuario_envia'] ?? null,
    ];
}

private function mapCertificate(array $certificate): array
{
    return [
        'certificate_id' => $certificate['certificado_id'] ?? $certificate['id'] ?? null,
        'email' => (string) ($certificate['alumno_correo'] ?? ''),
        'status' => (string) ($certificate['estado'] ?? 'pendiente'),
        'file_name' => $certificate['archivo_nombre'] ?? null,
        'public_link' => $this->normalizeCertificatePublicLink($certificate['link_publico'] ?? null),
        'attached_at' => $certificate['fecha_adjunta'] ?? null,
        'attached_by' => $certificate['usuario_adjunta'] ?? null,
        'sent_at' => $certificate['fecha_envia'] ?? null,
        'sent_by' => $certificate['usuario_envia'] ?? null,
    ];
}

private function normalizeCertificatePublicLink(mixed $link): ?string
{
    $link = trim((string) ($link ?? ''));

    if ($link === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $link)) {
        return $link;
    }

    $path = ltrim($link, '/');
    $baseUrl = rtrim((string) config('services.certificates.public_base_url', ''), '/');

    if ($baseUrl !== '') {
        $token = str_starts_with($path, 'Certificados/')
            ? substr($path, strlen('Certificados/'))
            : $path;

        return $baseUrl . '/' . ltrim($token, '/');
    }

    if (str_starts_with($path, 'Certificados/')) {
        return url('/'.$path);
    }

    return url('/Certificados/'.$path);
}

private function normalizeEdition(mixed $edition, mixed $code = null): string
{
    $value = trim((string) ($edition ?? ''));

    if ($value === '') {
        $value = trim((string) ($code ?? ''));
    }

    if ($value === '') {
        return '';
    }

    return trim((string) preg_replace('/^edici[oó]n\s*/iu', '', $value));
}

private function formatScheduleLabel(mixed $schedule): string
{
    $schedule = trim((string) ($schedule ?? ''));

    if ($schedule === '') {
        return '';
    }

    $parts = preg_split('/\s*[·•]\s*/u', $schedule) ?: [$schedule];
    $formatted = collect($parts)
        ->map(fn ($part) => $this->formatSchedulePart((string) $part))
        ->filter()
        ->values();

    return $formatted->isNotEmpty()
        ? $formatted->implode(' · ')
        : preg_replace('/\s*\([^)]*\)/', '', $schedule);
}

private function formatSchedulePart(string $part): ?string
{
    $part = trim($part);

    if ($part === '') {
        return null;
    }

    if (!preg_match('/^([A-ZÁÉÍÓÚÑ]{3})\s+(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})(?:\s*\([^)]*\))?$/iu', $part, $matches)) {
        return trim((string) preg_replace('/\s*\([^)]*\)/', '', $part));
    }

    $days = [
        'LUN' => 'Lun',
        'MAR' => 'Mar',
        'MIE' => 'Mie',
        'MIÉ' => 'Mie',
        'JUE' => 'Jue',
        'VIE' => 'Vie',
        'SAB' => 'Sab',
        'SÁB' => 'Sab',
        'DOM' => 'Dom',
    ];

    $day = $days[mb_strtoupper($matches[1], 'UTF-8')] ?? ucfirst(mb_strtolower($matches[1], 'UTF-8'));

    return sprintf(
        '%s %s - %s',
        $day,
        $this->formatTimeLabel((int) $matches[2], (int) $matches[3]),
        $this->formatTimeLabel((int) $matches[4], (int) $matches[5])
    );
}

private function formatTimeLabel(int $hour, int $minute): string
{
    $suffix = $hour >= 12 ? 'p.m.' : 'a.m.';
    $displayHour = $hour % 12;

    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return sprintf('%d:%02d %s', $displayHour, $minute, $suffix);
}

}
