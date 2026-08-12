<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EncuestaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EncuestaController extends Controller
{
    protected EncuestaService $service;

    public function __construct(EncuestaService $service)
    {
        $this->service = $service;
    }

    /**
     * Listar encuestas
     */
    public function listar(Request $request)
    {
        $start = microtime(true);

        $rows = Cache::remember("encuestas", 60, function () {
            return $this->service->listar();
        });

        $data = array_map(function ($e) {
            return [
                'id' => (int)$e->id,
                'nombre' => $e->nombre,
                'tipo' => (int)$e->tipo,
                'activa' => (int)$e->activa,
            ];
        }, $rows);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('encuestas_listar', [
            'ms' => $elapsed,
            'count' => count($data)
        ]);

        return response()->json($data);
    }

    /**
     * Obtener encuesta
     */
    public function obtener(Request $request, $id)
    {
        $start = microtime(true);

        if (!is_numeric($id)) {
            return response()->json(['error' => 'id invalido'], 400);
        }

        $encuesta = $this->service->obtener((int)$id);

        if (!$encuesta) {
            return response()->json(['error' => 'encuesta no encontrada'], 404);
        }

        $preguntas = $this->service->listarPreguntas((int)$id);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('encuesta_obtener', [
            'id' => (int)$id,
            'ms' => $elapsed
        ]);

        return response()->json([
            'encuesta' => $encuesta,
            'preguntas' => $preguntas
        ]);
    }

    /**
     * Crear encuesta
     */
    public function crear(Request $request)
    {
        $start = microtime(true);

        $data = $request->only([
            'nombre',
            'tipo'
        ]);

        if (empty($data['nombre'])) {
            return response()->json(['error' => 'nombre requerido'], 400);
        }

        if (empty($data['tipo'])) {
            return response()->json(['error' => 'tipo requerido'], 400);
        }

        $id = $this->service->crear($data);

        Cache::forget("encuestas");

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('encuesta_crear', [
            'id' => (int)$id,
            'ms' => $elapsed
        ]);

        return response()->json([
            'ok' => true,
            'id' => (int)$id
        ]);
    }

    /**
     * Actualizar encuesta
     */
    public function actualizar(Request $request, $id)
    {
        $start = microtime(true);

        if (!is_numeric($id)) {
            return response()->json(['error' => 'id invalido'], 400);
        }

        $data = $request->only([
            'nombre',
            'tipo',
            'activa'
        ]);

        $this->service->actualizar((int)$id, $data);

        Cache::forget("encuestas");

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('encuesta_actualizar', [
            'id' => (int)$id,
            'ms' => $elapsed
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Eliminar encuesta
     */
    public function eliminar(Request $request, $id)
    {
        $start = microtime(true);

        if (!is_numeric($id)) {
            return response()->json(['error' => 'id invalido'], 400);
        }

        $this->service->eliminar((int)$id);

        Cache::forget("encuestas");

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('encuesta_eliminar', [
            'id' => (int)$id,
            'ms' => $elapsed
        ]);

        return response()->json(['ok' => true]);
    }

    public function formulario(Request $request, $id)
    {
        $start = microtime(true);

        if (!is_numeric($id)) {
            return response()->json(['error'=>'id invalido'],400);
        }

        $data = $this->service->obtenerEncuestaCompleta((int)$id);

        if(!$data){
            return response()->json(['error'=>'encuesta no encontrada'],404);
        }

        $elapsed = round((microtime(true)-$start)*1000);

        Log::info('encuesta_formulario',[
            'id'=>$id,
            'ms'=>$elapsed
        ]);

        return response()->json($data);
    }

   
}