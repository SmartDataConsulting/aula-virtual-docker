<?php

namespace App\Repositories;

use App\Helpers\DbSafe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AlumnoRepository
{
    public function insertar(array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($data) {
            $conn = DB::connection('mysql_cursos');

            $correo = strtolower(trim($data['correo']));

            $conn->insert("
                INSERT INTO alumno (
                    correo,
                    correo_corporativo,
                    nombres,
                    apellidos,
                    telefono,
                    fecha_nacimiento,
                    foto_url,
                    presentacion_profesional,
                    cv_url,
                    linkedin_url,
                    contacto_publico,
                    permite_solicitudes_contacto,
                    fecha_creacion,
                    fecha_actualizacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $correo,
                $data['correo_corporativo'] ?? null,
                $data['nombres'],
                $data['apellidos'],
                $data['telefono'] ?? null,
                $data['fecha_nacimiento'] ?? null,
                $data['foto_url'] ?? null,
                $data['presentacion_profesional'] ?? null,
                $data['cv_url'] ?? null,
                $data['linkedin_url'] ?? null,
                (int) ($data['contacto_publico'] ?? 0),
                (int) ($data['permite_solicitudes_contacto'] ?? 1),
            ]);

            return $this->obtenerPorCorreo($correo);
        });
    }

    public function actualizar(string $correo, array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($correo, $data) {
            $conn = DB::connection('mysql_cursos');

            $correo = strtolower(trim($correo));

            $conn->update("
                UPDATE alumno
                SET
                    correo_corporativo = ?,
                    nombres = ?,
                    apellidos = ?,
                    telefono = ?,
                    fecha_nacimiento = ?,
                    foto_url = ?,
                    presentacion_profesional = ?,
                    cv_url = ?,
                    linkedin_url = ?,
                    contacto_publico = ?,
                    permite_solicitudes_contacto = ?,
                    fecha_actualizacion = NOW()
                WHERE LOWER(TRIM(correo)) = LOWER(TRIM(?))
            ", [
                $data['correo_corporativo'] ?? null,
                $data['nombres'],
                $data['apellidos'],
                $data['telefono'] ?? null,
                $data['fecha_nacimiento'] ?? null,
                $data['foto_url'] ?? null,
                $data['presentacion_profesional'] ?? null,
                $data['cv_url'] ?? null,
                $data['linkedin_url'] ?? null,
                (int) ($data['contacto_publico'] ?? 0),
                (int) ($data['permite_solicitudes_contacto'] ?? 1),
                $correo,
            ]);

            return $this->obtenerPorCorreo($correo);
        });
    }

    public function obtenerPorCorreo(string $correo)
    {
        $correoNormalizado = strtolower(trim($correo));

        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                correo,
                correo_corporativo,
                nombres,
                apellidos,
                telefono,
                fecha_nacimiento,
                foto_url,
                presentacion_profesional,
                cv_url,
                linkedin_url,
                contacto_publico,
                permite_solicitudes_contacto,
                fecha_creacion,
                fecha_actualizacion
            FROM alumno
            WHERE LOWER(TRIM(correo)) = LOWER(TRIM(?))
            LIMIT 1
        ", [
            $correoNormalizado,
        ]);

        return $rows[0] ?? null;
    }

    public function obtenerFichaInscripcionMasReciente(string $correo)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                nombres,
                apellidos,
                correo_personal,
                correo_corporativo,
                url_linkedin,
                telefono,
                fecha_nacimiento
            FROM Ficha_inscripcion
            WHERE LOWER(TRIM(correo_personal)) = LOWER(TRIM(?))
            ORDER BY STR_TO_DATE(Marca_Temporal, '%d/%m/%Y %H:%i:%s') DESC
            LIMIT 1
        ", [
            strtolower(trim($correo)),
        ]);

        return $rows[0] ?? null;
    }

    public function crearSolicitudContacto(array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($data) {
            $conn = DB::connection('mysql_cursos');

            $id = Str::uuid()->toString();

            $conn->insert("
                INSERT INTO solicitud_contacto (
                    id,
                    curso_edicion_id,
                    solicitante_correo,
                    destinatario_correo,
                    mensaje,
                    estado,
                    fecha_solicitud,
                    fecha_respuesta
                ) VALUES (?, ?, ?, ?, ?, 'PENDIENTE', NOW(), NULL)
            ", [
                $id,
                (string) $data['curso_edicion_id'],
                strtolower(trim($data['solicitante_correo'])),
                strtolower(trim($data['destinatario_correo'])),
                $data['mensaje'] ?? null,
            ]);

            return $this->obtenerSolicitudPorId($id);
        });
    }

    public function obtenerSolicitudPendiente(
        string $cursoEdicionId,
        string $solicitanteCorreo,
        string $destinatarioCorreo
    ) {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                id,
                curso_edicion_id,
                solicitante_correo,
                destinatario_correo,
                mensaje,
                estado,
                fecha_solicitud,
                fecha_respuesta
            FROM solicitud_contacto
            WHERE curso_edicion_id = ?
              AND LOWER(TRIM(solicitante_correo)) = LOWER(TRIM(?))
              AND LOWER(TRIM(destinatario_correo)) = LOWER(TRIM(?))
              AND estado = 'PENDIENTE'
            LIMIT 1
        ", [
            $cursoEdicionId,
            strtolower(trim($solicitanteCorreo)),
            strtolower(trim($destinatarioCorreo)),
        ]);

        return $rows[0] ?? null;
    }

    public function obtenerSolicitudPorId(string $id)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                id,
                curso_edicion_id,
                solicitante_correo,
                destinatario_correo,
                mensaje,
                estado,
                fecha_solicitud,
                fecha_respuesta
            FROM solicitud_contacto
            WHERE id = ?
            LIMIT 1
        ", [
            trim($id),
        ]);

        return $rows[0] ?? null;
    }

    public function responderSolicitudContacto(
        string $solicitudId,
        string $destinatarioCorreo,
        string $estado
    ): bool {
        return DbSafe::statement('mysql_cursos', "
            UPDATE solicitud_contacto
            SET
                estado = ?,
                fecha_respuesta = NOW()
            WHERE id = ?
              AND LOWER(TRIM(destinatario_correo)) = LOWER(TRIM(?))
              AND estado = 'PENDIENTE'
        ", [
            strtoupper(trim($estado)),
            trim($solicitudId),
            strtolower(trim($destinatarioCorreo)),
        ]);
    }

    public function tieneSolicitudAceptada(
        string $cursoEdicionId,
        string $solicitanteCorreo,
        string $destinatarioCorreo
    ): bool {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT COUNT(*) AS total
            FROM solicitud_contacto
            WHERE curso_edicion_id = ?
              AND LOWER(TRIM(solicitante_correo)) = LOWER(TRIM(?))
              AND LOWER(TRIM(destinatario_correo)) = LOWER(TRIM(?))
              AND estado = 'ACEPTADA'
        ", [
            trim($cursoEdicionId),
            strtolower(trim($solicitanteCorreo)),
            strtolower(trim($destinatarioCorreo)),
        ]);

        return (int) ($rows[0]->total ?? 0) > 0;
    }

    public function listarSolicitudesPorAlumno(string $correo, string $tipo = 'RECIBIDAS'): array
    {
        $tipo = strtoupper(trim($tipo));

        if ($tipo === 'ENVIADAS') {
            return $this->listarSolicitudesEnviadas($correo);
        }

        return $this->listarSolicitudesRecibidas($correo);
    }

    public function listarSolicitudesRecibidas(string $correo): array
    {
        return DbSafe::select('mysql_cursos', "
            SELECT
                sc.id,
                sc.curso_edicion_id,
                sc.solicitante_correo,
                sc.destinatario_correo,
                sc.mensaje,
                sc.estado,
                sc.fecha_solicitud,
                sc.fecha_respuesta,

                a.nombres AS solicitante_nombres,
                a.apellidos AS solicitante_apellidos,
                CONCAT(a.nombres, ' ', a.apellidos) AS solicitante_nombre_completo,
                a.foto_url AS solicitante_foto_url,
                a.presentacion_profesional AS solicitante_presentacion_profesional
            FROM solicitud_contacto sc
            INNER JOIN alumno a
                ON LOWER(TRIM(a.correo)) = LOWER(TRIM(sc.solicitante_correo))
            WHERE LOWER(TRIM(sc.destinatario_correo)) = LOWER(TRIM(?))
            ORDER BY sc.fecha_solicitud DESC
        ", [
            strtolower(trim($correo)),
        ]);
    }

    public function listarSolicitudesEnviadas(string $correo): array
    {
        return DbSafe::select('mysql_cursos', "
            SELECT
                sc.id,
                sc.curso_edicion_id,
                sc.solicitante_correo,
                sc.destinatario_correo,
                sc.mensaje,
                sc.estado,
                sc.fecha_solicitud,
                sc.fecha_respuesta,

                a.nombres AS destinatario_nombres,
                a.apellidos AS destinatario_apellidos,
                CONCAT(a.nombres, ' ', a.apellidos) AS destinatario_nombre_completo,
                a.foto_url AS destinatario_foto_url,
                a.presentacion_profesional AS destinatario_presentacion_profesional
            FROM solicitud_contacto sc
            INNER JOIN alumno a
                ON LOWER(TRIM(a.correo)) = LOWER(TRIM(sc.destinatario_correo))
            WHERE LOWER(TRIM(sc.solicitante_correo)) = LOWER(TRIM(?))
            ORDER BY sc.fecha_solicitud DESC
        ", [
            strtolower(trim($correo)),
        ]);
    }

        private function generarTokenCertificado(): string
    {
        return Str::uuid()->toString();
    }

    private function construirLinkPublicoCertificado(string $token): string
    {
        $baseUrl = rtrim((string) env('CERTIFICADO_PUBLIC_BASE_URL', ''), '/');

        if ($baseUrl === '') {
            $baseUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/Certificados';
        }

        return $baseUrl . '/' . $token;
    }

    public function crearCertificado(array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($data) {
            $conn = DB::connection('mysql_cursos');

            $correo = strtolower(trim($data['alumno_correo']));
            $token = $data['token'] ?? $this->generarTokenCertificado();
            $linkPublico = $data['link_publico'] ?? $this->construirLinkPublicoCertificado($token);

            $conn->insert("
                INSERT INTO alumno_certificado (
                    alumno_correo,
                    curso_edicion_id,
                    archivo_nombre,
                    archivo_ruta,
                    archivo_mime,
                    archivo_peso,
                    token,
                    link_publico,
                    estado,
                    usuario_adjunta,
                    fecha_adjunta,
                    usuario_envia,
                    fecha_envia,
                    fecha_creacion,
                    fecha_actualizacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $correo,
                (int) $data['curso_edicion_id'],

                $data['archivo_nombre'] ?? null,
                $data['archivo_ruta'] ?? null,
                $data['archivo_mime'] ?? null,
                $data['archivo_peso'] ?? null,

                $token,
                $linkPublico,

                $data['estado'] ?? 'pendiente',

                $data['usuario_adjunta'] ?? null,
                $data['fecha_adjunta'] ?? null,

                $data['usuario_envia'] ?? null,
                $data['fecha_envia'] ?? null,
            ]);

            $id = (int) $conn->getPdo()->lastInsertId();

            return $this->obtenerCertificadoPorId($id);
        });
    }

    public function obtenerCertificadoPorId(int $id)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                id,
                alumno_correo,
                curso_edicion_id,
                archivo_nombre,
                archivo_ruta,
                archivo_mime,
                archivo_peso,
                token,
                link_publico,
                estado,
                usuario_adjunta,
                fecha_adjunta,
                usuario_envia,
                fecha_envia,
                fecha_creacion,
                fecha_actualizacion
            FROM alumno_certificado
            WHERE id = ?
            LIMIT 1
        ", [
            $id,
        ]);

        return $rows[0] ?? null;
    }

    public function obtenerCertificadoPorToken(string $token)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                id,
                alumno_correo,
                curso_edicion_id,
                archivo_nombre,
                archivo_ruta,
                archivo_mime,
                archivo_peso,
                token,
                link_publico,
                estado,
                usuario_adjunta,
                fecha_adjunta,
                usuario_envia,
                fecha_envia,
                fecha_creacion,
                fecha_actualizacion
            FROM alumno_certificado
            WHERE token = ?
            LIMIT 1
        ", [
            trim($token),
        ]);

        return $rows[0] ?? null;
    }

    public function obtenerCertificadoPorAlumnoCurso(string $correo, int $cursoEdicionId)
    {
        $rows = DbSafe::select('mysql_cursos', "
            SELECT
                id,
                alumno_correo,
                curso_edicion_id,
                archivo_nombre,
                archivo_ruta,
                archivo_mime,
                archivo_peso,
                token,
                link_publico,
                estado,
                usuario_adjunta,
                fecha_adjunta,
                usuario_envia,
                fecha_envia,
                fecha_creacion,
                fecha_actualizacion
            FROM alumno_certificado
            WHERE LOWER(TRIM(alumno_correo)) = LOWER(TRIM(?))
              AND curso_edicion_id = ?
            LIMIT 1
        ", [
            strtolower(trim($correo)),
            $cursoEdicionId,
        ]);

        return $rows[0] ?? null;
    }

    public function listarCertificadosPorCursoEdicion(int $cursoEdicionId): array
    {
        return DbSafe::select('mysql_cursos', "
            SELECT
                id,
                alumno_correo,
                curso_edicion_id,
                archivo_nombre,
                archivo_ruta,
                archivo_mime,
                archivo_peso,
                token,
                link_publico,
                estado,
                usuario_adjunta,
                fecha_adjunta,
                usuario_envia,
                fecha_envia,
                fecha_creacion,
                fecha_actualizacion
            FROM alumno_certificado
            WHERE curso_edicion_id = ?
            ORDER BY fecha_creacion DESC
        ", [
            $cursoEdicionId,
        ]);
    }

    public function actualizarCertificado(int $id, array $data)
    {
        $fields = [];
        $params = [];

        $map = [
            'alumno_correo',
            'curso_edicion_id',
            'archivo_nombre',
            'archivo_ruta',
            'archivo_mime',
            'archivo_peso',
            'token',
            'link_publico',
            'estado',
            'usuario_adjunta',
            'fecha_adjunta',
            'usuario_envia',
            'fecha_envia',
        ];

        foreach ($map as $campo) {
            if (array_key_exists($campo, $data)) {
                if ($campo === 'alumno_correo' && $data[$campo] !== null) {
                    $data[$campo] = strtolower(trim($data[$campo]));
                }

                $fields[] = "$campo = ?";
                $params[] = $data[$campo];
            }
        }

        if (empty($fields)) {
            return $this->obtenerCertificadoPorId($id);
        }

        $fields[] = "fecha_actualizacion = NOW()";

        $sql = "
            UPDATE alumno_certificado
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ";

        $params[] = $id;

        DbSafe::statement('mysql_cursos', $sql, $params);

        return $this->obtenerCertificadoPorId($id);
    }

    public function adjuntarCertificado(int $id, array $data)
    {
        return DbSafe::execute('mysql_cursos', function () use ($id, $data) {
            $conn = DB::connection('mysql_cursos');

            $conn->update("
                UPDATE alumno_certificado
                SET
                    archivo_nombre = ?,
                    archivo_ruta = ?,
                    archivo_mime = ?,
                    archivo_peso = ?,
                    estado = 'adjuntado',
                    usuario_adjunta = ?,
                    fecha_adjunta = NOW(),
                    fecha_actualizacion = NOW()
                WHERE id = ?
            ", [
                $data['archivo_nombre'],
                $data['archivo_ruta'],
                $data['archivo_mime'] ?? null,
                $data['archivo_peso'] ?? null,
                $data['usuario_adjunta'] ?? null,
                $id,
            ]);

            return $this->obtenerCertificadoPorId($id);
        });
    }

    public function enviarCertificado(int $id, string $usuarioEnvia)
    {
        return DbSafe::execute('mysql_cursos', function () use ($id, $usuarioEnvia) {
            $conn = DB::connection('mysql_cursos');

            $conn->update("
                UPDATE alumno_certificado
                SET
                    estado = 'enviado',
                    usuario_envia = ?,
                    fecha_envia = NOW(),
                    fecha_actualizacion = NOW()
                WHERE id = ?
            ", [
                strtolower(trim($usuarioEnvia)),
                $id,
            ]);

            return $this->obtenerCertificadoPorId($id);
        });
    }

    public function eliminarCertificado(int $id): bool
    {
        return DbSafe::statement('mysql_cursos', "
            DELETE FROM alumno_certificado
            WHERE id = ?
        ", [
            $id,
        ]);
    }
}
