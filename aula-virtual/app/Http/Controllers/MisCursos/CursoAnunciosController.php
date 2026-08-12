<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Http\ApiServiciosClient;
use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Log;
use App\Services\AnnouncementService;

class CursoAnunciosController extends Controller
{
    private ApiServiciosClient $apiServiciosClient;
    private AnnouncementService $announcementService;

    public function __construct(
    AnnouncementService $announcementService
) {
    $this->announcementService = $announcementService;
}

   /**
 * Marca un anuncio como leído.
 */
public function leer(Request $request, $anuncioId)
{
    try {

        Log::info('MARCAR ANUNCIO START', [
            'anuncio_id' => $anuncioId
        ]);

        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);

        if (!$correo) {
            throw new \Exception('Correo no encontrado en sesión');
        }

        $result = $this->announcementService
            ->marcarAnuncioComoLeido(
                (int) $anuncioId,
                $correo
            );

        if (!$result->ok()) {
            throw new \Exception(
                'AnnouncementService devolvió error: ' .
                json_encode($result->error())
            );
        }

        Log::info('MARCAR ANUNCIO OK', [
            'anuncio_id' => $anuncioId
        ]);

        return response()->json(['success' => true]);

    } catch (\Throwable $e) {

        Log::error('MARCAR ANUNCIO FAILED', [
            'anuncio_id' => $anuncioId,
            'exception'  => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Ocurrió un error inesperado'
        ], 500);
    }
}
  public function leerTodos(Request $request, $entidadTipo, $entidadId)
{
    try {

        Log::info('MARCAR TODOS ANUNCIOS START', [
            'tipo' => $entidadTipo,
            'id'   => $entidadId
        ]);

        if (!in_array($entidadTipo, ['curso', 'sesion'])) {
            throw new \Exception('Entidad tipo inválida');
        }

        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL);

        if (!$correo) {
            throw new \Exception('Correo no encontrado en sesión');
        }

        $result = $this->announcementService
            ->marcarAnunciosComoLeido(
                $entidadTipo,
                (int) $entidadId,
                $correo
            );

        if (!$result->ok()) {
            throw new \Exception(
                'AnnouncementService devolvió error: ' .
                json_encode($result->error())
            );
        }

        Log::info('MARCAR TODOS ANUNCIOS OK', [
            'tipo' => $entidadTipo,
            'id'   => $entidadId
        ]);

        return response()->json(['success' => true]);

    } catch (\Throwable $e) {

        Log::error('MARCAR TODOS ANUNCIOS FAILED', [
            'tipo'      => $entidadTipo ?? null,
            'id'        => $entidadId ?? null,
            'exception' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Ocurrió un error inesperado'
        ], 500);
    }
}
}
