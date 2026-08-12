<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;
use App\Support\PerformanceCache;

class MaterialService
{
    public function __construct(private readonly ApiServiciosClient $client)
    {
    }

    /**
     * Lista materiales por sesión
     */
    public function listarMaterialesPorSesion(int $sessionId): ServiceResult
    {
        $result = $this->client->listarMaterialesPorSesion($sessionId);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $items = $this->extractMaterialItems($result->data());

        $materials = collect($items)
            ->values()
            ->map(fn ($item, $index) => (object) $this->normalizeMaterial($item, $index + 1));

        return ServiceResult::success([
            'materials' => $materials,
        ]);
    }

    private function extractMaterialItems(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return [];
    }

    private function normalizeMaterial(mixed $item, int $fallbackOrder): array
    {
        $data = is_array($item) ? $item : (array) $item;

        return [
            'id' => $data['id'] ?? $fallbackOrder,
            'session_id' => $data['curso_edicion_sesion_id'] ?? null,
            'title' => $data['titulo'] ?? 'Material',
            'description' => $data['descripcion'] ?? null,
            'type' => $data['tipo'] ?? null,

            'file_name' => $data['nombre_archivo'] ?? null,
            'file_path' => $data['ruta_archivo'] ?? null,
            'mime_type' => $data['mime_type'] ?? $data['tipo_mime'] ?? $data['content_type'] ?? null,
            'size' => $data['tamano_bytes'] ?? null,

            'external_url' => $data['url_externa'] ?? null,
            'order' => $data['orden'] ?? $fallbackOrder,
        ];
    }

    /**
     * Crea material en sesión
     */
    public function crearMaterialSesion(int $sessionId, array $payload): ServiceResult
    {
        // sacar rol desde sesión
        $rol = session('user_role');

        $result = $this->client->crearMaterialSesion($sessionId, $payload, $rol);

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        $data = $result->data();

        if (!is_array($data) || empty($data['ok']) || empty($data['id'])) {
            return ServiceResult::failure([
                'message' => 'El API no confirmo la creacion del material.',
            ], 502);
        }

        PerformanceCache::forgetCourseLists();

        return ServiceResult::success($data);
    }

   /**
     * Actualiza material de sesión
     */
    public function actualizarMaterialSesion(int $sessionId, int $materialId, array $payload): ServiceResult
    {
        $rol = session(AuthSessionKeys::USER_ROLE);

        $result = $this->client->actualizarMaterialSesion(
            $sessionId,
            $materialId,
            $payload,
            $rol
        );

        if (!$result->ok()) {
            return ServiceResult::failure($result->error(), $result->status());
        }

        PerformanceCache::forgetCourseLists();

        return ServiceResult::success($result->data());
    }

  /**
 * Elimina material de sesión
 */
public function eliminarMaterialSesion(int $sessionId, int $materialId): ServiceResult
{
    $rol = session()->get(\App\Support\AuthSessionKeys::USER_ROLE);

    $result = $this->client->eliminarMaterialSesion(
        $sessionId,
        $materialId,
        $rol
    );

    if (!$result->ok()) {
        return ServiceResult::failure($result->error(), $result->status());
    }

    PerformanceCache::forgetCourseLists();

    return ServiceResult::success($result->data());
}

     

    public function descargarMaterial(int $materialId): ServiceResult
{
    $rol = session(\App\Support\AuthSessionKeys::USER_ROLE);

    return $this->client->descargarMaterialSesion(
        $materialId,
        $rol
    );
}


}
