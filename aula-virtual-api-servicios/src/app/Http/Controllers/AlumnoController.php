<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AlumnoService;
use Illuminate\Support\Facades\Storage;

class AlumnoController extends Controller
{
    protected AlumnoService $service;

    public function __construct(AlumnoService $service)
    {
        $this->service = $service;
    }

    public function insertar(Request $request)
    {
        try {
            $data = $request->only([
                'correo',
                'nombres',
                'apellidos',
                'telefono',
                'foto_url',
                'presentacion_profesional',
                'cv_url',
                'linkedin_url',
                'contacto_publico',
                'permite_solicitudes_contacto',
            ]);

            $alumno = $this->service->insertar($data);

            return response()->json([
                'ok' => true,
                'message' => 'Alumno registrado correctamente',
                'alumno' => $alumno,
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function actualizar(Request $request, $correo)
    {
        try {
            if (empty($correo)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'correo invalido',
                ], 400);
            }

            $data = $request->only([
                'correo_corporativo',
                'nombres',
                'apellidos',
                'fecha_nacimiento',
                'telefono',
                'foto_url',
                'presentacion_profesional',
                'cv_url',
                'linkedin_url',
                'contacto_publico',
                'permite_solicitudes_contacto',
            ]);

            $alumno = $this->service->actualizar($correo, $data);

            return response()->json([
                'ok' => true,
                'message' => 'Alumno actualizado correctamente',
                'alumno' => $alumno,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function obtenerMiPerfil(Request $request)
    {
        return $this->obtenerPorCorreo($request, (string) $request->header('X-USER-EMAIL', ''));
    }

    public function actualizarMiPerfil(Request $request)
    {
        return $this->actualizar($request, (string) $request->header('X-USER-EMAIL', ''));
    }

    public function actualizarAdjuntosPerfil(Request $request)
    {
        try {
            $correo = (string) $request->header('X-USER-EMAIL', '');

            if (empty($correo)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'correo invalido',
                ], 400);
            }

            $alumno = $this->service->actualizarAdjuntosPerfil($correo, [
                'foto' => $request->file('foto'),
                'cv' => $request->file('cv'),
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Adjuntos actualizados correctamente',
                'alumno' => $alumno,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function descargarAdjuntoPerfil(Request $request, $tipo)
    {
        try {
            $correo = (string) $request->header('X-USER-EMAIL', '');
            $data = $this->service->obtenerAdjuntoPerfilParaDescarga($correo, (string) $tipo);

            if (!Storage::disk('files')->exists($data['ruta'])) {
                return response()->json([
                    'ok' => false,
                    'message' => 'archivo no encontrado',
                ], 404);
            }

            return Storage::disk('files')->download(
                $data['ruta'],
                $data['nombre'],
                ['Content-Type' => $data['mime_type'] ?? 'application/octet-stream']
            );

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    public function obtenerPorCorreo(Request $request, $correo)
    {
        try {
            if (empty($correo)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'correo invalido',
                ], 400);
            }

            $alumno = $this->service->obtenerPorCorreo($correo);

            if (!$alumno) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Alumno no encontrado',
                ], 404);
            }

            return response()->json([
                'ok' => true,
                'alumno' => $alumno,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function obtenerPerfilPublico(Request $request, $correo)
    {
        try {
            if (empty($correo)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'correo invalido',
                ], 400);
            }

            $perfil = $this->service->obtenerPerfilPublico(
                (string) $correo,
                $request->query('curso_edicion_id'),
                $request->query('solicitante_correo')
            );

            if (!$perfil) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Alumno no encontrado',
                ], 404);
            }

            return response()->json([
                'ok' => true,
                'participante' => $perfil,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function obtenerPerfilPublicoDesdeQuery(Request $request)
    {
        return $this->obtenerPerfilPublico(
            $request,
            (string) $request->query('correo', '')
        );
    }

    public function enviarSolicitudContacto(Request $request)
    {
        try {
            $data = $request->only([
                'curso_edicion_id',
                'solicitante_correo',
                'destinatario_correo',
                'mensaje',
            ]);

            $solicitud = $this->service->enviarSolicitudContacto($data);

            return response()->json([
                'ok' => true,
                'message' => 'Solicitud de contacto enviada correctamente',
                'solicitud' => $solicitud,
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function responderSolicitudContacto(Request $request, $solicitudId)
    {
        try {
            if (empty($solicitudId)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'solicitud_id invalido',
                ], 400);
            }

            $destinatarioCorreo = (string) $request->input('destinatario_correo', '');
            $estado = (string) $request->input('estado', '');

            $solicitud = $this->service->responderSolicitudContacto(
                $solicitudId,
                $destinatarioCorreo,
                $estado
            );

            return response()->json([
                'ok' => true,
                'message' => 'Solicitud actualizada correctamente.',
                'estado' => $solicitud->estado ?? strtoupper(trim((string) $estado)),
                'solicitud' => $solicitud,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function consultarSolicitudesPorAlumno(Request $request, $correo)
    {
        try {
            if (empty($correo)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'correo invalido',
                ], 400);
            }

            $tipo = $request->query('tipo', 'RECIBIDAS');

            $solicitudes = $this->service->consultarSolicitudesPorAlumno(
                $correo,
                $tipo
            );

            return response()->json([
                'ok' => true,
                'tipo' => strtoupper(trim($tipo)),
                'solicitudes' => $solicitudes,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function consultarSolicitudesPorAlumnoDesdeQuery(Request $request)
    {
        return $this->consultarSolicitudesPorAlumno(
            $request,
            (string) $request->query('correo', '')
        );
    }

        public function crearCertificadoPendiente(Request $request)
    {
        try {
            $data = $request->only([
                'alumno_correo',
                'curso_edicion_id',
            ]);

            $certificado = $this->service->crearCertificadoPendiente($data);

            return response()->json([
                'ok' => true,
                'message' => 'Certificado creado correctamente',
                'certificado' => $certificado,
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function adjuntarCertificado(Request $request)
    {
        try {
            $archivo = $request->file('certificado');

            if (!$archivo) {
                return response()->json([
                    'ok' => false,
                    'message' => 'El archivo del certificado es obligatorio',
                ], 400);
            }

            $data = $request->only([
                'alumno_correo',
                'curso_edicion_id',
                'usuario_adjunta',
            ]);

            $certificado = $this->service->adjuntarCertificado($data, $archivo);

            return response()->json([
                'ok' => true,
                'message' => 'Certificado adjuntado correctamente',
                'certificado' => $certificado,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function enviarCertificado(Request $request, $certificadoId)
    {
        try {
            if (empty($certificadoId)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'certificado_id invalido',
                ], 400);
            }

            $usuarioEnvia = (string) $request->input('usuario_envia', '');

            $certificado = $this->service->enviarCertificado(
                (int) $certificadoId,
                $usuarioEnvia
            );

            return response()->json([
                'ok' => true,
                'message' => 'Certificado enviado correctamente',
                'certificado' => $certificado,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function listarCertificadosPorCursoEdicion(Request $request, $cursoEdicionId)
    {
        try {
            if (empty($cursoEdicionId)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'curso_edicion_id invalido',
                ], 400);
            }

            $detalle = $this->service->listarCertificadosPorCursoEdicion(
                (int) $cursoEdicionId
            );

            return response()->json(array_merge([
                'ok' => true,
            ], $detalle));

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function obtenerCertificadoAlumnoCurso(Request $request, $courseId)
    {
        try {
            if (empty($courseId)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'curso_edicion_id invalido',
                ], 400);
            }

            $correo = strtolower(trim((string) $request->header('X-USER-EMAIL', '')));

            if ($correo === '') {
                return response()->json([
                    'ok' => false,
                    'message' => 'correo requerido',
                ], 400);
            }

            return response()->json([
                'ok' => true,
                'certificado' => $this->service->obtenerCertificadoAlumnoCurso((int) $courseId, $correo),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function sincronizarDiplomasSgaCurso(Request $request, $cursoEdicionId)
    {
        try {
            if (empty($cursoEdicionId)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'curso_edicion_id invalido',
                ], 400);
            }

            $usuario = (string) $request->input('usuario_sincroniza', '');
            $result = $this->service->sincronizarDiplomasSgaCurso((int) $cursoEdicionId, $usuario);

            return response()->json([
                'ok' => true,
                'message' => 'Diplomas sincronizados correctamente',
                'sync' => $result,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function descargarCertificadoPublico(Request $request, $token)
    {
        try {
            if (empty($token)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'token invalido',
                ], 400);
            }

            $data = $this->service->obtenerCertificadoParaDescarga((string) $token);

            if (!Storage::disk('files')->exists($data['ruta'])) {
                return response()->json([
                    'ok' => false,
                    'message' => 'archivo no encontrado',
                ], 404);
            }

            return Storage::disk('files')->download(
                $data['ruta'],
                $data['nombre'],
                ['Content-Type' => $data['mime_type'] ?? 'application/pdf']
            );

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }
}
