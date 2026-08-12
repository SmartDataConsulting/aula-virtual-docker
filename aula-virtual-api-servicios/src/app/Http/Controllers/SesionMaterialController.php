<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SesionMaterialService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Support\ApiCache;

class SesionMaterialController extends Controller
{
    protected SesionMaterialService $service;

    public function __construct(SesionMaterialService $service)
    {
        $this->service = $service;
    }

    /**
     * Lista materiales por sesión
     */
    public function listar(Request $request, $sesionId)
    {
        $start = microtime(true);

        if (!is_numeric($sesionId)) {
            return response()->json(['error' => 'curso_edicion_sesion_id invalido'], 400);
        }

        $rows = Cache::remember("sesion_materiales_{$sesionId}", 60, function () use ($sesionId) {
            return $this->service->listarPorSesion((int)$sesionId);
        });

        $data = array_map(function ($m) {
            return [
                'id' => (int) $m->id,
                'curso_edicion_sesion_id' => (int) $m->curso_edicion_sesion_id,

                'titulo' => $m->titulo,
                'descripcion' => $m->descripcion,
                'tipo' => $m->tipo,

                'nombre_archivo' => $m->nombre_archivo,
                'ruta_archivo' => $m->ruta_archivo,
                'mime_type' => $m->mime_type,
                'tamano_bytes' => $m->tamano_bytes ? (int) $m->tamano_bytes : null,

                'url_externa' => $m->url_externa,
                'orden' => (int) $m->orden,
            ];
        }, $rows);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('sesion_materiales', [
            'curso_edicion_sesion_id' => (int)$sesionId,
            'ms' => $elapsed,
            'count' => count($data)
        ]);

        return response()->json($data);
    }

   /**
     * Crear material
     */
    public function crear(Request $request, $sesionId)
    {
        $start = microtime(true);

        if (!is_numeric($sesionId)) {
            return response()->json(['error' => 'sesionId invalido'], 400);
        }

        $this->validate($request, [
            'titulo' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:2000',
            'tipo' => 'required|in:archivo,link,video',
            'archivo' => 'required_if:tipo,archivo|nullable|file|max:30720|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,zip,jpg,jpeg,png,gif,webp',
            'url_externa' => 'required_if:tipo,link,video|nullable|url|max:2048',
        ]);

        $data = $request->only(['titulo', 'descripcion', 'tipo', 'url_externa']);

        $data['curso_edicion_sesion_id'] = (int)$sesionId;

        // === ARCHIVO ===
        if ($request->hasFile('archivo') && $request->file('archivo')->isValid()) {

            $file = $request->file('archivo');

            $path = Storage::disk('files')->putFile('materiales', $file);

            $data['nombre_archivo'] = $file->getClientOriginalName();
            $data['ruta_archivo'] = $path;
            $data['mime_type'] = $file->getMimeType();
            $data['tamano_bytes'] = $file->getSize();
        }

        $id = $this->service->crear($data);

        Cache::forget("sesion_materiales_{$sesionId}");
        ApiCache::bumpCourseSummary();

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('sesion_material_crear', [
            'curso_edicion_sesion_id' => (int)$sesionId,
            'material_id' => (int)$id,
            'ms' => $elapsed
        ]);

        return response()->json([
            'ok' => true,
            'id' => (int)$id
        ]);
    }

    /**
     * Actualizar material
     */
    public function actualizar(Request $request, $sesionId, $id)
    {
        $start = microtime(true);

        if (!is_numeric($sesionId) || !is_numeric($id)) {
            return response()->json(['error' => 'parametros invalidos'], 400);
        }

        $data = $request->only(['titulo','descripcion','tipo','url_externa',]);

        $data['curso_edicion_sesion_id'] = (int)$sesionId;
       
        if ($request->input('tipo') !== 'archivo') {
            $data['nombre_archivo'] = null;
            $data['ruta_archivo'] = null;
            $data['mime_type'] = null;
            $data['tamano_bytes'] = null;
        }

        // === ARCHIVO ===
        if ($request->hasFile('archivo')) {

            $file = $request->file('archivo');

            $path = Storage::disk('files')->putFile('materiales', $file);

            $data['nombre_archivo'] = $file->getClientOriginalName();
            $data['ruta_archivo'] = $path;
            $data['mime_type'] = $file->getMimeType();
            $data['tamano_bytes'] = $file->getSize();
        }

        $this->service->actualizar((int)$id, $data);

        Cache::forget("sesion_materiales_{$sesionId}");
        ApiCache::bumpCourseSummary();

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('sesion_material_actualizar', [
            'id' => (int)$id,
            'ms' => $elapsed
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Eliminar material (soft delete)
     */
    public function eliminar(Request $request, $sesionId, $id)
    {
        $start = microtime(true);

        if (!is_numeric($sesionId) || !is_numeric($id)) {
            return response()->json(['error' => 'parametros invalidos'], 400);
        }

        $this->service->eliminar((int)$id);

        Cache::forget("sesion_materiales_{$sesionId}");
        ApiCache::bumpCourseSummary();

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('sesion_material_eliminar', [
            'id' => (int)$id,
            'ms' => $elapsed
        ]);

        return response()->json(['ok' => true]);
    }

   /**
 * Descargar material
 */
    public function descargar(Request $request, int $id)
    {
        $start = microtime(true);

        if (!is_numeric($id)) {
            abort(400, 'Parametro invalido');
        }

        $data = $this->service->obtenerArchivoParaDescarga((int)$id);

        $elapsed = round((microtime(true) - $start) * 1000);

        Log::info('material_descargar', [
            'material_id' => (int)$id,
            'ms'          => $elapsed
        ]);

        return Storage::disk('files')->download(
            $data['ruta'],
            $data['nombre']
        );
    }

}
