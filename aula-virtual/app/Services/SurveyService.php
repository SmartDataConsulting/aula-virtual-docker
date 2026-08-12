<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;

class SurveyService
{
    public function __construct(private readonly ApiServiciosClient $client)
    {
    }

    /**
     * Obtener encuesta completa para mostrar en formulario
     */
    public function obtenerEncuestaAlumno(int $courseId, int $sessionId, int $linkId): ServiceResult
    {
        $result = $this->client->obtenerEncuestaAlumno($courseId, $sessionId, $linkId);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $payload = is_array($result->data()) ? $result->data() : [];
        $survey = is_array($payload['survey'] ?? null) ? $payload['survey'] : [];
        $preguntas = collect($survey['questions'] ?? [])->map(fn ($item) => (object) [
            'id' => (int) ($item['id'] ?? 0),
            'code' => (string) ($item['code'] ?? ''),
            'text' => (string) ($item['label'] ?? ''),
            'type' => (string) ($item['type'] ?? 'textarea'),
            'required' => (bool) ($item['required'] ?? false),
            'options' => collect($item['options'] ?? []),
            'scale' => (object) ($item['scale'] ?? ['min' => 1, 'max' => 5]),
            'contextual' => (bool) ($item['contextual'] ?? false),
            'scope' => (string) ($item['scope'] ?? 'course'),
        ]);

        return ServiceResult::success([
            'encuesta' => (object) $survey,
            'preguntas' => $preguntas,
            'docentes' => collect($survey['teachers'] ?? [])->map(fn ($teacher) => (object) $teacher),
        ]);
    }

    public function registrarEncuestaAlumno(int $courseId, int $sessionId, int $linkId, array $payload): ServiceResult
    {
        $result = $this->client->registrarEncuestaAlumno($courseId, $sessionId, $linkId, $payload);
        if ($result->ok()) {
            return $result;
        }

        $error = $result->error();
        $body = json_decode((string) ($error['body'] ?? ''), true);
        if (is_array($body)) {
            $error['message'] = (string) ($body['message'] ?? $error['message'] ?? 'No se pudo guardar la encuesta.');
            $error['errors'] = is_array($body['errors'] ?? null) ? $body['errors'] : [];
        }

        return ServiceResult::failure($error, $result->status());
    }

    public function obtenerDetalleResultadosCurso(int $cursoEdicionId, array $filters = []): ServiceResult
    {
        $result = $this->client->obtenerDetalleResultadosEncuestasCurso($cursoEdicionId, $filters);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $payload = is_array($result->data()) ? $result->data() : [];
        $items = is_array($payload['responses']['data'] ?? null)
            ? $payload['responses']['data']
            : (is_array($payload['data'] ?? null) ? $payload['data'] : []);

        return ServiceResult::success(array_merge($payload, [
            'curso_edicion_id' => (int) ($payload['curso_edicion_id'] ?? $cursoEdicionId),
            'total' => (int) ($payload['total'] ?? count($items)),
            'resultados' => collect($items)->values(),
        ]));
    }

    /**
     * Extraer preguntas del payload
     */
    private function extractPreguntaItems(array $payload): array
    {
        if (isset($payload['preguntas']) && is_array($payload['preguntas'])) {
            return $payload['preguntas'];
        }

        return [];
    }

    /**
     * Normaliza pregunta para la vista
     */
    private function normalizePregunta(mixed $item, int $fallbackOrder): array
    {
        $data = is_array($item) ? $item : (array) $item;

        $pregunta = [
            'id' => $data['id'] ?? $fallbackOrder,
            'text' => $data['pregunta'] ?? '',
            'type' => $data['tipo_respuesta'] ?? 1,
            'required' => (bool) ($data['obligatoria'] ?? true),
        ];

        /*
        |----------------------------------------
        | Escala (ej: 1..5 estrellas)
        |----------------------------------------
        */

        if (isset($data['escala'])) {

            $pregunta['scale'] = [
                'min' => $data['escala']['min'] ?? 1,
                'max' => $data['escala']['max'] ?? 5,
                'label_min' => $data['escala']['label_min'] ?? null,
                'label_max' => $data['escala']['label_max'] ?? null,
            ];
        }

        /*
        |----------------------------------------
        | Opciones (radio / select)
        |----------------------------------------
        */

        if (isset($data['opciones']) && is_array($data['opciones'])) {

            $pregunta['options'] = collect($data['opciones'])
                ->map(function ($opt) {

                    $o = is_array($opt) ? $opt : (array) $opt;

                    return [
                        'value' => $o['valor'] ?? null,
                        'label' => $o['texto'] ?? '',
                    ];
                })
                ->values();
        }

        return $pregunta;
    }

}
