<?php

namespace App\Http\Controllers;

use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    protected ChatService $service;

    public function __construct(ChatService $service)
    {
        $this->service = $service;
    }

    public function obtenerOCrearSala(Request $request, $tipoContexto, $idContexto)
    {
        try {
            $sala = $this->service->obtenerOCrearSala(
                $tipoContexto,
                $idContexto,
                $request->query('titulo')
            );

            return response()->json($sala);
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function listarMensajes(Request $request, $salaId)
    {
        try {
            $limit = (int) $request->query('limit', 20);
            $offset = (int) $request->query('offset', 0);

            return response()->json(
                $this->service->listarMensajesPaginados($salaId, $limit, $offset)
            );
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function crearMensaje(Request $request, $salaId)
    {
        try {
            $data = $request->all();
            $data['sala_id'] = $salaId;
            $data['usuario_id'] = $this->obtenerCorreoUsuario($request);
            $data['nombre_usuario'] = $this->obtenerNombreUsuario($request);
            $data['rol_usuario'] = $this->obtenerRolUsuario($request);

            $mensaje = $this->service->crearMensaje($data);

            return response()->json([
                'ok' => true,
                'mensaje' => $mensaje,
            ]);
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function obtenerMensaje(Request $request, $mensajeId)
    {
        try {
            $mensaje = $this->service->obtenerMensaje($mensajeId);

            if (!$mensaje) {
                return response()->json(['message' => 'Mensaje no encontrado'], 404);
            }

            return response()->json($mensaje);
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function eliminarMensaje(Request $request, $mensajeId)
    {
        try {
            $this->service->eliminarMensaje(
                $mensajeId,
                $this->obtenerCorreoUsuario($request),
                $this->obtenerRolUsuario($request)
            );

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function fijarMensaje(Request $request, $mensajeId)
    {
        try {
            $this->service->fijarMensaje(
                $mensajeId,
                $this->obtenerRolUsuario($request)
            );

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function desfijarMensaje(Request $request, $mensajeId)
    {
        try {
            $this->service->desfijarMensaje(
                $mensajeId,
                $this->obtenerRolUsuario($request)
            );

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function listarMensajesFijados(Request $request, $salaId)
    {
        try {
            return response()->json(
                $this->service->listarMensajesFijados($salaId)
            );
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function listarParticipantes(Request $request, $salaId)
    {
        try {
            return response()->json(
                $this->service->listarParticipantes($salaId)
            );
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function buscarMensajes(Request $request, $salaId)
    {
        try {
            $texto = (string) $request->query('texto', '');

            return response()->json(
                $this->service->buscarMensajes($salaId, $texto)
            );
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    public function resumenSala(Request $request, $salaId)
    {
        try {
            return response()->json(
                $this->service->obtenerResumenSala($salaId)
            );
        } catch (\Throwable $e) {
            return $this->mapChatException($e);
        }
    }

    private function obtenerCorreoUsuario(Request $request): string
    {
        return (string) $request->header('X-USER-EMAIL', '');
    }

    private function obtenerNombreUsuario(Request $request): string
    {
        return (string) $request->header('X-USER-NAME', 'Usuario');
    }

    private function obtenerRolUsuario(Request $request): string
    {
        return (string) ($request->header('X-USER-ROLE') ?: $request->header('X-USER-ROL', 'ALUMNO'));
    }

    private function mapChatException(\Throwable $e)
    {
        if ($e instanceof \InvalidArgumentException) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        if ($e instanceof \RuntimeException) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        if ($e instanceof \DomainException) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $correlationId = (string) Str::uuid();

        Log::error('chat_error', [
            'correlation_id' => $correlationId,
            'message' => $e->getMessage(),
        ]);

        return response()->json([
            'ok' => false,
            'message' => 'Error interno',
            'correlation_id' => $correlationId,
        ], 500);
    }
}
