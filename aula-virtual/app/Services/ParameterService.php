<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\PerformanceCache;

class ParameterService
{
    public function __construct(
        private readonly ApiServiciosClient $client
    ) {
    }

    /**
     * Listar parametros por maestro
     */
    public function listarPorMaestro(int $idMaestro): ServiceResult
    {
        return PerformanceCache::remember(
            PerformanceCache::parametersKey($idMaestro),
            PerformanceCache::CATALOG_TTL,
            fn () => $this->listarPorMaestroFresh($idMaestro)
        );
    }

    private function listarPorMaestroFresh(int $idMaestro): ServiceResult
    {
        $result = $this->client->listarParametrosPorMaestro($idMaestro);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $items = $this->extractItems($result->data());

        $parametros = collect($items)
            ->values()
            ->map(fn ($item) => (object) $this->normalizeParametro($item));

        return ServiceResult::success([
            'items' => $parametros
        ]);
    }

    private function extractItems(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return [];
    }

    private function normalizeParametro(mixed $item): array
    {
        $data = is_array($item) ? $item : (array) $item;

        return [
            'id_maestro'  => $data['id_maestro'] ?? null,
            'desc_maestro'=> $data['desc_maestro'] ?? null,
            'id_valor'    => $data['id_valor'] ?? null,
            'desc_valor'  => $data['desc_valor'] ?? null,
        ];
    }
}
