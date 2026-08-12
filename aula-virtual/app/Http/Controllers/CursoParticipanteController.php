<?php

namespace App\Http\Controllers;

use App\Services\CursoParticipanteService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CursoParticipanteController extends Controller
{
    public function solicitarContacto(
        Request $request,
        CursoParticipanteService $service,
        int $cursoEdicionId,
        string $correo
    ): JsonResponse {
        $solicitanteCorreo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');
        $destinatarioCorreo = rawurldecode($correo);

        $result = $service->solicitarContacto(
            (string) $cursoEdicionId,
            $solicitanteCorreo,
            $destinatarioCorreo,
            $request->input('mensaje')
        );

        if (!$result->ok()) {
            return response()->json([
                'ok' => false,
                'message' => $result->error()['message'] ?? 'No se pudo enviar la solicitud.',
            ], $result->status() ?: 422);
        }

        return response()->json($result->data(), $result->status() ?: 200);
    }
}
