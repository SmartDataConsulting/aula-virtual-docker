<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;

class AnnouncementService
{
    public function __construct(
        private readonly ApiServiciosClient $client
    ) {
    }

    public function listarAnuncios(string $entidadTipo, int $entidadId): ServiceResult
    {
        $rol = session(AuthSessionKeys::USER_ROLE);

        $result = $this->client->listarAnuncios($entidadTipo, $entidadId, $rol);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $items = $this->extractAnnouncementItems($result->data());

        $announcements = collect($items)
            ->values()
            ->map(fn ($item, $index) =>
                 (object) $this->normalizeAnnouncement($item, $index + 1)
            );

        return ServiceResult::success([
            'announcements' => $announcements,
        ]);
    }

    public function listarAnunciosAlumno(
        string $entidadTipo,
        int $entidadId,
        string $correo
    ): ServiceResult {

        // Consulta anuncios con estado de lectura.
        $result = $this->client->listarAnunciosAlumno(
            $entidadTipo,
            $entidadId,
            $correo
        );

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $items = is_array($result->data())
            ? $result->data()
            : [];

        $anuncios = collect($items)
        ->values()
        ->map(function ($item) {
            return (object) [
            'id'         => (int) ($item['id'] ?? 0),
            'title'      => (string) ($item['titulo'] ?? ''),
            'content'    => (string) ($item['contenido'] ?? ''),
            'type'       => (string) ($item['tipo'] ?? 'general'),
            'created_at' => (string) ($item['creado_en'] ?? ''),
            'updated_at' => (string) ($item['actualizado_en'] ?? ''),
            'leido'      => (int) ($item['leido'] ?? 0),
            ];
        });

        return ServiceResult::success([
            'anuncios' => $anuncios,
        ]);
    }

    private function extractAnnouncementItems(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return [];
    }

    private function normalizeAnnouncement(mixed $item, int $fallbackOrder): array
    {
        $data = is_array($item) ? $item : (array) $item;

        return [
            'id'         => $data['id'] ?? $fallbackOrder,
            'session_id' => $data['session_id'] ?? null,

            'title'      => $data['title'] ?? $data['titulo'] ?? 'Anuncio',
            'content'    => $data['content'] ?? $data['contenido'] ?? null,
            'type'    => $data['tipo'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Marca un anuncio como leído
     */
    public function marcarAnuncioComoLeido(
        int $anuncioId,
        string $correo
    ): ServiceResult {

        $result = $this->client->marcarAnuncioComoLeido(
            $anuncioId,
            $correo
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
     * Marca todos los anuncios como leídos (curso o sesion)
     */
    public function marcarAnunciosComoLeido(
        string $entidadTipo,
        int $entidadId,
        string $correo
    ): ServiceResult {

        $result = $this->client->marcarAnunciosComoLeido(
            $entidadTipo,
            $entidadId,
            $correo
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
     * Crea anuncio en sesión
     */
    public function crearAnuncio(array $payload): ServiceResult
    {
        $rol = session(AuthSessionKeys::USER_ROLE);

        $result = $this->client->crearAnuncio($payload, $rol);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        return ServiceResult::success($result->data());
    }

    /**
     * Actualiza anuncio
     */
    public function actualizarAnuncio(
        int $announcementId,
        array $payload
    ): ServiceResult {

        $rol = session(AuthSessionKeys::USER_ROLE);

        $result = $this->client->actualizarAnuncio(
            $announcementId,
            $payload,
            $rol
        );

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        return ServiceResult::success($result->data());
    }

    /**
     * Elimina anuncio
     */
    public function eliminarAnuncio(int $announcementId): ServiceResult
    {
        $rol = session(AuthSessionKeys::USER_ROLE);

        $result = $this->client->eliminarAnuncio(
            $announcementId,
            $rol
        );

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        return ServiceResult::success($result->data());
    }
}