<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\PerformanceCache;
use Illuminate\Support\Facades\Log;
class SesionService
{
    public function __construct(private readonly ApiServiciosClient $client)
    {
    }

    /**
     * Lista sesiones del curso (alumno / profesor según X-USER-ROL)
     */
    public function listarSesionesCurso(int $courseId, string $rol): ServiceResult
    {
        $cacheKey = 'sessions:list:' . strtolower($rol) . ':' . $courseId;

        return PerformanceCache::remember($cacheKey, PerformanceCache::DETAIL_TTL, function () use ($courseId, $rol) {
            return $this->listarSesionesCursoFresh($courseId, $rol);
        });
    }

    public function forgetCourseSessions(int $courseId, string $rol): void
    {
        PerformanceCache::forget('sessions:list:' . strtolower($rol) . ':' . $courseId);
    }

    private function listarSesionesCursoFresh(int $courseId, string $rol): ServiceResult
    {
        $result = $this->client->listarSesionesCurso($courseId, $rol);
        

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $items = $this->extractSessionItems($result->data());

        $sessions = collect($items)
            ->values()
            ->map(fn ($item, $index) => (object) $this->normalizeSession($item, $index + 1, $rol));

        return ServiceResult::success([
            'sessions' => $sessions,
        ]);
    }

    public function listarSesionesCursoLight(int $courseId, string $correo): ServiceResult
    {
        $cacheKey = 'sessions:light:' . $courseId . ':' . md5(strtolower(trim($correo)));

        return PerformanceCache::remember($cacheKey, PerformanceCache::DETAIL_TTL, function () use ($courseId, $correo) {
            return $this->listarSesionesCursoLightFresh($courseId, $correo);
        });
    }

    public function forgetStudentCourseSessions(int $courseId, string $correo): void
    {
        PerformanceCache::forget(
            'sessions:light:' . $courseId . ':' . md5(strtolower(trim($correo)))
        );
    }

    public function forgetStudentSessionDetail(int $courseId, int $sessionId, string $correo): void
    {
        PerformanceCache::forget(
            'sessions:student-detail:' . $courseId . ':' . $sessionId . ':' . md5(strtolower(trim($correo)))
        );
    }

    private function listarSesionesCursoLightFresh(int $courseId, string $correo): ServiceResult
    {
        $result = $this->client->listarSesionesCursoLight($courseId, $correo);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $items = $this->extractSessionItems($result->data());

        $sessions = collect($items)
            ->values()
            ->map(fn ($item, $index) => (object) $this->normalizeSession($item, $index + 1, 'alumno'));

        return ServiceResult::success([
            'sessions' => $sessions,
        ]);
    }

    public function obtenerDetalleSesionAlumno(int $courseId, int $sessionId, string $correo): ServiceResult
    {
        $cacheKey = 'sessions:student-detail:' . $courseId . ':' . $sessionId . ':' . md5(strtolower(trim($correo)));

        return PerformanceCache::remember($cacheKey, PerformanceCache::SHORT_TTL, function () use ($courseId, $sessionId, $correo) {
            return $this->obtenerDetalleSesionAlumnoFresh($courseId, $sessionId, $correo);
        });
    }

    private function obtenerDetalleSesionAlumnoFresh(int $courseId, int $sessionId, string $correo): ServiceResult
    {
        $result = $this->client->obtenerDetalleSesionAlumno($courseId, $sessionId, $correo);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $data = is_array($result->data()) ? $result->data() : [];
        $sessionData = $data['session'] ?? $data['sesion'] ?? [];
        $session = (object) $this->normalizeSession($sessionData, (int) ($sessionData['numero'] ?? 1), 'alumno');

        $materials = collect($data['materiales'] ?? $sessionData['materiales'] ?? $sessionData['materials'] ?? [])
            ->values()
            ->map(fn ($item, $index) => (object) $this->normalizeMaterial($item, $index + 1));

        $session->materials = $materials;
        $session->evaluaciones = $this->normalizeEvaluations(
            $data['evaluaciones'] ?? $session->evaluaciones ?? []
        );

        return ServiceResult::success([
            'session' => $session,
            'anuncioSesionNoLeido' => $this->normalizeSessionAnnouncement($data['anuncio_sesion_no_leido'] ?? []),
        ]);
    }

 private function extractSessionItems(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return [];
    }

    private function normalizeSession(mixed $item, int $fallbackNumber, string $rol): array
    {
        /* LOG 1: lo que llega
        Log::info('SESSION RAW', [
            'item' => $item,
            'fallbackNumber' => $fallbackNumber,
            'rol' => $rol
        ]);*/

        $data = is_array($item) ? $item : (array) $item;

        $base = [
            'curso_edicion_id' => $data['curso_edicion_id'] ?? $data['course_edition_id'] ?? null,
            'curso_id' => $data['curso_id'] ?? $data['course_id'] ?? null,
            'course' => $data['curso'] ?? null,
            'teacher' => $data['docente'] ?? null,
            'edition' => $data['edicion'] ?? $data['edition'] ?? $data['grupo'] ?? null,
            'course_state' => $data['estado_curso'] ?? $data['course_state'] ?? null,
            'id' => $data['id'] ?? $fallbackNumber,
            'number' => $data['numero'] ?? $fallbackNumber,
            'title' => 'Sesión '.($data['numero'] ?? $fallbackNumber),
            'date' => $data['fecha'] ?? null,
            'start_time' => $data['hora_inicio'] ?? null,
            'end_time' => $data['hora_fin'] ?? null,
            'duration' => $data['duracion'] ?? null,
            'state' => $data['estado'] ?? null,
            'video_status' => $data['video_status'] ?? null,
            'video_drive_file_id' => $data['video_drive_file_id'] ?? null,
            'video_uploaded_at' => $data['video_uploaded_at'] ?? null,
            'video_filesize' => $data['video_filesize'] ?? null,
            'video_chat_drive_file_id' => $data['video_chat_drive_file_id'] ?? null,
            'video_chat_titulo' => $data['video_chat_titulo'] ?? null,
            'video_chat_filesize' => $data['video_chat_filesize'] ?? null,
            'video_chat_uploaded_at' => $data['video_chat_uploaded_at'] ?? null,
            'materials_count' => (int) ($data['materiales_count'] ?? $data['materials_count'] ?? 0),
            'announcements_count' => (int) ($data['anuncios_count'] ?? $data['announcements_count'] ?? 0),
            'survey_id' => $data['encuesta_id'] ?? null,
            'survey_answered' => (bool) ($data['encuesta_respondida'] ?? false),
            'surveys' => collect($data['surveys'] ?? [])->map(fn ($survey) => (object) $survey),
            'evaluaciones' => $this->normalizeEvaluations($data['evaluaciones'] ?? []),
            'tiene_evaluacion' => (bool) ($data['tiene_evaluacion'] ?? false),
            'meeting' => $this->normalizeMeeting($data['meeting'] ?? null),
        ];

        if (in_array(strtolower($rol), ['admin', 'operador', 'docente', 'profesor'], true)) {
            $base['material_pending']   = (bool) ($data['falta_material'] ?? false);
            $base['has_evaluation'] = (bool) ($data['existe_evaluacion'] ?? false);
        }

        /* LOG 2: lo que sale
        Log::info('SESSION NORMALIZED', [
            'normalized' => $base
        ]); */

        return $base;
    }

    private function normalizeMeeting(mixed $meeting): object
    {
        $data = is_array($meeting) ? $meeting : (is_object($meeting) ? (array) $meeting : []);

        return (object) [
            'scheduled' => (bool) ($data['scheduled'] ?? false),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'availability' => $data['availability'] ?? 'unavailable',
            'can_join' => (bool) ($data['can_join'] ?? false),
            'join_url' => $data['join_url'] ?? null,
            'meeting_id' => $data['meeting_id'] ?? null,
            'access_code' => $data['access_code'] ?? null,
        ];
    }

    private function normalizeEvaluations(mixed $items): array
    {
        if (!is_array($items) && !$items instanceof \Traversable) {
            return [];
        }

        return collect($items)
            ->map(function ($item): array {
                $data = is_array($item) ? $item : (array) $item;
                $score = $data['puntaje_total']
                    ?? $data['puntaje_obtenido']
                    ?? $data['score']
                    ?? null;
                $maxScore = $data['puntaje_maximo']
                    ?? $data['puntaje_max']
                    ?? $data['max_score']
                    ?? null;
                $passScore = $data['puntaje_aprobacion']
                    ?? $data['pass_score']
                    ?? null;

                return array_merge($data, [
                    'score' => is_numeric($score) ? (float) $score : null,
                    'max_score' => is_numeric($maxScore) ? (float) $maxScore : null,
                    'pass_score' => is_numeric($passScore) ? (float) $passScore : null,
                    'approved' => array_key_exists('aprobado', $data) && $data['aprobado'] !== null
                        ? (bool) $data['aprobado']
                        : null,
                ]);
            })
            ->values()
            ->all();
    }

    private function normalizeMaterial(mixed $item, int $fallbackOrder): array
    {
        $data = is_array($item) ? $item : (array) $item;

        return [
            'id' => $data['id'] ?? $fallbackOrder,
            'session_id' => $data['curso_edicion_sesion_id'] ?? $data['session_id'] ?? null,
            'title' => $data['titulo'] ?? $data['title'] ?? 'Material',
            'description' => $data['descripcion'] ?? $data['description'] ?? null,
            'type' => $data['tipo'] ?? $data['type'] ?? null,
            'file_name' => $data['nombre_archivo'] ?? $data['file_name'] ?? null,
            'file_path' => $data['ruta_archivo'] ?? $data['file_path'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'size' => $data['tamano_bytes'] ?? $data['size'] ?? null,
            'external_url' => $data['url_externa'] ?? $data['external_url'] ?? null,
            'order' => $data['orden'] ?? $data['order'] ?? $fallbackOrder,
        ];
    }

    private function normalizeSessionAnnouncement(array $data): array
    {
        $pending = $data['pendiente'] ?? null;

        if (is_array($pending)) {
            $pending = (object) [
                'id' => $pending['id'] ?? null,
                'title' => $pending['titulo'] ?? $pending['title'] ?? '',
                'content' => $pending['contenido'] ?? $pending['content'] ?? '',
                'type' => $pending['tipo'] ?? $pending['type'] ?? 'general',
                'created_at' => $pending['creado_en'] ?? $pending['created_at'] ?? '',
                'updated_at' => $pending['actualizado_en'] ?? $pending['updated_at'] ?? '',
                'leido' => (int) ($pending['leido'] ?? 0),
                'ui' => (object) [
                    'class' => match (strtolower((string) ($pending['tipo'] ?? $pending['type'] ?? 'general'))) {
                        'importante' => 'announcement-important',
                        'informativo' => 'announcement-info',
                        default => 'announcement-general',
                    },
                ],
            ];
        }

        return [
            'existen' => (bool) ($data['existen'] ?? false),
            'pendiente' => $pending,
        ];
    }

    public function obtenerEvaluacionesSesion(
    int $courseId,
    int $sessionId
): ServiceResult
{
    $result = $this->client
        ->obtenerEvaluacionesSesion($courseId, $sessionId);

    if (!$result->ok()) {
        return ServiceResult::failure(
            $result->error(),
            $result->status()
        );
    }

    $data = $result->data();

    return ServiceResult::success([
        'asignadas' => collect($data['asignadas'] ?? [])
            ->map(fn($e) => [
                'id' => $e['id'],
                'nombre' => $e['nombre'],
                'tipo_param_id' => $e['tipo_param_id'],
                'tipo' => $e['tipo'],
                'fecha_limite' => $e['fecha_limite'] ?? null,
                'hito_nombre' => $e['hito_nombre'] ?? null,
                'hito_orden' => $e['hito_orden'] ?? null,
                'grupo_nombre' => $e['grupo_nombre'] ?? null,
                'plazo_dias' => $e['plazo_dias'] ?? null,
            ])
            ->values(),

        'disponibles' => collect($data['disponibles'] ?? [])
            ->map(fn($e) => [
                'id' => $e['id'],
                'nombre' => $e['nombre'],
                'tipo_param_id' => $e['tipo_param_id'],
                'tipo' => $e['tipo'],
                'fecha_limite' => $e['fecha_limite'] ?? null,
                'hito_nombre' => $e['hito_nombre'] ?? null,
                'hito_orden' => $e['hito_orden'] ?? null,
                'grupo_nombre' => $e['grupo_nombre'] ?? null,
                'plazo_dias' => $e['plazo_dias'] ?? null,
            ])
            ->values(),
    ]);
}

    public function agregarEvaluacionesSesion(int $sesionId, array $evaluaciones): ServiceResult
    {
        $result = $this->client
            ->agregarEvaluacionesSesion($sesionId, $evaluaciones);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success([
            'ok' => true
        ]);
    }

    public function obtenerPlanEvaluacionCurso(int $courseId): ServiceResult
    {
        $result = $this->client->obtenerPlanEvaluacionCurso($courseId);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $data = is_array($result->data()) ? $result->data() : [];

        return ServiceResult::success([
            'course_id' => $data['course_id'] ?? $courseId,
            'sessions' => collect($data['sessions'] ?? [])
                ->map(function ($session) {
                    $session = is_array($session) ? $session : (array) $session;

                    return [
                        'session_id' => (int) ($session['session_id'] ?? 0),
                        'session_number' => (int) ($session['session_number'] ?? 0),
                        'date' => $session['date'] ?? null,
                        'start_time' => $session['start_time'] ?? null,
                        'end_time' => $session['end_time'] ?? null,
                        'status' => $session['status'] ?? null,
                        'has_evaluation' => (bool) ($session['has_evaluation'] ?? false),
                        'milestones' => collect($session['milestones'] ?? [])
                            ->map(fn ($milestone) => is_array($milestone) ? $milestone : (array) $milestone)
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all(),
        ]);
    }


    public function eliminarEvaluacionSesion(int $sesionId, int $evaluacionId): ServiceResult
    {
        $result = $this->client
            ->eliminarEvaluacionSesion($sesionId, $evaluacionId);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success([
            'ok' => true
        ]);
    }

    public function actualizarEvaluacionSesion(
        int $sesionId,
        int $evaluacionId,
        array $payload
    ): ServiceResult
    {
        $result = $this->client
            ->actualizarEvaluacionSesion($sesionId, $evaluacionId, $payload);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success([
            'ok' => true
        ]);
    }
}
