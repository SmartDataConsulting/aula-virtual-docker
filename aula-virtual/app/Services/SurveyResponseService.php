<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;

class SurveyResponseService
{
    public function __construct(
        private readonly ApiServiciosClient $client
    ) {}

     

    /**
     * Registrar respuestas
     */
    public function registrarRespuestaEncuesta(
        int $encuestaId,
        int $courseId,
        int $sesionId,
        string $correo,
        array $respuestas
    ): ServiceResult {

        $result = $this->client->registrarRespuestaEncuesta(
            $encuestaId,
            $courseId,
            $sesionId,
            $correo,
            $respuestas
        );

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success(
            $result->data()
        );
    }

    /**
     * Estadísticas de respuestas
     */
    public function obtenerEstadisticaSesion(
        int $sesionId
    ): ServiceResult {

        $result = $this->client->obtenerEstadisticaEncuestaSesion(
            $sesionId
        );

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        return ServiceResult::success(
            $result->data()
        );
    }
}