<?php

namespace App\Http\Controllers;

use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function index(Request $request, ChatService $chatService, string $sala): JsonResponse
    {
        $limit = (int) $request->integer('limit', 20);
        $offset = (int) $request->integer('offset', 0);

        $result = $chatService->obtenerMensajesSala($sala, $limit, $offset);

        if (!$result->ok()) {
            Log::warning('Error actualizando mensajes de chat', [
                'sala_id' => $sala,
                'status' => $result->status(),
                'error' => $result->error(),
            ]);

            return response()->json([
                'message' => $result->error()['message'] ?? 'No se pudo actualizar la conversación.',
            ], $result->status() ?: 422);
        }

        return response()->json($result->data());
    }

    public function storePrincipal(Request $request, ChatService $chatService, string $sala): JsonResponse
    {
        $mensaje = (string) $request->input('mensaje', '');
        $mensajePadreId = $request->filled('mensaje_padre_id')
            ? (string) $request->input('mensaje_padre_id')
            : null;

        $result = $mensajePadreId
            ? $chatService->responderComentario($sala, $mensajePadreId, $mensaje)
            : $chatService->publicarComentarioPrincipal($sala, $mensaje);

        if (!$result->ok()) {
            Log::error('Error publicando comentario principal de chat', [
                'sala_id' => $sala,
                'status' => $result->status(),
                'error' => $result->error(),
            ]);

            return response()->json([
                'message' => $result->error()['message'] ?? 'No se pudo publicar el comentario. Intenta nuevamente.',
            ], $result->status() ?: 422);
        }

        return response()->json($result->data());
    }

    public function destroy(ChatService $chatService, string $mensaje): JsonResponse
    {
        $result = $chatService->eliminarMensajePropio($mensaje);

        if (!$result->ok()) {
            Log::error('Error eliminando mensaje propio de chat', [
                'mensaje_id' => $mensaje,
                'status' => $result->status(),
                'error' => $result->error(),
            ]);

            return response()->json([
                'message' => $result->error()['message'] ?? 'No se pudo eliminar el mensaje. Intenta nuevamente.',
            ], $result->status() ?: 422);
        }

        return response()->json([
            'message' => 'Mensaje eliminado.',
        ], $result->status() ?: 200);
    }
}
