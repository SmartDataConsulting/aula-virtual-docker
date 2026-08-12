<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ParametroService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ParametroController extends Controller
{
    protected ParametroService $service;

    public function __construct(ParametroService $service)
    {
        $this->service = $service;
    }

    /**
     * Listar todos los parametros
     */
    public function listar(Request $request)
    {
        $start = microtime(true);

        $rows = Cache::remember("parametros", 300, function () {
            return $this->service->listar();
        });

        $data = array_map(function ($p) {
            return [
                'id_maestro' => (int)$p->id_maestro,
                'desc_maestro' => $p->desc_maestro,
                'id_valor' => (int)$p->id_valor,
                'desc_valor' => $p->desc_valor,
            ];
        }, $rows);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('parametros_listar', [
            'ms' => $elapsed,
            'count' => count($data)
        ]);

        return response()->json($data);
    }

    /**
     * Listar por maestro
     */
    public function listarPorMaestro(Request $request, $id)
    {
        $start = microtime(true);
    
        if (!is_numeric($id)) {
            return response()->json(['error' => 'id_maestro invalido'], 400);
        }

        $cacheKey = "parametros_maestro_" . $id;

        $rows = Cache::remember($cacheKey, 300, function () use ($id) {
            return $this->service->listarPorMaestro((int)$id);
        });

        $data = array_map(function ($p) {
            return [
                'id_valor' => (int)$p->id_valor,
                'desc_valor' => $p->desc_valor,
            ];
        }, $rows);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('parametros_listar_maestro', [
            'id_maestro' => (int)$id,
            'ms' => $elapsed
        ]);

        return response()->json($data);
    }

    /**
     * Obtener valor
     */
    public function obtener(Request $request, $idMaestro, $idValor)
    {
        $start = microtime(true);

        if (!is_numeric($idMaestro) || !is_numeric($idValor)) {
            return response()->json(['error' => 'parametros invalidos'], 400);
        }

        $row = $this->service->obtener((int)$idMaestro, (int)$idValor);

        if (!$row) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('parametro_obtener', [
            'id_maestro' => (int)$idMaestro,
            'id_valor' => (int)$idValor,
            'ms' => $elapsed
        ]);

        return response()->json([
            'id_maestro' => (int)$row->id_maestro,
            'desc_maestro' => $row->desc_maestro,
            'id_valor' => (int)$row->id_valor,
            'desc_valor' => $row->desc_valor,
        ]);
    }

 
}