<?php

namespace App\Http\Controllers;

use App\Services\CursoService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommunityParticipantsController extends Controller
{
    public function index(Request $request, CursoService $cursoService, int $cursoEdicionId): JsonResponse
    {
        if ($cursoEdicionId <= 0) {
            return response()->json([
                'message' => 'No se pudo identificar el curso.',
            ], 422);
        }

        $result = $cursoService->listarParticipantesCurso(
            $cursoEdicionId,
            $request->session()->get(AuthSessionKeys::USER_ID),
            (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '')
        );

        if (!$result->ok()) {
            Log::warning('Error cargando participantes de comunidad', [
                'curso_edicion_id' => $cursoEdicionId,
                'status' => $result->status(),
                'error' => $result->error(),
            ]);

            return response()->json([
                'message' => $result->error()['message'] ?? 'No se pudieron cargar los participantes. Intenta nuevamente.',
            ], $result->status() ?: 422);
        }

        return response()->json($result->data(), $result->status() ?: 200);
    }

    public function profile(Request $request, CursoService $cursoService, int $cursoEdicionId, string $correo): JsonResponse
    {
        if ($cursoEdicionId <= 0 || trim($correo) === '') {
            return response()->json([
                'message' => 'No se pudo identificar el participante.',
            ], 422);
        }

        $result = $cursoService->obtenerPerfilPublicoParticipante(
            $cursoEdicionId,
            rawurldecode($correo),
            (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '')
        );

        if (!$result->ok()) {
            Log::warning('Error cargando perfil publico de participante', [
                'curso_edicion_id' => $cursoEdicionId,
                'correo' => rawurldecode($correo),
                'status' => $result->status(),
                'error' => $result->error(),
            ]);

            return response()->json([
                'message' => $result->error()['message'] ?? 'No se pudo cargar el perfil del participante.',
            ], $result->status() ?: 422);
        }

        return response()->json($result->data(), $result->status() ?: 200);
    }

    public function cv(Request $request, CursoService $cursoService, int $cursoEdicionId, string $correo)
    {
        $result = $cursoService->descargarCvPerfilPublicoParticipante(
            $cursoEdicionId,
            rawurldecode($correo),
            (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '')
        );

        if (!$result->ok()) {
            abort($result->status() ?: 404, $result->error()['message'] ?? 'CV no disponible.');
        }

        $data = $result->data();
        $apiResponse = $this->extractApiResponse($data);
        $filename = Str::ascii((string) ($data['filename'] ?? 'cv.pdf'));
        $filename = $filename !== '' ? $filename : 'cv.pdf';

        if (!$this->hasResponseBody($apiResponse)) {
            abort(404, 'CV no disponible.');
        }

        return response($apiResponse?->body() ?? '', 200)
            ->header('Content-Type', $apiResponse?->header('Content-Type') ?: 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"');
    }

    public function photo(Request $request, CursoService $cursoService, int $cursoEdicionId, string $correo)
    {
        $result = $cursoService->descargarFotoPerfilPublicoParticipante(
            $cursoEdicionId,
            rawurldecode($correo),
            (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '')
        );

        if (!$result->ok()) {
            return response('', $result->status() ?: 404);
        }

        $data = $result->data();
        $apiResponse = $this->extractApiResponse($data);

        if (!$this->hasResponseBody($apiResponse)) {
            Log::warning('Foto de participante sin cuerpo de respuesta', [
                'curso_edicion_id' => $cursoEdicionId,
                'correo' => rawurldecode($correo),
            ]);

            return response('', 404);
        }

        $contentType = $apiResponse?->header('Content-Type') ?: 'image/jpeg';

        if ($contentType === 'application/octet-stream') {
            $contentType = $this->guessImageContentType((string) ($data['filename'] ?? 'foto.jpg'));
        }

        return response($apiResponse?->body() ?? '', 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'private, max-age=300');
    }

    private function extractApiResponse(mixed $data): mixed
    {
        if (is_array($data)) {
            $response = $data['response'] ?? null;

            return is_array($response)
                ? ($response['response'] ?? null)
                : $response;
        }

        return $data;
    }

    private function hasResponseBody(mixed $apiResponse): bool
    {
        return is_object($apiResponse)
            && method_exists($apiResponse, 'body')
            && $apiResponse->body() !== '';
    }

    private function guessImageContentType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/jpeg',
        };
    }
}
