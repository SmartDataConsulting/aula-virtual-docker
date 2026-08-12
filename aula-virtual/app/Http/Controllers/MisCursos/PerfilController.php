<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use App\Services\AlumnoPerfilService;
use App\Services\CursoParticipanteService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PerfilController extends Controller
{
    public function show(Request $request, AlumnoPerfilService $perfilService, CursoParticipanteService $participanteService)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            abort(401, 'No se encontró el correo del usuario autenticado');
        }

        $result = $perfilService->obtenerMiPerfil($correo);
        $alumno = $result->ok() ? $result->data() : null;

        if ($alumno && !empty($alumno['adjuntos']['foto'])) {
            $alumno['foto_data_uri'] = $this->buildFotoDataUri($perfilService, $correo);
        }

        $solicitudesResult = $participanteService->consultarSolicitudes($correo, 'RECIBIDAS');

        return view('alumno.perfil.show', [
            'alumno' => $alumno,
            'solicitudesContacto' => $solicitudesResult->ok()
                ? ($solicitudesResult->data()['solicitudes'] ?? [])
                : [],
            'profileError' => $result->ok()
                ? null
                : ($result->error()['message'] ?? 'No se pudo cargar tu perfil en este momento.'),
        ]);
    }

    public function solicitudesContacto(Request $request, CursoParticipanteService $participanteService)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if (trim($correo) === '') {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el correo del alumno.',
            ], 401);
        }

        $result = $participanteService->consultarSolicitudes(
            $correo,
            (string) $request->query('tipo', 'RECIBIDAS')
        );

        if (!$result->ok()) {
            return response()->json([
                'ok' => false,
                'message' => $result->error()['message'] ?? 'No se pudieron cargar las solicitudes.',
            ], $result->status() ?: 422);
        }

        return response()->json($result->data());
    }

    public function responderSolicitudContacto(
        Request $request,
        AlumnoPerfilService $perfilService,
        string $solicitudId
    ) {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if (trim($correo) === '') {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el correo del alumno.',
            ], 401);
        }

        $data = $request->validate([
            'estado' => ['required', Rule::in(['ACEPTADA', 'RECHAZADA'])],
        ]);

        $result = $perfilService->responderSolicitudContacto(
            $solicitudId,
            $correo,
            (string) $data['estado']
        );

        if (!$result->ok()) {
            return response()->json([
                'ok' => false,
                'message' => $result->error()['message'] ?? 'No se pudo actualizar la solicitud.',
            ], $result->status() ?: 422);
        }

        return response()->json($result->data(), $result->status() ?: 200);
    }

    public function actualizar(Request $request, AlumnoPerfilService $perfilService)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if (trim($correo) === '') {
            if ($this->expectsJsonResponse($request)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No se pudo actualizar el perfil. Intenta nuevamente.',
                ], 401);
            }

            return back()
                ->withInput()
                ->with('profile_error', 'No se pudo actualizar el perfil. Intenta nuevamente.');
        }

        $data = $request->validate([
            'correo_corporativo' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'fecha_nacimiento' => ['nullable', 'date_format:Y-m-d'],
            'presentacion_profesional' => ['nullable', 'string', 'max:5000'],
            'linkedin_url' => ['nullable', 'string', 'max:255', 'starts_with:https://'],
            'contacto_publico' => ['required', Rule::in(['0', '1'])],
            'permite_solicitudes_contacto' => ['required', Rule::in(['0', '1'])],
            'foto_archivo' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'cv_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ], [
            'correo_corporativo.email' => 'Ingresa un correo corporativo valido.',
            'telefono.max' => 'El telefono no debe superar los 30 caracteres.',
            'fecha_nacimiento.date_format' => 'Ingresa la fecha con formato YYYY-MM-DD.',
            'linkedin_url.starts_with' => 'LinkedIn debe iniciar con https://.',
            'foto_archivo.mimes' => 'La foto debe ser JPG o PNG.',
            'foto_archivo.max' => 'La foto no debe superar los 5 MB.',
            'cv_archivo.mimes' => 'El CV debe ser PDF.',
            'cv_archivo.max' => 'El CV no debe superar los 10 MB.',
        ]);

        $result = $perfilService->actualizarMiPerfil($correo, $data, [
            'foto' => $request->file('foto_archivo'),
            'cv' => $request->file('cv_archivo'),
        ]);

        if (!$result->ok()) {
            if ($this->expectsJsonResponse($request)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No se pudo actualizar el perfil. Intenta nuevamente.',
                ], $result->status() ?: 500);
            }

            return back()
                ->withInput()
                ->with('profile_error', 'No se pudo actualizar el perfil. Intenta nuevamente.');
        }

        if ($this->expectsJsonResponse($request)) {
            $perfil = $perfilService->obtenerMiPerfil($correo);
            $alumno = $perfil->ok() ? $perfil->data() : null;

            if ($alumno && !empty($alumno['adjuntos']['foto'])) {
                $alumno['foto_data_uri'] = $this->buildFotoDataUri($perfilService, $correo);
            }

            return response()->json([
                'ok' => true,
                'message' => 'Perfil actualizado correctamente.',
                'alumno' => $alumno,
            ]);
        }

        return redirect()
            ->route('alumno.perfil.show')
            ->with('profile_success', 'Perfil actualizado correctamente.');
    }

    public function descargarAdjunto(Request $request, AlumnoPerfilService $perfilService, string $tipo): Response
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if (trim($correo) === '') {
            abort(401);
        }

        if (!in_array($tipo, ['foto', 'cv'], true)) {
            abort(404);
        }

        $result = $perfilService->descargarAdjuntoPerfil($correo, $tipo);

        if (!$result->ok()) {
            abort($result->status() ?: 404);
        }

        $apiResponse = $result->data();
        $contentType = $apiResponse->header('Content-Type') ?? 'application/octet-stream';
        $contentDisposition = $apiResponse->header('Content-Disposition');
        $filename = $tipo === 'foto' ? 'foto-perfil' : 'cv';

        if ($contentDisposition && preg_match('/filename="?([^"]+)"?/', $contentDisposition, $matches)) {
            $filename = $matches[1];
        }

        if ($tipo === 'foto' && $contentType === 'application/octet-stream') {
            $contentType = $this->guessImageContentType($filename);
        }

        $body = $apiResponse->body();

        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => $this->buildDisposition($tipo, $filename),
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => $tipo === 'foto'
                ? 'no-store, no-cache, must-revalidate, max-age=0'
                : 'private, max-age=300',
        ]);
    }

    private function buildDisposition(string $tipo, string $filename): string
    {
        $disposition = $tipo === 'foto' ? 'inline' : 'attachment';

        return sprintf('%s; filename="%s"', $disposition, addslashes($filename));
    }

    private function guessImageContentType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/jpeg',
        };
    }

    private function buildFotoDataUri(AlumnoPerfilService $perfilService, string $correo): ?string
    {
        $result = $perfilService->descargarAdjuntoPerfil($correo, 'foto');

        if (!$result->ok()) {
            return null;
        }

        $apiResponse = $result->data();
        $contentType = $apiResponse->header('Content-Type') ?? 'application/octet-stream';

        if ($contentType === 'application/octet-stream') {
            $contentDisposition = $apiResponse->header('Content-Disposition') ?? '';
            $contentType = $this->guessImageContentType($contentDisposition);
        }

        if (!str_starts_with($contentType, 'image/')) {
            return null;
        }

        $body = $apiResponse->body();

        if ($body === '') {
            return null;
        }

        return 'data:' . $contentType . ';base64,' . base64_encode($body);
    }

    private function expectsJsonResponse(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }
}
