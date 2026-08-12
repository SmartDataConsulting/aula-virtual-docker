<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;

class CursoParticipanteService
{
    public function __construct(private readonly ApiServiciosClient $client)
    {
    }

    public function solicitarContacto(
        string $cursoEdicionId,
        string $solicitanteCorreo,
        string $destinatarioCorreo,
        ?string $mensaje = null
    ): ServiceResult {
        $cursoEdicionId = trim($cursoEdicionId);
        $solicitanteCorreo = strtolower(trim($solicitanteCorreo));
        $destinatarioCorreo = strtolower(trim($destinatarioCorreo));
        $mensaje = trim((string) $mensaje);

        if ($cursoEdicionId === '' || !ctype_digit($cursoEdicionId)) {
            return ServiceResult::failure(['message' => 'No se pudo identificar el curso.'], 422);
        }

        if ($solicitanteCorreo === '' || $destinatarioCorreo === '') {
            return ServiceResult::failure(['message' => 'No se pudo identificar a los alumnos.'], 422);
        }

        if ($solicitanteCorreo === $destinatarioCorreo) {
            return ServiceResult::failure(['message' => 'No puedes solicitar tus propios datos de contacto.'], 422);
        }

        if ($this->existeSolicitudPendiente($cursoEdicionId, $solicitanteCorreo, $destinatarioCorreo)) {
            return ServiceResult::success([
                'ok' => true,
                'message' => 'Ya existe una solicitud pendiente.',
                'estado' => 'PENDIENTE',
            ]);
        }

        $result = $this->client->enviarSolicitudContactoAlumno([
            'curso_edicion_id' => $cursoEdicionId,
            'solicitante_correo' => $solicitanteCorreo,
            'destinatario_correo' => $destinatarioCorreo,
            'mensaje' => $mensaje !== '' ? $mensaje : 'Hola, me gustaria acceder a tus datos de contacto.',
        ]);

        if (!$result->ok()) {
            $body = (string) ($result->error()['body'] ?? '');

            if (str_contains($body, 'Ya existe una solicitud pendiente')) {
                return ServiceResult::success([
                    'ok' => true,
                    'message' => 'Ya existe una solicitud pendiente.',
                    'estado' => 'PENDIENTE',
                ]);
            }

            return ServiceResult::failure([
                'message' => 'No se pudo enviar la solicitud.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        $payload = is_array($result->data()) ? $result->data() : [];

        return ServiceResult::success([
            'ok' => true,
            'message' => 'Solicitud enviada correctamente.',
            'solicitud' => $payload['solicitud'] ?? null,
            'estado' => data_get($payload, 'solicitud.estado', 'PENDIENTE'),
        ], $result->status() ?: 200);
    }

    public function consultarSolicitudes(string $correo, string $tipo = 'RECIBIDAS'): ServiceResult
    {
        $correo = strtolower(trim($correo));
        $tipo = strtoupper(trim($tipo));

        if ($correo === '') {
            return ServiceResult::failure(['message' => 'No se encontro el correo del alumno.'], 401);
        }

        if (!in_array($tipo, ['RECIBIDAS', 'ENVIADAS'], true)) {
            $tipo = 'RECIBIDAS';
        }

        $result = $this->client->consultarSolicitudesContactoAlumno($correo, $tipo);

        if (!$result->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudieron cargar las solicitudes de contacto.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        $payload = is_array($result->data()) ? $result->data() : [];
        $solicitudes = $payload['solicitudes'] ?? $payload['data'] ?? [];

        return ServiceResult::success([
            'ok' => true,
            'tipo' => $tipo,
            'solicitudes' => array_values(array_filter(
                is_array($solicitudes) ? $solicitudes : [],
                fn ($solicitud) => strtoupper((string) data_get($solicitud, 'estado', '')) === 'PENDIENTE'
            )),
        ], $result->status() ?: 200);
    }

    public function existeSolicitudPendiente(string $cursoEdicionId, string $solicitanteCorreo, string $destinatarioCorreo): bool
    {
        $result = $this->consultarSolicitudes($solicitanteCorreo, 'ENVIADAS');

        if (!$result->ok()) {
            return false;
        }

        foreach ($result->data()['solicitudes'] ?? [] as $solicitud) {
            $mismaSolicitud = (string) data_get($solicitud, 'curso_edicion_id') === (string) $cursoEdicionId
                && strtolower(trim((string) data_get($solicitud, 'destinatario_correo'))) === strtolower(trim($destinatarioCorreo))
                && strtoupper((string) data_get($solicitud, 'estado')) === 'PENDIENTE';

            if ($mismaSolicitud) {
                return true;
            }
        }

        return false;
    }
}
