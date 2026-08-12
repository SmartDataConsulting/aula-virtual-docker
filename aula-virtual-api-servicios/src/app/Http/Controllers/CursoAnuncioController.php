<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\CursoAnuncioService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CursoAnuncioController extends Controller
{
    protected CursoAnuncioService $service;

    public function __construct(CursoAnuncioService $service)
    {
        $this->service = $service;
    }

    /**
     * Lista anuncios por entidad (curso o sesión)
     */
    public function listar(Request $request, $entidadTipo, $entidadId)
    {
        $start = microtime(true);

        if (!in_array($entidadTipo, ['curso', 'sesion'])) {
            return response()->json(['error' => 'entidad_tipo invalido'], 400);
        }

        if (!is_numeric($entidadId)) {
            return response()->json(['error' => 'entidad_id invalido'], 400);
        }

        $cacheKey = "anuncios_{$entidadTipo}_{$entidadId}";

        $rows = Cache::remember($cacheKey, 60, function () use ($entidadTipo, $entidadId) {
            return $this->service->listarAnuncios($entidadTipo, (int)$entidadId);
        });

        $data = array_map(function ($a) {
            return [
                'id'             => (int) $a->id,
                'titulo'         => $a->titulo,
                'contenido'      => $a->contenido,
                'tipo'           => $a->tipo,
                'creado_por'     => (int) $a->creado_por,
                'creado_en'      => $a->creado_en,
                'actualizado_en' => $a->actualizado_en,
                'editado_en'     => $a->editado_en
            ];
        }, $rows);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('anuncios_listar', [
            'entidad_tipo' => $entidadTipo,
            'entidad_id'   => (int)$entidadId,
            'ms'           => $elapsed,
            'count'        => count($data)
        ]);

        return response()->json($data);
    }

    /**
     * Lista anuncios con estado lectura
     */
    public function listarConEstadoLectura(Request $request, $entidadTipo, $entidadId)
    {
        $start = microtime(true);

        if (!in_array($entidadTipo, ['curso', 'sesion'])) {
            return response()->json(['error' => 'entidad_tipo invalido'], 400);
        }

        if (!is_numeric($entidadId)) {
            return response()->json(['error' => 'entidad_id invalido'], 400);
        }

        $correo = trim((string) $request->input('correo'));

        if ($correo === '') {
            return response()->json(['error' => 'correo requerido'], 400);
        }


        $rows = $this->service->listarConEstadoLectura(
            $entidadTipo,
            (int)$entidadId,
            $correo
        );

        $data = array_map(function ($a) {
            return [
                'id'             => (int) $a->id,
                'titulo'         => $a->titulo,
                'contenido'      => $a->contenido,
                'tipo'           => $a->tipo,
                'creado_por'     => (int) $a->creado_por,
                'creado_en'      => $a->creado_en,
                'actualizado_en' => $a->actualizado_en,
                'leido'          => (int) ($a->leido ?? 0),
            ];
        }, $rows->all());

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('anuncios_con_lectura', [
            'entidad_tipo' => $entidadTipo,
            'entidad_id'   => (int)$entidadId,
            'correo'       => $correo,
            'ms'           => $elapsed,
            'count'        => count($data)
        ]);

        return response()->json($data);
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
     * Marcar anuncio como leído
     */
    public function marcarLeido(Request $request, $anuncioId)
    {
        if (!is_numeric($anuncioId)) {
            return response()->json(['error' => 'anuncio_id invalido'], 400);
        }

        $correo = trim((string) $request->input('correo'));

        if ($correo === '') {
            return response()->json(['error' => 'correo requerido'], 400);
        }

        $this->service->marcarLeido((int)$anuncioId, $correo);

        // 🔥 Mejor que flush global
        Cache::flush();

        return response()->json(['success' => true]);
    }

    /**
     * Marcar todos como leídos
     */ 
    public function marcarTodosLeidos(Request $request, $entidadTipo, $entidadId)
    {
        if (!in_array($entidadTipo, ['curso', 'sesion'])) {
            return response()->json(['error' => 'entidad_tipo invalido'], 400);
        }

        if (!is_numeric($entidadId)) {
            return response()->json(['error' => 'entidad_id invalido'], 400);
        }

        $correo = trim((string) $request->input('correo'));

        if ($correo === '') {
            return response()->json(['error' => 'correo requerido'], 400);
        }

        $count = $this->service->marcarMasivamenteComoLeido(
            $entidadTipo,
            (int)$entidadId,
            $correo
        );

        Cache::forget("anuncios_{$entidadTipo}_{$entidadId}_{$correo}");

        return response()->json([
            'success'    => true,
            'afectados'  => $count
        ]);
    }

    public function crear(Request $request)
    {
        $entidadTipo = strtolower(trim((string) $request->input('entidad_tipo')));
        $entidadId   = $request->input('entidad_id');
        $titulo      = trim((string) $request->input('titulo'));
        $contenido   = trim((string) $request->input('contenido'));
        $tipo        = $request->input('tipo', 'importante');
        $creadoPor   = (int) $request->input('creado_por');

        if (!in_array($entidadTipo, ['curso', 'sesion'])) {
            return response()->json(['error' => 'entidad_tipo invalido'], 400);
        }

        if (!is_numeric($entidadId)) {
            return response()->json(['error' => 'entidad_id invalido'], 400);
        }

        if ($titulo === '' || $contenido === '') {
            return response()->json(['error' => 'titulo y contenido son requeridos'], 400);
        }

        $id = $this->service->crear(
            $entidadTipo,
            (int)$entidadId,
            $titulo,
            $contenido,
            $tipo,
            $creadoPor
        );

        // Invalida cache de esa entidad
        Cache::forget("anuncios_{$entidadTipo}_{$entidadId}");

        return response()->json([
            'success' => true,
            'id'      => $id
        ]);
    }

   public function editar(Request $request, $anuncioId)
{
    if (!is_numeric($anuncioId)) {
        return response()->json(['error' => 'anuncio_id invalido'], 400);
    }

    try {

        $this->service->editar(
            (int)$anuncioId,
            trim((string)$request->input('titulo')),
            trim((string)$request->input('contenido')),
            $request->input('tipo', 'importante'),
            (int)$request->input('editado_por')
        );

        return response()->json(['success' => true]);

    } catch (\Exception $e) {

        if ($e->getMessage() === 'ANUNCIO_NO_ENCONTRADO') {
            return response()->json(['error' => 'anuncio no encontrado'], 404);
        }

        return response()->json(['error' => 'error interno'], 500);
    }
}

    public function eliminar($anuncioId)
{
    if (!is_numeric($anuncioId)) {
        return response()->json(['error' => 'anuncio_id invalido'], 400);
    }

    try {

        $this->service->eliminar((int)$anuncioId);

        return response()->json(['success' => true]);

    } catch (\Exception $e) {

        if ($e->getMessage() === 'ANUNCIO_NO_ENCONTRADO') {
            return response()->json(['error' => 'anuncio no encontrado'], 404);
        }

        return response()->json(['error' => 'error interno'], 500);
    }
}
}