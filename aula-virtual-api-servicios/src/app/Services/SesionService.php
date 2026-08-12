<?php

namespace App\Services;

use Carbon\Carbon;
use App\Repositories\SesionRepository;
use App\Services\SesionMaterialService;
use App\Services\CursoAnuncioService;
use App\Services\SesionVideoService;
use Illuminate\Support\Facades\Log;

class SesionService
{
    private $sesionRepository;
    private $surveyService;
    private $materialService;
    private $anuncioService;
    private $videoService;
    private $meetingService;

    public function __construct(
        SesionRepository $sesionRepository,
        GenDocsSurveyService $surveyService,
        SesionMaterialService $materialService,
        CursoAnuncioService $anuncioService,
        SesionVideoService $videoService,
        MeetingService $meetingService
    ) {
        $this->sesionRepository = $sesionRepository;
        $this->surveyService = $surveyService;
        $this->materialService = $materialService;
        $this->anuncioService = $anuncioService;
        $this->videoService = $videoService;
        $this->meetingService = $meetingService;
    }

    public function listarPorCursoAlumno(int $cursoId, string $correo, string $role = 'alumno')
    {
        $sesiones = $this->sesionRepository
            ->listarPorCursoAlumno($cursoId);
        $sesiones = $this->meetingService->attachToSessions($sesiones, $role);

        $sesiones = $this->surveyService->attachSummaries($sesiones, $correo);

        $sesionIds = array_map(fn($s) => $s->id, $sesiones);

        $evaluaciones = $this->sesionRepository
        ->obtenerEvaluacionesPorSesiones($sesionIds, $correo);

        $mapEval = [];
        foreach ($evaluaciones as $e) {
            $mapEval[$e->sesion_id][] = $e;
        }
        
        foreach ($sesiones as $s) {
            $s->evaluaciones = $mapEval[$s->id] ?? [];
            $s->tiene_evaluacion = !empty($s->evaluaciones);
        }

        return $sesiones;
    }

    public function listarPorCursoAlumnoLight(int $cursoId, string $correo)
    {
        $sessions = $this->sesionRepository->listarPorCursoAlumnoLight($cursoId);
        return $this->surveyService->attachSummaries($sessions, $correo);
    }

    public function obtenerDetalleSesionAlumno(int $cursoId, int $sesionId, string $correo): ?array
    {
        $sesion = $this->sesionRepository->obtenerPorId($sesionId);

        if (!$sesion || (int) $sesion->curso_edicion_id !== $cursoId) {
            return null;
        }

        $video = $this->videoService->getVideoStatus($sesionId);
        $sesion = $this->sesionRepository->obtenerPorId($sesionId) ?: $sesion;
        $sesion = $this->meetingService->attachToSession($sesion, 'alumno');

        $sesion = $this->surveyService->attachSummaries([$sesion], $correo)[0];

        $sesion->evaluaciones = $this->sesionRepository
            ->obtenerEvaluacionesPorSesiones([$sesionId], $correo);
        $sesion->tiene_evaluacion = !empty($sesion->evaluaciones);

        return [
            'sesion' => $sesion,
            'materiales' => $this->materialService->listarPorSesion($sesionId),
            'anuncios' => $this->anuncioService->listarConEstadoLectura(
                'sesion',
                $sesionId,
                $correo
            ),
            'video' => $video,
        ];
    }

    public function listarPorCursoProfesor(int $cursoId, string $role = 'docente')
    {
        $sesiones = $this->sesionRepository->listarPorCursoProfesor($cursoId);
        $sesiones = $this->meetingService->attachToSessions($sesiones, $role);

        $ids = array_map(fn($s) => $s->id, $sesiones);

        $evaluaciones = $this->sesionRepository
            ->obtenerEvaluacionesPorSesiones($ids);

        // agrupar
        $map = [];
        foreach($evaluaciones as $e){
            $map[$e->sesion_id][] = $e;
        }

        // inyectar
        foreach($sesiones as $s){
            $s->evaluaciones = $map[$s->id] ?? [];
        }

        return $sesiones;
    }

    public function obtenerEvaluacionesSesion(int $cursoId, int $sesionId){
        $asignadas = $this->sesionRepository
            ->listarEvaluacionesSesion($sesionId);

        $disponibles = $this->sesionRepository
            ->listarEvaluacionesDisponibles($cursoId, $sesionId);

        return [
            'asignadas' => $asignadas,
            'disponibles' => $disponibles
        ];
    }

    public function obtenerPlanEvaluacionCurso(int $cursoId): array
    {
        $rows = $this->sesionRepository->obtenerEvaluacionPlanCurso($cursoId);
        $sessions = [];

        foreach ($rows as $row) {
            $sessionId = (int) $row->sesion_id;

            if (!isset($sessions[$sessionId])) {
                $sessions[$sessionId] = [
                    'session_id' => $sessionId,
                    'session_number' => isset($row->sesion_numero) ? (int) $row->sesion_numero : null,
                    'date' => $row->fecha ?? null,
                    'start_time' => $row->hora_inicio ?? null,
                    'end_time' => $row->hora_fin ?? null,
                    'status' => $row->estado_sesion ?? null,
                    'has_evaluation' => false,
                    'milestones' => [],
                ];
            }

            if (empty($row->evaluacion_id)) {
                continue;
            }

            $deliveries = (int) ($row->entregas_total ?? 0);
            $graded = (int) ($row->calificaciones_total ?? 0);
            $isPublished = (bool) ($row->publicada ?? false);

            $sessions[$sessionId]['has_evaluation'] = true;
            $sessions[$sessionId]['milestones'][] = [
                'course_session_evaluation_id' => (int) $row->curso_sesion_evaluacion_id,
                'evaluation_id' => (int) $row->evaluacion_id,
                'name' => $row->nombre ?? 'Evaluacion',
                'type_id' => isset($row->tipo_param_id) ? (int) $row->tipo_param_id : null,
                'type' => $row->tipo ?? null,
                'group_name' => $row->grupo_nombre ?? null,
                'milestone_name' => $row->hito_nombre ?? null,
                'milestone_order' => isset($row->hito_orden) ? (int) $row->hito_orden : null,
                'deadline' => $row->fecha_limite ?? null,
                'relative_deadline_days' => isset($row->plazo_dias) ? (int) $row->plazo_dias : null,
                'weight' => isset($row->peso) ? (float) $row->peso : null,
                'published' => $isPublished,
                'deliveries_total' => $deliveries,
                'graded_total' => $graded,
                'status' => !$isPublished
                    ? 'draft'
                    : ($deliveries > $graded ? 'pending_grading' : ($deliveries > 0 ? 'with_deliveries' : 'published')),
            ];
        }

        return [
            'course_id' => $cursoId,
            'sessions' => array_values($sessions),
        ];
    }

    public function agregarEvaluacionesSesion(int $sesionId, array $evaluacionIds): void
    {
        foreach ($evaluacionIds as $evaluacionData) {
            $evaluacionId = $this->extraerEvaluacionId($evaluacionData);

            if ($evaluacionId === null) {
                continue;
            }

            $metadata = $this->normalizarMetadataAsignacion($sesionId, $evaluacionData);

            $this->sesionRepository->addEvaluacion(
                $sesionId,
                $evaluacionId,
                $metadata['fecha_limite'] ?? null,
                $metadata['hito_nombre'] ?? null,
                $metadata['hito_orden'] ?? null,
                $metadata['grupo_nombre'] ?? null,
                $metadata['plazo_dias'] ?? null
            );
        }
    }

    public function actualizarFechaLimiteEvaluacionSesion(
        int $sesionId,
        int $evaluacionId,
        ?string $fechaLimite,
        array $metadata = []
    ): bool {
        if (!$this->sesionRepository->existsEvaluacionSesion($sesionId, $evaluacionId)) {
            return false;
        }

        if (array_key_exists('fecha_limite', $metadata) || $fechaLimite !== null) {
            $metadata['fecha_limite'] = $fechaLimite;
        }

        $metadata = $this->normalizarMetadataParcial($sesionId, $metadata);

        $this->sesionRepository->updateEvaluacionMetadata(
            $sesionId,
            $evaluacionId,
            $metadata
        );

        return true;
    }

    public function eliminarEvaluacionSesion(int $sesionId, int $evaluacionId): void
    {
        $this->sesionRepository->deleteEvaluacion(
            $sesionId,
            $evaluacionId
        );
    }

    private function extraerEvaluacionId($evaluacionData): ?int
    {
        if (is_numeric($evaluacionData)) {
            return (int) $evaluacionData;
        }

        if (is_array($evaluacionData) && isset($evaluacionData['id']) && is_numeric($evaluacionData['id'])) {
            return (int) $evaluacionData['id'];
        }

        return null;
    }

    private function normalizarFechaLimiteAsignacion($evaluacionData): ?string
    {
        if (!is_array($evaluacionData) || !array_key_exists('fecha_limite', $evaluacionData)) {
            return null;
        }

        $fechaLimite = $evaluacionData['fecha_limite'];

        if ($fechaLimite === null || $fechaLimite === '') {
            return null;
        }

        try {
            $fecha = Carbon::createFromFormat('Y-m-d\TH:i', (string) $fechaLimite);

            if ($fecha->format('Y-m-d\TH:i') !== (string) $fechaLimite) {
                return null;
            }

            return $fecha->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizarMetadataAsignacion(int $sesionId, $evaluacionData): array
    {
        if (!is_array($evaluacionData)) {
            return [];
        }

        $plazoDias = $this->normalizarEnteroNullable($evaluacionData['plazo_dias'] ?? null);
        $fechaLimite = $this->normalizarFechaLimiteAsignacion($evaluacionData);

        if ($fechaLimite === null && $plazoDias !== null) {
            $fechaLimite = $this->calcularFechaLimitePorPlazo($sesionId, $plazoDias);
        }

        return [
            'fecha_limite' => $fechaLimite,
            'hito_nombre' => $this->limpiarTextoNullable($evaluacionData['hito_nombre'] ?? null, 120),
            'hito_orden' => $this->normalizarEnteroNullable($evaluacionData['hito_orden'] ?? null),
            'grupo_nombre' => $this->limpiarTextoNullable($evaluacionData['grupo_nombre'] ?? null, 120),
            'plazo_dias' => $plazoDias,
        ];
    }

    private function normalizarMetadataParcial(int $sesionId, array $evaluacionData): array
    {
        $metadata = [];

        if (array_key_exists('plazo_dias', $evaluacionData)) {
            $metadata['plazo_dias'] = $this->normalizarEnteroNullable($evaluacionData['plazo_dias']);
        }

        if (array_key_exists('fecha_limite', $evaluacionData)) {
            $metadata['fecha_limite'] = $this->normalizarFechaLimiteAsignacion($evaluacionData);
        }

        if (
            array_key_exists('plazo_dias', $metadata)
            && $metadata['plazo_dias'] !== null
            && (!array_key_exists('fecha_limite', $metadata) || $metadata['fecha_limite'] === null)
        ) {
            $metadata['fecha_limite'] = $this->calcularFechaLimitePorPlazo($sesionId, $metadata['plazo_dias']);
        }

        if (array_key_exists('hito_nombre', $evaluacionData)) {
            $metadata['hito_nombre'] = $this->limpiarTextoNullable($evaluacionData['hito_nombre'], 120);
        }

        if (array_key_exists('hito_orden', $evaluacionData)) {
            $metadata['hito_orden'] = $this->normalizarEnteroNullable($evaluacionData['hito_orden']);
        }

        if (array_key_exists('grupo_nombre', $evaluacionData)) {
            $metadata['grupo_nombre'] = $this->limpiarTextoNullable($evaluacionData['grupo_nombre'], 120);
        }

        return $metadata;
    }

    private function calcularFechaLimitePorPlazo(int $sesionId, int $plazoDias): ?string
    {
        $sesion = $this->sesionRepository->obtenerPorId($sesionId);

        if (!$sesion || empty($sesion->fecha)) {
            return null;
        }

        $time = $sesion->hora_fin ?? $sesion->hora_inicio ?? '23:59:00';

        try {
            return Carbon::parse($sesion->fecha.' '.$time, 'America/Lima')
                ->addDays($plazoDias)
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function limpiarTextoNullable($value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function normalizarEnteroNullable($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value >= 0 ? $value : null;
    }
}
