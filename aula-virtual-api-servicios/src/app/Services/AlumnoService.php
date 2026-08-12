<?php

namespace App\Services;

use App\Helpers\HtmlSanitizer;
use App\Repositories\AlumnoRepository;
use App\Repositories\SgaDiplomaRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AlumnoService
{
    protected AlumnoRepository $repo;
    protected SgaDiplomaRepository $sgaDiplomas;
    protected CursoService $cursoService;

    public function __construct(AlumnoRepository $repo, SgaDiplomaRepository $sgaDiplomas, CursoService $cursoService)
    {
        $this->repo = $repo;
        $this->sgaDiplomas = $sgaDiplomas;
        $this->cursoService = $cursoService;
    }

    public function insertar(array $data)
    {
        $payload = $this->validarAlumno($data, true);

        $existe = $this->repo->obtenerPorCorreo($payload['correo']);

        if ($existe) {
            throw new \Exception('El alumno ya existe');
        }

        return $this->repo->insertar($payload);
    }

    public function actualizar(string $correo, array $data)
    {
        $correo = $this->normalizarCorreo($correo);

        if ($correo === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        $alumno = $this->obtenerOCrearDesdeFicha($correo);

        if (!$alumno) {
            throw new \Exception('El alumno no existe');
        }

        $data = array_merge([
            'correo_corporativo' => $alumno->correo_corporativo ?? null,
            'nombres' => $alumno->nombres ?? null,
            'apellidos' => $alumno->apellidos ?? null,
            'telefono' => $alumno->telefono ?? null,
            'fecha_nacimiento' => $alumno->fecha_nacimiento ?? null,
            'foto_url' => $alumno->foto_url ?? null,
            'presentacion_profesional' => $alumno->presentacion_profesional ?? null,
            'cv_url' => $alumno->cv_url ?? null,
            'linkedin_url' => $alumno->linkedin_url ?? null,
            'contacto_publico' => $alumno->contacto_publico ?? 0,
            'permite_solicitudes_contacto' => $alumno->permite_solicitudes_contacto ?? 1,
        ], $data);

        $payload = $this->validarAlumno($data, false);

        return $this->repo->actualizar($correo, $payload);
    }

    public function obtenerPorCorreo(string $correo)
    {
        $correo = $this->normalizarCorreo($correo);

        if ($correo === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        return $this->obtenerOCrearDesdeFicha($correo);
    }

    public function obtenerPerfilPublico(
        string $correoPerfil,
        ?string $cursoEdicionId = null,
        ?string $solicitanteCorreo = null
    ): ?array {
        $correoPerfil = $this->normalizarCorreo($correoPerfil);
        $cursoEdicionId = trim((string) ($cursoEdicionId ?? ''));
        $solicitanteCorreo = $this->normalizarCorreo($solicitanteCorreo);

        if ($correoPerfil === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        $alumno = $this->obtenerOCrearDesdeFicha($correoPerfil);

        if (!$alumno) {
            return null;
        }

        $nombres = trim((string) ($alumno->nombres ?? ''));
        $apellidos = trim((string) ($alumno->apellidos ?? ''));
        $nombreCompleto = trim($nombres . ' ' . $apellidos);
        $contactoPublico = (int) ($alumno->contacto_publico ?? 0);
        $puedeVerContacto = $contactoPublico === 1;

        if (!$puedeVerContacto && $cursoEdicionId !== '' && $solicitanteCorreo !== '') {
            $puedeVerContacto = $this->repo->tieneSolicitudAceptada(
                $cursoEdicionId,
                $solicitanteCorreo,
                $correoPerfil
            );
        }

        return [
            'correo' => $this->normalizarCorreo($alumno->correo ?? $correoPerfil),
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'nombre_completo' => $nombreCompleto,
            'foto_url' => $alumno->foto_url ?? null,
            'iniciales' => $this->generarIniciales($nombreCompleto),
            'presentacion_profesional' => $alumno->presentacion_profesional ?? null,
            'cv_url' => $alumno->cv_url ?? null,
            'contacto_publico' => $contactoPublico,
            'puede_ver_contacto' => $puedeVerContacto,
            'contacto' => $puedeVerContacto ? [
                'correo' => $this->normalizarCorreo($alumno->correo ?? $correoPerfil),
                'correo_corporativo' => $alumno->correo_corporativo ?? null,
                'telefono' => $alumno->telefono ?? null,
                'linkedin_url' => $alumno->linkedin_url ?? null,
            ] : null,
        ];
    }

    public function actualizarAdjuntosPerfil(string $correo, array $archivos)
    {
        $correo = $this->normalizarCorreo($correo);

        if ($correo === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        $alumno = $this->obtenerOCrearDesdeFicha($correo);

        if (!$alumno) {
            throw new \Exception('El alumno no existe');
        }

        $guardados = [];

        foreach (['foto', 'cv'] as $tipo) {
            $archivo = $archivos[$tipo] ?? null;

            if (!$archivo instanceof UploadedFile) {
                continue;
            }

            $this->validarAdjuntoPerfil($tipo, $archivo);
            $guardados[] = $this->guardarAdjuntoPerfilEnDisco($correo, $tipo, $archivo);
        }

        if (empty($guardados)) {
            return $this->obtenerPorCorreo($correo);
        }

        $data = [];

        foreach ($guardados as $guardado) {
            if (($guardado['tipo'] ?? '') === 'foto') {
                $data['foto_url'] = $guardado['ruta_archivo'];
            }

            if (($guardado['tipo'] ?? '') === 'cv') {
                $data['cv_url'] = $guardado['ruta_archivo'];
            }
        }

        try {
            $this->actualizar($correo, $data);
        } catch (\Throwable $e) {
            $this->eliminarAdjuntosFisicos($guardados);
            throw $e;
        }

        return $this->obtenerPorCorreo($correo);
    }

    public function obtenerAdjuntoPerfilParaDescarga(string $correo, string $tipo): array
    {
        $correo = $this->normalizarCorreo($correo);
        $tipo = strtolower(trim($tipo));

        if (!in_array($tipo, ['foto', 'cv'], true)) {
            throw new \Exception('Tipo de adjunto invalido');
        }

        $alumno = $this->obtenerPorCorreo($correo);
        $ruta = $tipo === 'foto'
            ? ($alumno->foto_url ?? null)
            : ($alumno->cv_url ?? null);

        if (empty($ruta)) {
            throw new \Exception('Adjunto no encontrado');
        }

        return [
            'ruta' => $ruta,
            'nombre' => basename((string) $ruta),
            'mime_type' => $tipo === 'foto'
                ? $this->obtenerMimeTypeFoto((string) $ruta)
                : 'application/pdf',
        ];
    }

    private function obtenerMimeTypeFoto(string $ruta): string
    {
        try {
            $mimeType = Storage::disk('files')->mimeType($ruta);

            if (is_string($mimeType) && str_starts_with($mimeType, 'image/')) {
                return $mimeType;
            }
        } catch (\Throwable) {
            // Si el adaptador no detecta MIME, inferimos por extension.
        }

        return match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/jpeg',
        };
    }

    public function enviarSolicitudContacto(array $data)
    {
        $cursoEdicionId = trim((string) ($data['curso_edicion_id'] ?? ''));
        $solicitanteCorreo = $this->normalizarCorreo($data['solicitante_correo'] ?? '');
        $destinatarioCorreo = $this->normalizarCorreo($data['destinatario_correo'] ?? '');
        $mensaje = trim((string) ($data['mensaje'] ?? ''));

        if ($cursoEdicionId === '') {
            throw new \Exception('El curso_edicion_id es obligatorio');
        }

        if ($solicitanteCorreo === '') {
            throw new \Exception('El correo del solicitante es obligatorio');
        }

        if ($destinatarioCorreo === '') {
            throw new \Exception('El correo del destinatario es obligatorio');
        }

        if ($solicitanteCorreo === $destinatarioCorreo) {
            throw new \Exception('No puedes solicitar contacto a tu propio usuario');
        }

        $solicitante = $this->obtenerOCrearDesdeFicha($solicitanteCorreo);

        if (!$solicitante) {
            throw new \Exception('El alumno solicitante no existe');
        }

        $destinatario = $this->obtenerOCrearDesdeFicha($destinatarioCorreo);

        if (!$destinatario) {
            throw new \Exception('El alumno destinatario no existe');
        }

        if ((int) ($destinatario->permite_solicitudes_contacto ?? 1) !== 1) {
            throw new \Exception('El alumno no permite solicitudes de contacto');
        }

        $pendiente = $this->repo->obtenerSolicitudPendiente(
            $cursoEdicionId,
            $solicitanteCorreo,
            $destinatarioCorreo
        );

        if ($pendiente) {
            throw new \Exception('Ya existe una solicitud pendiente para este alumno');
        }

        return $this->repo->crearSolicitudContacto([
            'curso_edicion_id' => $cursoEdicionId,
            'solicitante_correo' => $solicitanteCorreo,
            'destinatario_correo' => $destinatarioCorreo,
            'mensaje' => $mensaje !== '' ? $mensaje : null,
        ]);
    }

    public function responderSolicitudContacto(
        string $solicitudId,
        string $destinatarioCorreo,
        string $estado
    ) {
        $solicitudId = trim($solicitudId);
        $destinatarioCorreo = $this->normalizarCorreo($destinatarioCorreo);
        $estado = strtoupper(trim($estado));

        if ($solicitudId === '') {
            throw new \Exception('El id de la solicitud es obligatorio');
        }

        if ($destinatarioCorreo === '') {
            throw new \Exception('El correo del destinatario es obligatorio');
        }

        if (!in_array($estado, ['ACEPTADA', 'RECHAZADA'], true)) {
            throw new \Exception('El estado debe ser ACEPTADA o RECHAZADA');
        }

        $solicitud = $this->repo->obtenerSolicitudPorId($solicitudId);

        if (!$solicitud) {
            throw new \Exception('La solicitud no existe');
        }

        if (($solicitud->estado ?? '') !== 'PENDIENTE') {
            throw new \Exception('La solicitud ya fue respondida');
        }

        if ($this->normalizarCorreo($solicitud->destinatario_correo ?? '') !== $destinatarioCorreo) {
            throw new \Exception('No puedes responder una solicitud que no está dirigida a ti');
        }

        $actualizada = $this->repo->responderSolicitudContacto(
            $solicitudId,
            $destinatarioCorreo,
            $estado
        );

        if (!$actualizada) {
            throw new \Exception('No se pudo actualizar la solicitud');
        }

        return $this->repo->obtenerSolicitudPorId($solicitudId);
    }

    public function consultarSolicitudesPorAlumno(string $correo, string $tipo = 'RECIBIDAS'): array
    {
        $correo = $this->normalizarCorreo($correo);
        $tipo = strtoupper(trim($tipo));

        if ($correo === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        if (!in_array($tipo, ['RECIBIDAS', 'ENVIADAS'], true)) {
            throw new \Exception('El tipo debe ser RECIBIDAS o ENVIADAS');
        }

        $this->obtenerOCrearDesdeFicha($correo);

        return $this->repo->listarSolicitudesPorAlumno($correo, $tipo);
    }

    private function obtenerOCrearDesdeFicha(string $correo)
    {
        $correo = $this->normalizarCorreo($correo);

        $alumno = $this->repo->obtenerPorCorreo($correo);

        if ($alumno) {
            return $alumno;
        }

        $ficha = $this->repo->obtenerFichaInscripcionMasReciente($correo);

        if (!$ficha) {
            return null;
        }

        return $this->repo->insertar([
            'correo' => $this->normalizarCorreo($ficha->correo_personal ?? $correo),
            'correo_corporativo' => $this->limpiarTextoNullable($ficha->correo_corporativo ?? null),
            'nombres' => trim((string) ($ficha->nombres ?? '')),
            'apellidos' => trim((string) ($ficha->apellidos ?? '')),
            'telefono' => $this->limpiarTextoNullable($ficha->telefono ?? null),
            'fecha_nacimiento' => $this->limpiarFechaNullable($ficha->fecha_nacimiento ?? null),
            'foto_url' => null,
            'presentacion_profesional' => null,
            'cv_url' => null,
            'linkedin_url' => $this->limpiarTextoNullable($ficha->url_linkedin ?? null),
            'contacto_publico' => 0,
            'permite_solicitudes_contacto' => 1,
        ]);
    }

    private function validarAlumno(array $data, bool $requiereCorreo): array
    {
        $correo = $this->normalizarCorreo($data['correo'] ?? '');

        if ($requiereCorreo && $correo === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        $nombres = trim((string) ($data['nombres'] ?? ''));
        $apellidos = trim((string) ($data['apellidos'] ?? ''));

        if ($nombres === '') {
            throw new \Exception('Los nombres son obligatorios');
        }

        if ($apellidos === '') {
            throw new \Exception('Los apellidos son obligatorios');
        }

        $presentacion = trim((string) ($data['presentacion_profesional'] ?? ''));

        if ($presentacion !== '') {
            $presentacion = HtmlSanitizer::sanitizeQuillHtml($presentacion);
        }

        $payload = [
            'correo_corporativo' => $this->limpiarTextoNullable($data['correo_corporativo'] ?? null),
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'telefono' => $this->limpiarTextoNullable($data['telefono'] ?? null),
            'fecha_nacimiento' => $this->limpiarFechaNullable($data['fecha_nacimiento'] ?? null),
            'foto_url' => $this->limpiarTextoNullable($data['foto_url'] ?? null),
            'presentacion_profesional' => $presentacion !== '' ? $presentacion : null,
            'cv_url' => $this->limpiarTextoNullable($data['cv_url'] ?? null),
            'linkedin_url' => $this->limpiarTextoNullable($data['linkedin_url'] ?? null),
            'contacto_publico' => $this->boolToInt($data['contacto_publico'] ?? 0),
            'permite_solicitudes_contacto' => $this->boolToInt($data['permite_solicitudes_contacto'] ?? 1),
        ];

        if ($requiereCorreo) {
            $payload['correo'] = $correo;
        }

        return $payload;
    }

    private function normalizarCorreo(?string $correo): string
    {
        return strtolower(trim((string) $correo));
    }

    private function limpiarTextoNullable($valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto !== '' ? $texto : null;
    }

    private function limpiarFechaNullable($valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        $fecha = trim((string) ($valor ?? ''));

        if ($fecha === '') {
            return null;
        }

        foreach (['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'j/n/Y', 'd/m/Y H:i:s', 'j/n/Y H:i:s'] as $format) {
            $date = \DateTime::createFromFormat($format, $fecha);

            if ($date instanceof \DateTime) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function boolToInt($valor): int
    {
        return filter_var($valor, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    private function generarIniciales(string $nombreCompleto): string
    {
        $partes = preg_split('/\s+/', trim($nombreCompleto)) ?: [];
        $iniciales = '';

        foreach ($partes as $parte) {
            if ($parte === '') {
                continue;
            }

            $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1));

            if (mb_strlen($iniciales) >= 2) {
                break;
            }
        }

        return $iniciales;
    }

    private function validarAdjuntoPerfil(string $tipo, UploadedFile $archivo): void
    {
        $extension = mb_strtolower((string) $archivo->getClientOriginalExtension());
        $maxBytes = $tipo === 'foto' ? 5 * 1024 * 1024 : 10 * 1024 * 1024;
        $permitidas = $tipo === 'foto' ? ['jpg', 'jpeg', 'png'] : ['pdf'];

        if (!in_array($extension, $permitidas, true)) {
            throw new \Exception($tipo === 'foto'
                ? 'La foto debe ser JPG o PNG'
                : 'El CV debe ser PDF');
        }

        if (($archivo->getSize() ?? 0) > $maxBytes) {
            throw new \Exception($tipo === 'foto'
                ? 'La foto no debe superar los 5 MB'
                : 'El CV no debe superar los 10 MB');
        }
    }

    private function guardarAdjuntoPerfilEnDisco(
        string $correo,
        string $tipo,
        UploadedFile $archivo
    ): array {
        $extension = mb_strtolower((string) $archivo->getClientOriginalExtension());
        $folder = 'alumnos/' . sha1($correo);
        $filename = $tipo . '-' . Str::uuid()->toString() . ($extension ? '.' . $extension : '');
        $ruta = Storage::disk('files')->putFileAs($folder, $archivo, $filename);

        return [
            'tipo' => $tipo,
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta_archivo' => $ruta,
            'peso_bytes' => $archivo->getSize(),
            'mime_type' => $archivo->getMimeType(),
        ];
    }

    private function eliminarAdjuntosFisicos(array $adjuntos): void
    {
        foreach ($adjuntos as $adjunto) {
            $ruta = $adjunto['ruta_archivo'] ?? null;

            if ($ruta) {
                Storage::disk('files')->delete($ruta);
            }
        }
    }

    public function crearCertificadoPendiente(array $data)
    {
        $correo = $this->normalizarCorreo($data['alumno_correo'] ?? '');
        $cursoEdicionId = (int) ($data['curso_edicion_id'] ?? 0);

        if ($correo === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        if ($cursoEdicionId <= 0) {
            throw new \Exception('El curso_edicion_id es obligatorio');
        }

        $existente = $this->repo->obtenerCertificadoPorAlumnoCurso($correo, $cursoEdicionId);

        if ($existente) {
            return $this->formatearCertificadoParaRespuesta($existente);
        }

        $certificado = $this->repo->crearCertificado([
            'alumno_correo' => $correo,
            'curso_edicion_id' => $cursoEdicionId,
            'estado' => 'pendiente',
        ]);

        return $this->formatearCertificadoParaRespuesta($certificado);
    }

    public function adjuntarCertificado(array $data, UploadedFile $archivo)
    {
        $correo = $this->normalizarCorreo($data['alumno_correo'] ?? '');
        $cursoEdicionId = (int) ($data['curso_edicion_id'] ?? 0);
        $usuarioAdjunta = $this->normalizarCorreo($data['usuario_adjunta'] ?? '');

        if ($correo === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        if ($cursoEdicionId <= 0) {
            throw new \Exception('El curso_edicion_id es obligatorio');
        }

        if ($usuarioAdjunta === '') {
            throw new \Exception('El usuario que adjunta es obligatorio');
        }

        $this->validarArchivoCertificado($archivo);

        $certificado = $this->repo->obtenerCertificadoPorAlumnoCurso($correo, $cursoEdicionId);

        if (!$certificado) {
            $certificado = $this->repo->crearCertificado([
                'alumno_correo' => $correo,
                'curso_edicion_id' => $cursoEdicionId,
                'estado' => 'pendiente',
            ]);
        }

        if (($certificado->estado ?? '') === 'enviado') {
            throw new \Exception('No se puede reemplazar un certificado enviado');
        }

        $guardado = $this->guardarCertificadoEnDisco($correo, $cursoEdicionId, $archivo);

        try {
            $certificado = $this->repo->adjuntarCertificado((int) $certificado->id, [
                'archivo_nombre' => $guardado['nombre_original'],
                'archivo_ruta' => $guardado['ruta_archivo'],
                'archivo_mime' => $guardado['mime_type'],
                'archivo_peso' => $guardado['peso_bytes'],
                'usuario_adjunta' => $usuarioAdjunta,
            ]);

            return $this->formatearCertificadoParaRespuesta($certificado);
        } catch (\Throwable $e) {
            Storage::disk('files')->delete($guardado['ruta_archivo']);
            throw $e;
        }
    }

    public function enviarCertificado(int $certificadoId, string $usuarioEnvia)
    {
        $usuarioEnvia = $this->normalizarCorreo($usuarioEnvia);

        if ($certificadoId <= 0) {
            throw new \Exception('El id del certificado es obligatorio');
        }

        if ($usuarioEnvia === '') {
            throw new \Exception('El usuario que envia es obligatorio');
        }

        $certificado = $this->repo->obtenerCertificadoPorId($certificadoId);

        if (!$certificado) {
            throw new \Exception('El certificado no existe');
        }

        if (!in_array((string) ($certificado->estado ?? ''), ['adjuntado', 'generado'], true)) {
            throw new \Exception('Solo se puede enviar un certificado generado o adjuntado');
        }

        if (empty($certificado->link_publico)) {
            throw new \Exception('El certificado no tiene link publico');
        }

        if (empty($certificado->alumno_correo)) {
            throw new \Exception('El certificado no tiene alumno_correo');
        }

        $correoPruebas = trim((string) env('ENVIO_CERTIFICADOS_CORREO_PRUEBAS', ''));
        $destinatario = $correoPruebas !== ''
            ? $correoPruebas
            : $this->normalizarCorreo($certificado->alumno_correo ?? '');
        $linkPublicoCorreo = $this->normalizarLinkPublicoCertificado($certificado);

        try {
            Log::info('Enviando correo de certificado', [
                'certificado_id' => $certificadoId,
                'alumno_correo' => $certificado->alumno_correo,
                'correo_destino' => $destinatario,
                'usa_correo_pruebas' => $correoPruebas !== '',
                'asunto' => 'Tu certificado ya está disponible',
                'link_publico' => $linkPublicoCorreo,
            ]);

            $this->enviarCorreoCertificado($linkPublicoCorreo, $destinatario);
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de certificado', [
                'certificado_id' => $certificadoId,
                'alumno_correo' => $certificado->alumno_correo,
                'correo_destino' => $destinatario,
                'usa_correo_pruebas' => $correoPruebas !== '',
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('No se pudo enviar el correo del certificado');
        }

        return $this->formatearCertificadoParaRespuesta(
            $this->repo->enviarCertificado($certificadoId, $usuarioEnvia)
        );
    }

    public function obtenerCertificadoPorToken(string $token)
    {
        $token = trim($token);

        if ($token === '') {
            throw new \Exception('El token es obligatorio');
        }

        $certificado = $this->repo->obtenerCertificadoPorToken($token);

        if (!$certificado) {
            throw new \Exception('Certificado no encontrado');
        }

        if (empty($certificado->archivo_ruta)) {
            throw new \Exception('El certificado no tiene archivo adjunto');
        }

        if (!Storage::disk('files')->exists($certificado->archivo_ruta)) {
            throw new \Exception('El archivo del certificado no existe');
        }

        return $certificado;
    }

    public function obtenerCertificadoParaDescarga(string $token): array
    {
        $certificado = $this->obtenerCertificadoPorToken($token);

        return [
            'ruta' => $certificado->archivo_ruta,
            'nombre' => $certificado->archivo_nombre ?: basename((string) $certificado->archivo_ruta),
            'mime_type' => $certificado->archivo_mime ?: $this->inferirMimeCertificado((string) $certificado->archivo_ruta),
        ];
    }

    private function inferirMimeCertificado(string $ruta): string
    {
        $extension = mb_strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    public function listarCertificadosPorCursoEdicion(int $cursoEdicionId): array
    {
        if ($cursoEdicionId <= 0) {
            throw new \Exception('El curso_edicion_id es obligatorio');
        }

        $participantes = $this->cursoService->listarAlumnosCurso($cursoEdicionId, '');
        $curso = $this->obtenerDatosCursoCertificados($cursoEdicionId, $participantes);
        $certificados = $this->repo->listarCertificadosPorCursoEdicion($cursoEdicionId);
        $sga = $this->sgaDiplomas->listarDiplomasPorCurso($cursoEdicionId, $curso, $participantes);
        $diplomas = $sga['items'] ?? [];
        $certificadosPorCorreo = [];
        $diplomasPorCorreo = [];
        $diplomasPorNombre = [];

        foreach ($certificados as $certificado) {
            $correo = $this->normalizarCorreo($certificado->alumno_correo ?? '');

            if ($correo !== '' && !isset($certificadosPorCorreo[$correo])) {
                $certificadosPorCorreo[$correo] = $certificado;
            }
        }

        foreach ($diplomas as $diploma) {
            $correo = $this->normalizarCorreo($diploma['student_email'] ?? '');
            $nombre = $this->normalizarNombre($diploma['student_name'] ?? '');

            if ($correo !== '' && !isset($diplomasPorCorreo[$correo])) {
                $diplomasPorCorreo[$correo] = $diploma;
            }

            if ($nombre !== '' && !isset($diplomasPorNombre[$nombre])) {
                $diplomasPorNombre[$nombre] = $diploma;
            }
        }

        $alumnos = [];
        $enviados = 0;
        $generados = 0;
        $requierenRevision = 0;
        $diplomasAsociados = [];

        foreach ($participantes as $participante) {
            $correo = $this->normalizarCorreo($participante->CORREO_PERSONAL ?? '');
            $certificado = $certificadosPorCorreo[$correo] ?? null;
            $nombres = trim((string) ($participante->NOMBRES ?? ''));
            $apellidos = trim((string) ($participante->APELLIDOS ?? ''));
            $nombreCompleto = trim((string) ($participante->alumno ?? ''));

            if ($nombreCompleto === '') {
                $nombreCompleto = trim($nombres . ' ' . $apellidos);
            }

            $diploma = $diplomasPorCorreo[$correo] ?? $diplomasPorNombre[$this->normalizarNombre($nombreCompleto)] ?? null;
            $estado = $this->resolverEstadoCertificado($certificado, $diploma);

            if ($diploma && isset($diploma['diploma_id'])) {
                $diplomasAsociados[(string) $diploma['diploma_id']] = true;
            }

            if ($estado === 'enviado') {
                $enviados++;
            }

            if ($diploma && in_array($estado, ['generado', 'enviado'], true)) {
                $generados++;
            }

            if ($estado === 'requiere_revision') {
                $requierenRevision++;
            }

            $alumnos[] = [
                'certificado_id' => $certificado ? (int) $certificado->id : null,
                'alumno_correo' => $correo,
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'nombre_completo' => $nombreCompleto,
                'estado' => $estado,
                'archivo_nombre' => $certificado->archivo_nombre ?? null,
                'link_publico' => $certificado->link_publico ?? ($diploma['public_url'] ?? null),
                'fecha_adjunta' => $certificado->fecha_adjunta ?? null,
                'usuario_adjunta' => $certificado->usuario_adjunta ?? null,
                'fecha_envia' => $certificado->fecha_envia ?? ($diploma['sent_at'] ?? null),
                'usuario_envia' => $certificado->usuario_envia ?? null,
                'source' => $diploma ? 'sga_diplomas' : ($certificado ? 'aula_virtual' : null),
                'diploma' => $diploma,
            ];
        }

        $total = count($alumnos);

        return [
            'curso' => $curso,
            'resumen' => [
                'total_certificados' => $total,
                'diplomas_generados' => $generados,
                'certificados_enviados' => $enviados,
                'certificados_pendientes' => max(0, $generados - $enviados),
                'sin_diploma' => max(0, $total - $generados),
                'requieren_revision' => $requierenRevision,
                'sga_disponible' => (bool) ($sga['available'] ?? false),
                'sga_message' => $sga['message'] ?? null,
                'sga_config' => $sga['config'] ?? [],
                'sga_diplomas_detectados' => count($diplomas),
                'sga_diplomas_sin_identificar' => max(0, count($diplomas) - count($diplomasAsociados)),
            ],
            'alumnos' => $alumnos,
        ];
    }

    public function obtenerCertificadoAlumnoCurso(int $cursoEdicionId, string $correoAlumno): array
    {
        if ($cursoEdicionId <= 0) {
            throw new \Exception('El curso_edicion_id es obligatorio');
        }

        $correoAlumno = $this->normalizarCorreo($correoAlumno);

        if ($correoAlumno === '') {
            throw new \Exception('El correo del alumno es obligatorio');
        }

        $participantes = $this->cursoService->listarAlumnosCurso($cursoEdicionId, $correoAlumno);
        $participante = collect($participantes)->first(function ($item) use ($correoAlumno) {
            return $this->normalizarCorreo($item->CORREO_PERSONAL ?? '') === $correoAlumno
                || $this->normalizarCorreo($item->correo_corporativo ?? '') === $correoAlumno;
        });

        if (!$participante) {
            throw new \Exception('No tienes acceso a este curso');
        }

        $curso = $this->obtenerDatosCursoCertificados($cursoEdicionId, $participantes);
        $cursoData = $this->cursoService->obtener($cursoEdicionId);
        $certificado = $this->repo->obtenerCertificadoPorAlumnoCurso($correoAlumno, $cursoEdicionId);
        $sga = $this->sgaDiplomas->listarDiplomasPorCurso($cursoEdicionId, $curso, $participantes);
        $nombreAlumno = trim((string) ($participante->alumno ?? ''));

        if ($nombreAlumno === '') {
            $nombreAlumno = trim((string) ($participante->NOMBRES ?? '') . ' ' . (string) ($participante->APELLIDOS ?? ''));
        }

        $diploma = null;
        $nombreNormalizado = $this->normalizarNombre($nombreAlumno);
        $diplomasPorNombre = [];

        foreach (($sga['items'] ?? []) as $item) {
            $correoDiploma = $this->normalizarCorreo($item['student_email'] ?? '');
            $nombreDiploma = $this->normalizarNombre($item['student_name'] ?? '');

            if ($correoDiploma !== '' && $correoDiploma === $correoAlumno) {
                $diploma = $item;
                break;
            }

            if ($correoDiploma === '' && $nombreDiploma !== '' && $nombreDiploma === $nombreNormalizado) {
                $diplomasPorNombre[] = $item;
            }
        }

        if (!$diploma && count($diplomasPorNombre) === 1) {
            $diploma = $diplomasPorNombre[0];
        }

        $estado = $this->resolverEstadoCertificado($certificado, $diploma);
        $cursoFinalizado = in_array(
            mb_strtolower(trim((string) ($cursoData->estadocurso ?? $cursoData->estado ?? ''))),
            ['finalizado', 'completado', 'completed'],
            true
        );

        $publicUrl = trim((string) ($certificado->link_publico ?? ''));
        if ($publicUrl !== '') {
            $publicUrl = $this->normalizarLinkPublicoCertificado($certificado);
        }

        if ($publicUrl === '') {
            $publicUrl = trim((string) ($diploma['public_url'] ?? $diploma['file_url'] ?? $diploma['image_url'] ?? ''));
        }

        $previewUrl = trim((string) ($diploma['image_url'] ?? $diploma['public_url'] ?? $publicUrl));
        $downloadUrl = trim((string) ($diploma['file_url'] ?? $publicUrl));
        [$status, $label, $message] = $this->presentarCertificadoAlumno($estado, $cursoFinalizado, $publicUrl !== '');

        return [
            'status' => $status,
            'label' => $label,
            'code' => trim((string) ($diploma['code'] ?? '')) ?: null,
            'sent_at' => $certificado->fecha_envia ?? ($diploma['sent_at'] ?? null),
            'public_url' => $publicUrl !== '' ? $publicUrl : null,
            'download_url' => $downloadUrl !== '' ? $downloadUrl : null,
            'preview_url' => $previewUrl !== '' ? $previewUrl : null,
            'message' => $message,
        ];
    }

    private function presentarCertificadoAlumno(string $estado, bool $cursoFinalizado, bool $tieneUrl): array
    {
        if ($estado === 'enviado' && $tieneUrl) {
            return ['enviado', 'Enviado', 'Tu certificado ya fue enviado y esta disponible para consulta.'];
        }

        if (in_array($estado, ['generado', 'adjuntado'], true) && $tieneUrl) {
            return ['disponible', 'Disponible', 'Tu certificado esta listo para visualizar y descargar.'];
        }

        if ($estado === 'requiere_revision') {
            return ['requiere_revision', 'En revision', 'Estamos revisando tu certificado antes de publicarlo.'];
        }

        if ($cursoFinalizado) {
            return ['en_preparacion', 'En preparacion', 'Estamos preparando tu certificado. Aparecera aqui cuando este disponible.'];
        }

        return ['no_disponible', 'No disponible', 'El certificado estara disponible cuando completes el curso.'];
    }

    public function sincronizarDiplomasSgaCurso(int $cursoEdicionId, string $usuario): array
    {
        if ($cursoEdicionId <= 0) {
            throw new \Exception('El curso_edicion_id es obligatorio');
        }

        $usuario = $this->normalizarCorreo($usuario);

        if ($usuario === '') {
            throw new \Exception('El usuario que sincroniza es obligatorio');
        }

        $participantes = $this->cursoService->listarAlumnosCurso($cursoEdicionId, '');
        $curso = $this->obtenerDatosCursoCertificados($cursoEdicionId, $participantes);
        $sga = $this->sgaDiplomas->listarDiplomasPorCurso($cursoEdicionId, $curso, $participantes);

        if (!($sga['available'] ?? false)) {
            throw new \Exception($sga['message'] ?? 'No se pudo consultar SGA');
        }

        $diplomas = $sga['items'] ?? [];
        $diplomasPorCorreo = [];
        $diplomasPorNombre = [];

        foreach ($diplomas as $diploma) {
            $correo = $this->normalizarCorreo($diploma['student_email'] ?? '');
            $nombre = $this->normalizarNombre($diploma['student_name'] ?? '');

            if ($correo !== '') {
                $diplomasPorCorreo[$correo] = $diploma;
            }

            if ($nombre !== '') {
                $diplomasPorNombre[$nombre] = $diploma;
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($participantes as $participante) {
            $correo = $this->normalizarCorreo($participante->CORREO_PERSONAL ?? '');
            $nombreCompleto = trim((string) ($participante->alumno ?? ''));

            if ($nombreCompleto === '') {
                $nombreCompleto = trim((string) ($participante->NOMBRES ?? '') . ' ' . (string) ($participante->APELLIDOS ?? ''));
            }

            $diploma = $diplomasPorCorreo[$correo] ?? $diplomasPorNombre[$this->normalizarNombre($nombreCompleto)] ?? null;
            $publicUrl = trim((string) ($diploma['public_url'] ?? ''));

            if ($correo === '' || !$diploma || $publicUrl === '') {
                $skipped++;
                continue;
            }

            $existente = $this->repo->obtenerCertificadoPorAlumnoCurso($correo, $cursoEdicionId);
            $estado = ($diploma['status'] ?? '') === 'enviado' ? 'enviado' : 'adjuntado';
            $payload = [
                'alumno_correo' => $correo,
                'curso_edicion_id' => $cursoEdicionId,
                'archivo_nombre' => $this->nombreArchivoDiploma($diploma),
                'archivo_ruta' => null,
                'archivo_mime' => null,
                'archivo_peso' => null,
                'link_publico' => $publicUrl,
                'estado' => $estado,
                'usuario_adjunta' => $usuario,
                'fecha_adjunta' => date('Y-m-d H:i:s'),
            ];

            if ($estado === 'enviado') {
                $payload['usuario_envia'] = $usuario;
                $payload['fecha_envia'] = $diploma['sent_at'] ?? date('Y-m-d H:i:s');
            }

            if ($existente) {
                $this->repo->actualizarCertificado((int) $existente->id, $payload);
                $updated++;
            } else {
                $this->repo->crearCertificado($payload);
                $created++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function resolverEstadoCertificado($certificado, ?array $diploma): string
    {
        $estadoCertificado = (string) ($certificado->estado ?? '');

        if ($estadoCertificado === 'enviado' || ($diploma['status'] ?? '') === 'enviado') {
            return 'enviado';
        }

        if ($estadoCertificado === 'adjuntado' || ($diploma['status'] ?? '') === 'generado') {
            return 'generado';
        }

        if (($diploma['status'] ?? '') === 'requiere_revision') {
            return 'requiere_revision';
        }

        return 'pendiente';
    }

    private function nombreArchivoDiploma(array $diploma): string
    {
        $code = trim((string) ($diploma['code'] ?? ''));

        if ($code !== '') {
            return 'Diploma ' . $code;
        }

        $id = (int) ($diploma['diploma_id'] ?? 0);

        return $id > 0 ? 'Diploma SGA #' . $id : 'Diploma SGA';
    }

    private function normalizarNombre(mixed $nombre): string
    {
        $nombre = mb_strtolower(trim((string) ($nombre ?? '')));
        $nombre = preg_replace('/\s+/', ' ', $nombre) ?: '';

        return $nombre;
    }

    private function obtenerDatosCursoCertificados(int $cursoEdicionId, array $participantes): array
    {
        $primerParticipante = $participantes[0] ?? null;

        if ($primerParticipante) {
            return [
                'curso_edicion_id' => (int) ($primerParticipante->curso_edicion_id ?? $cursoEdicionId),
                'nombre' => $primerParticipante->curso ?? null,
                'docente' => $primerParticipante->docente ?? null,
                'horario' => $primerParticipante->horario ?? null,
            ];
        }

        $curso = $this->cursoService->obtener($cursoEdicionId);

        return [
            'curso_edicion_id' => $cursoEdicionId,
            'nombre' => $curso->curso ?? $curso->nombre ?? null,
            'docente' => $curso->docente ?? null,
            'horario' => $curso->horario ?? null,
        ];
    }

    private function enviarCorreoCertificado(string $linkPublico, string $destinatario): void
    {
        if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Correo destinatario invalido');
        }

        $linkPublico = trim($linkPublico);

        if ($linkPublico === '') {
            throw new \Exception('El certificado no tiene link publico');
        }

        $html = $this->construirHtmlCorreoCertificado($linkPublico);
        $this->enviarSmtpHtml($destinatario, 'Tu certificado ya está disponible', $html);
    }

    private function normalizarLinkPublicoCertificado($certificado): string
    {
        $linkPublico = trim((string) ($certificado->link_publico ?? ''));

        if ($linkPublico === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $linkPublico)) {
            return $linkPublico;
        }

        $token = trim((string) ($certificado->token ?? ''));
        $path = trim($linkPublico, '/');

        if ($token === '') {
            if (stripos($path, 'Certificados/') === 0) {
                $token = substr($path, strlen('Certificados/'));
            } else {
                $token = basename($path);
            }
        }

        $baseUrl = rtrim((string) env('CERTIFICADO_PUBLIC_BASE_URL', ''), '/');

        if ($baseUrl === '') {
            $baseUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/Certificados';
        }

        return $baseUrl . '/' . ltrim($token, '/');
    }

    private function construirHtmlCorreoCertificado(string $linkPublico): string
    {
        $link = htmlspecialchars($linkPublico, ENT_QUOTES, 'UTF-8');

        return implode("\n", [
            '<p>Hola,</p>',
            '<p>Nos complace informarte que tu certificado ya se encuentra disponible.</p>',
            '<p>Puedes visualizarlo y descargarlo desde el siguiente enlace:</p>',
            '<p><a href="' . $link . '" target="_blank" rel="noopener noreferrer">Ver y descargar certificado</a></p>',
            '<p>Conserva este enlace para futuras consultas.</p>',
            '<p>Saludos,<br>Aula Virtual</p>',
        ]);
    }

    private function enviarSmtpHtml(string $destinatario, string $asunto, string $html): void
    {
        $mailer = strtolower(trim((string) env('MAIL_MAILER', 'smtp')));
        $host = trim((string) env('MAIL_HOST', ''));
        $port = (int) env('MAIL_PORT', 587);
        $username = trim((string) env('MAIL_USERNAME', ''));
        $password = (string) env('MAIL_PASSWORD', '');
        $encryption = strtolower(trim((string) env('MAIL_ENCRYPTION', 'tls')));
        $fromAddress = trim((string) env('MAIL_FROM_ADDRESS', $username));
        $fromName = trim((string) env('MAIL_FROM_NAME', 'Aula Virtual'));

        if ($mailer !== 'smtp') {
            throw new \Exception('MAIL_MAILER no soportado');
        }

        if ($host === '' || $port <= 0 || $fromAddress === '') {
            throw new \Exception('Configuracion SMTP incompleta');
        }

        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('MAIL_FROM_ADDRESS invalido');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

        if (!$socket) {
            throw new \Exception('No se pudo conectar al servidor SMTP: ' . $errstr);
        }

        stream_set_timeout($socket, 20);

        try {
            $this->leerRespuestaSmtp($socket, [220]);
            $this->enviarComandoSmtp($socket, 'EHLO ' . $this->obtenerHostEhlo(), [250]);

            if ($encryption === 'tls') {
                $this->enviarComandoSmtp($socket, 'STARTTLS', [220]);

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \Exception('No se pudo iniciar TLS SMTP');
                }

                $this->enviarComandoSmtp($socket, 'EHLO ' . $this->obtenerHostEhlo(), [250]);
            }

            if ($username !== '') {
                $this->enviarComandoSmtp($socket, 'AUTH LOGIN', [334]);
                $this->enviarComandoSmtp($socket, base64_encode($username), [334]);
                $this->enviarComandoSmtp($socket, base64_encode($password), [235]);
            }

            $this->enviarComandoSmtp($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
            $this->enviarComandoSmtp($socket, 'RCPT TO:<' . $destinatario . '>', [250, 251]);
            $this->enviarComandoSmtp($socket, 'DATA', [354]);

            fwrite($socket, $this->construirMensajeSmtp(
                $fromAddress,
                $fromName,
                $destinatario,
                $asunto,
                $html
            ));

            $this->leerRespuestaSmtp($socket, [250]);
            $this->enviarComandoSmtp($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function construirMensajeSmtp(
        string $fromAddress,
        string $fromName,
        string $destinatario,
        string $asunto,
        string $html
    ): string {
        $fromName = trim(str_replace(["\r", "\n"], '', $fromName));
        $asunto = trim(str_replace(["\r", "\n"], '', $asunto));
        $html = preg_replace('/^\./m', '..', $html);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $this->codificarHeaderCorreo($fromName) . ' <' . $fromAddress . '>',
            'To: <' . $destinatario . '>',
            'Subject: ' . $this->codificarHeaderCorreo($asunto),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Message-ID: <' . Str::uuid()->toString() . '@' . $this->obtenerHostEhlo() . '>',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n.\r\n";
    }

    private function enviarComandoSmtp($socket, string $comando, array $codigosEsperados): string
    {
        fwrite($socket, $comando . "\r\n");

        return $this->leerRespuestaSmtp($socket, $codigosEsperados);
    }

    private function leerRespuestaSmtp($socket, array $codigosEsperados): string
    {
        $respuesta = '';

        while (($linea = fgets($socket, 515)) !== false) {
            $respuesta .= $linea;

            if (strlen($linea) >= 4 && $linea[3] === ' ') {
                break;
            }
        }

        $codigo = (int) substr($respuesta, 0, 3);

        if (!in_array($codigo, $codigosEsperados, true)) {
            throw new \Exception('Respuesta SMTP inesperada: ' . trim($respuesta));
        }

        return $respuesta;
    }

    private function codificarHeaderCorreo(string $valor): string
    {
        return '=?UTF-8?B?' . base64_encode($valor) . '?=';
    }

    private function obtenerHostEhlo(): string
    {
        return parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'localhost';
    }

    private function formatearCertificadoParaRespuesta($certificado): array
    {
        return [
            'id' => (int) ($certificado->id ?? 0),
            'certificado_id' => (int) ($certificado->id ?? 0),
            'alumno_correo' => $this->normalizarCorreo($certificado->alumno_correo ?? ''),
            'curso_edicion_id' => (int) ($certificado->curso_edicion_id ?? 0),
            'estado' => $certificado->estado ?? 'pendiente',
            'archivo_nombre' => $certificado->archivo_nombre ?? null,
            'link_publico' => $certificado->link_publico ?? null,
            'fecha_adjunta' => $certificado->fecha_adjunta ?? null,
            'usuario_adjunta' => $certificado->usuario_adjunta ?? null,
            'fecha_envia' => $certificado->fecha_envia ?? null,
            'usuario_envia' => $certificado->usuario_envia ?? null,
        ];
    }

    private function validarArchivoCertificado(UploadedFile $archivo): void
    {
        if (method_exists($archivo, 'isValid') && !$archivo->isValid()) {
            throw new \Exception('El archivo del certificado no es valido');
        }

        $extension = mb_strtolower((string) $archivo->getClientOriginalExtension());
        $mimeType = mb_strtolower((string) $archivo->getMimeType());
        $allowedExtensions = ['jpg', 'jpeg', 'png'];
        $allowedMimeTypes = ['image/jpeg', 'image/png'];

        if (!in_array($extension, $allowedExtensions, true) || !in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \Exception('El certificado debe ser una imagen JPG o PNG');
        }

        if (($archivo->getSize() ?? 0) > 10 * 1024 * 1024) {
            throw new \Exception('El certificado no debe superar los 10 MB');
        }
    }

    private function guardarCertificadoEnDisco(
        string $correo,
        int $cursoEdicionId,
        UploadedFile $archivo
    ): array {
        $folder = 'certificados/' . $cursoEdicionId . '/' . sha1($correo);
        $extension = mb_strtolower((string) $archivo->getClientOriginalExtension());
        $filename = 'certificado-' . Str::uuid()->toString() . '.' . $extension;
        $ruta = Storage::disk('files')->putFileAs($folder, $archivo, $filename);

        return [
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta_archivo' => $ruta,
            'peso_bytes' => $archivo->getSize(),
            'mime_type' => $archivo->getMimeType() ?: 'image/jpeg',
        ];
    }
}
