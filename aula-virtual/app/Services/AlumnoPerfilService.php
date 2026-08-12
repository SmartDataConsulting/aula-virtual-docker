<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\PerformanceCache;
use Carbon\Carbon;

class AlumnoPerfilService
{
    public function __construct(private readonly ApiServiciosClient $client)
    {
    }

    public function obtenerMiPerfil(string $correo): ServiceResult
    {
        $correo = strtolower(trim($correo));

        if ($correo === '') {
            return ServiceResult::failure([
                'message' => 'No se encontró el correo del usuario autenticado.',
            ], 401);
        }

        return PerformanceCache::remember(
            $this->profileCacheKey($correo),
            PerformanceCache::COURSE_LIST_TTL,
            fn () => $this->obtenerMiPerfilFresh($correo)
        );
    }

    private function obtenerMiPerfilFresh(string $correo): ServiceResult
    {
        $result = $this->client->obtenerAlumnoPorCorreo($correo);

        if (!$result->ok()) {
            return ServiceResult::failure([
                'message' => $result->status() === 404
                    ? 'No se encontró información del alumno.'
                    : 'No se pudo cargar tu perfil en este momento.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        $payload = is_array($result->data()) ? $result->data() : [];
        $alumno = $payload['alumno'] ?? $payload['data'] ?? $payload;

        if (!is_array($alumno) || empty($alumno)) {
            return ServiceResult::failure([
                'message' => 'No se encontró información del alumno.',
            ], 404);
        }

        return ServiceResult::success($this->normalizarAlumno($alumno), $result->status());
    }

    public function actualizarMiPerfil(string $correo, array $data, array $archivos = []): ServiceResult
    {
        $correo = strtolower(trim($correo));

        if ($correo === '') {
            return ServiceResult::failure([
                'message' => 'No se encontro el correo del usuario autenticado.',
            ], 401);
        }

        $permitidos = [
            'correo_corporativo',
            'telefono',
            'fecha_nacimiento',
            'presentacion_profesional',
            'linkedin_url',
            'contacto_publico',
            'permite_solicitudes_contacto',
            'foto_url',
            'cv_url',
        ];

        $payload = [];

        foreach ($permitidos as $campo) {
            if (!array_key_exists($campo, $data)) {
                continue;
            }

            $valor = $data[$campo];

            if (in_array($campo, ['contacto_publico', 'permite_solicitudes_contacto'], true)) {
                $payload[$campo] = (int) $valor;
                continue;
            }

            if ($campo === 'presentacion_profesional') {
                $valor = trim(strip_tags((string) $valor));
            } elseif (is_string($valor)) {
                $valor = trim($valor);
            }

            $payload[$campo] = $valor === '' ? null : $valor;
        }

        $result = $this->client->actualizarAlumnoPorCorreo($correo, $payload);

        if (!$result->ok()) {
            PerformanceCache::forget($this->profileCacheKey($correo));

            return $result;
        }

        $archivos = array_filter($archivos, fn ($archivo) => $archivo instanceof \Illuminate\Http\UploadedFile);

        if (empty($archivos)) {
            PerformanceCache::forget($this->profileCacheKey($correo));

            return $result;
        }

        $adjuntosResult = $this->client->actualizarAdjuntosPerfilAlumno($correo, $archivos);
        PerformanceCache::forget($this->profileCacheKey($correo));

        return $adjuntosResult;
    }

    public function descargarAdjuntoPerfil(string $correo, string $tipo): ServiceResult
    {
        $correo = strtolower(trim($correo));

        if ($correo === '') {
            return ServiceResult::failure([
                'message' => 'No se encontro el correo del usuario autenticado.',
            ], 401);
        }

        return $this->client->descargarAdjuntoPerfilAlumno($correo, $tipo);
    }

    public function responderSolicitudContacto(
        string $solicitudId,
        string $destinatarioCorreo,
        string $estado
    ): ServiceResult {
        $solicitudId = trim($solicitudId);
        $destinatarioCorreo = strtolower(trim($destinatarioCorreo));
        $estado = strtoupper(trim($estado));

        if ($solicitudId === '') {
            return ServiceResult::failure(['message' => 'No se pudo identificar la solicitud.'], 422);
        }

        if ($destinatarioCorreo === '') {
            return ServiceResult::failure(['message' => 'No se encontro el correo del alumno.'], 401);
        }

        if (!in_array($estado, ['ACEPTADA', 'RECHAZADA'], true)) {
            return ServiceResult::failure(['message' => 'El estado debe ser ACEPTADA o RECHAZADA.'], 422);
        }

        $result = $this->client->responderSolicitudContactoAlumno($solicitudId, [
            'destinatario_correo' => $destinatarioCorreo,
            'estado' => $estado,
        ]);

        if (!$result->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudo actualizar la solicitud.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        $payload = is_array($result->data()) ? $result->data() : [];

        return ServiceResult::success([
            'ok' => true,
            'message' => $payload['message'] ?? 'Solicitud actualizada correctamente.',
            'estado' => strtoupper((string) ($payload['estado'] ?? $estado)),
            'solicitud' => $payload['solicitud'] ?? null,
        ], $result->status() ?: 200);
    }

    private function normalizarAlumno(array $alumno): array
    {
        $nombres = trim((string) ($alumno['nombres'] ?? ''));
        $apellidos = trim((string) ($alumno['apellidos'] ?? ''));
        $nombreCompleto = trim($nombres . ' ' . $apellidos);

        return [
            'correo' => $alumno['correo'] ?? '',
            'correo_corporativo' => $alumno['correo_corporativo'] ?? null,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'nombre_completo' => $nombreCompleto !== '' ? $nombreCompleto : 'Alumno',
            'telefono' => $alumno['telefono'] ?? null,
            'fecha_nacimiento' => $this->formatearFecha($alumno['fecha_nacimiento'] ?? null),
            'fecha_nacimiento_form' => $this->formatearFechaFormulario($alumno['fecha_nacimiento'] ?? null),
            'foto_url' => $alumno['foto_url'] ?? null,
            'presentacion_profesional' => $alumno['presentacion_profesional'] ?? null,
            'cv_url' => $alumno['cv_url'] ?? null,
            'linkedin_url' => $alumno['linkedin_url'] ?? null,
            'contacto_publico' => (int) ($alumno['contacto_publico'] ?? 0),
            'permite_solicitudes_contacto' => (int) ($alumno['permite_solicitudes_contacto'] ?? 1),
            'adjuntos' => $this->normalizarAdjuntos($alumno),
        ];
    }

    private function normalizarAdjuntos(array $alumno): array
    {
        $adjuntos = [];

        if (!empty($alumno['foto_url'])) {
            $fotoUrl = (string) $alumno['foto_url'];
            $fotoVersion = md5($fotoUrl . '|' . (string) ($alumno['fecha_actualizacion'] ?? ''));
            $fotoDownloadUrl = route('alumno.perfil.adjuntos.descargar', ['tipo' => 'foto']) . '?v=' . $fotoVersion;

            $adjuntos['foto'] = [
                'tipo' => 'foto',
                'nombre_original' => basename($fotoUrl),
                'peso_bytes' => null,
                'mime_type' => null,
                'url_descarga' => filter_var($fotoUrl, FILTER_VALIDATE_URL)
                    ? $fotoUrl
                    : $fotoDownloadUrl,
            ];
        }

        if (!empty($alumno['cv_url'])) {
            $cvUrl = (string) $alumno['cv_url'];

            $adjuntos['cv'] = [
                'tipo' => 'cv',
                'nombre_original' => basename($cvUrl),
                'peso_bytes' => null,
                'mime_type' => 'application/pdf',
                'url_descarga' => filter_var($cvUrl, FILTER_VALIDATE_URL)
                    ? $cvUrl
                    : route('alumno.perfil.adjuntos.descargar', ['tipo' => 'cv']),
            ];
        }

        return $adjuntos;
    }

    private function formatearFecha(mixed $fecha): ?string
    {
        if (empty($fecha)) {
            return null;
        }

        try {
            return Carbon::parse($fecha)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $fecha;
        }
    }

    private function formatearFechaFormulario(mixed $fecha): ?string
    {
        if (empty($fecha)) {
            return null;
        }

        try {
            return Carbon::parse($fecha)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function profileCacheKey(string $correo): string
    {
        return 'student-profile:' . md5(strtolower(trim($correo)));
    }
}
