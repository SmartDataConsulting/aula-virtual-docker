<?php

namespace App\Http\Middleware;

use App\Helpers\DbSafe;
use App\Repositories\EncuestaRespuestaRepository;
use App\Support\BackofficePermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class CourseScopeMiddleware
{
    public function handle(Request $request, Closure $next, string $resource = 'course')
    {
        $role = BackofficePermission::normalizeRole($request->header('X-USER-ROL'));
        if (in_array($role, ['admin', 'operador'], true)) {
            return $next($request);
        }

        $email = trim((string) $request->header('X-USER-EMAIL'));
        // Student material and video content reads require canonical enrollment.
        $studentResource = $role === 'alumno' && in_array($resource, ['material', 'session', 'course'], true);
        if ($role !== 'docente' && !$studentResource) {
            return $next($request);
        }

        if ($email === '') {
            return $this->denied();
        }

        try {
            $courseId = $this->resolveCourseId($request, $resource);
            if ($courseId === null) {
                return $this->denied();
            }

            $authorized = $studentResource
                ? app(EncuestaRespuestaRepository::class)->alumnoInscritoEnCurso($courseId, $email)
                : $this->teacherAssigned($courseId, $email);
            if (!$authorized) {
                return $this->denied();
            }
        } catch (\Throwable $exception) {
            Log::warning('course_scope_check_failed', ['resource' => $resource, 'exception' => $exception::class]);
            return $this->denied();
        }

        return $next($request);
    }

    private function resolveCourseId(Request $request, string $resource): ?int
    {
        if ($resource === 'course') {
            return $this->positiveInt($this->routeValue($request, ['courseId', 'cursoId', 'course']));
        }

        if ($resource === 'session') {
            $sessionId = $this->positiveInt($this->routeValue($request, ['sessionId', 'sesionId', 'session']));
            return $sessionId ? $this->scalar('SELECT curso_edicion_id FROM curso_edicion_sesiones WHERE id = ? LIMIT 1', [$sessionId]) : null;
        }

        if ($resource === 'material') {
            $materialId = $this->positiveInt($this->routeValue($request, ['id', 'materialId', 'material']));
            return $materialId ? $this->scalar(
                'SELECT s.curso_edicion_id FROM curso_edicion_sesion_materiales m JOIN curso_edicion_sesiones s ON s.id = m.curso_edicion_sesion_id WHERE m.id = ? LIMIT 1',
                [$materialId]
            ) : null;
        }

        if ($resource === 'evaluation') {
            $evaluationId = $this->positiveInt($this->routeValue($request, ['evaluacionId', 'evaluationId']));
            return $evaluationId ? $this->scalar('SELECT curso_id FROM evaluacion WHERE id = ? LIMIT 1', [$evaluationId]) : null;
        }

        if ($resource === 'announcement') {
            $announcementId = $this->positiveInt($this->routeValue($request, ['anuncioId', 'announcementId']));
            if ($announcementId) {
                $rows = DbSafe::select('mysql_cursos', 'SELECT entidad_tipo, entidad_id FROM curso_edicion_anuncios WHERE id = ? AND activo = 1 LIMIT 1', [$announcementId]);
                return $rows === [] ? null : $this->entityCourseId((string) $rows[0]->entidad_tipo, (int) $rows[0]->entidad_id);
            }

            $type = strtolower(trim((string) ($this->routeValue($request, ['entidadTipo']) ?? $request->input('entidad_tipo'))));
            $id = $this->positiveInt($this->routeValue($request, ['entidadId']) ?? $request->input('entidad_id'));
            return $id ? $this->entityCourseId($type, $id) : null;
        }

        return null;
    }

    private function entityCourseId(string $type, int $id): ?int
    {
        if ($type === 'curso') {
            return $id;
        }

        return $type === 'sesion'
            ? $this->scalar('SELECT curso_edicion_id FROM curso_edicion_sesiones WHERE id = ? LIMIT 1', [$id])
            : null;
    }

    private function teacherAssigned(int $courseId, string $email): bool
    {
        $sql = "SELECT 1 FROM curso_edicion ce WHERE ce.id = ? AND EXISTS (
            SELECT 1 FROM usuario u WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(?)) AND (
                u.colaborador_id = ce.docente_id_colaborador
                OR u.colaborador_id = ce.docente2_id_colaborador
                OR EXISTS (SELECT 1 FROM curso_edicion_sesiones s WHERE s.curso_edicion_id = ce.id AND s.docente_id = u.colaborador_id)
            )
        ) LIMIT 1";

        return DbSafe::select('mysql_cursos', $sql, [$courseId, $email]) !== [];
    }

    private function scalar(string $sql, array $bindings): ?int
    {
        $rows = DbSafe::select('mysql_cursos', $sql, $bindings);
        if ($rows === []) {
            return null;
        }

        return $this->positiveInt(array_values((array) $rows[0])[0] ?? null);
    }

    private function routeValue(Request $request, array $names)
    {
        foreach ($names as $name) {
            $value = $request->route($name);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function positiveInt($value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function denied()
    {
        return response()->json(['ok' => false, 'message' => 'No autorizado para este curso'], 403);
    }
}
