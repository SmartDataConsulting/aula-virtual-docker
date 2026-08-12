<?php

namespace App\Services;

use App\Helpers\HtmlSanitizer;
use App\Repositories\ChatRepository;

class ChatService
{
    protected ChatRepository $repo;

    public function __construct(ChatRepository $repo)
    {
        $this->repo = $repo;
    }

    public function obtenerOCrearSala(string $tipoContexto, string $idContexto, ?string $titulo = null)
    {
        $tipoContexto = strtoupper(trim($tipoContexto));
        $idContexto = trim($idContexto);

        if ($tipoContexto === '') {
            throw new \InvalidArgumentException('El tipo de contexto es obligatorio');
        }

        if ($idContexto === '') {
            throw new \InvalidArgumentException('El identificador del contexto es obligatorio');
        }

        return $this->repo->obtenerOCrearSala($tipoContexto, $idContexto, $titulo);
    }

    public function listarMensajes(string $salaId, int $limit = 20, int $offset = 0)
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        return $this->repo->listarMensajes($salaId, $limit, $offset);
    }

    public function listarMensajesPaginados(string $salaId, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $total = $this->repo->contarMensajes($salaId);
        $mensajes = $this->repo->listarMensajes($salaId, $limit, $offset);

        return [
            'data' => $mensajes,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total,
                'next_offset' => ($offset + $limit) < $total ? $offset + $limit : null,
            ],
        ];
    }

    public function obtenerMensaje(string $mensajeId)
    {
        return $this->repo->obtenerMensaje($mensajeId);
    }

    public function crearMensaje(array $data)
    {
        if (empty($data['sala_id'])) {
            throw new \InvalidArgumentException('La sala es obligatoria');
        }

        if (empty($data['usuario_id'])) {
            throw new \InvalidArgumentException('El usuario es obligatorio');
        }

        if (empty($data['nombre_usuario'])) {
            throw new \InvalidArgumentException('El nombre del usuario es obligatorio');
        }

        if (empty($data['mensaje']) || trim($data['mensaje']) === '') {
            throw new \InvalidArgumentException('El mensaje no puede estar vacío');
        }

        $mensaje = trim($data['mensaje']);

        if (class_exists(HtmlSanitizer::class)) {
            $mensaje = HtmlSanitizer::sanitizeQuillHtml($mensaje);
        }

        return $this->repo->insertarMensaje([
            'sala_id' => $data['sala_id'],
            'mensaje_padre_id' => $data['mensaje_padre_id'] ?? null,
            'usuario_id' => $data['usuario_id'],
            'nombre_usuario' => $data['nombre_usuario'],
            'rol_usuario' => $data['rol_usuario'] ?? 'ALUMNO',
            'mensaje' => $mensaje,
        ]);
    }

    public function eliminarMensaje(string $mensajeId, string $usuarioId, ?string $rolUsuario = null)
    {
        $mensaje = $this->repo->obtenerMensaje($mensajeId);

        if (!$mensaje) {
            throw new \RuntimeException('Mensaje no encontrado');
        }

        $esAutor = $mensaje->usuario_id === $usuarioId;
        $esAdmin = in_array(strtoupper((string) $rolUsuario), ['ADMIN', 'DOCENTE'], true);

        if (!$esAutor && !$esAdmin) {
            throw new \DomainException('No tiene permisos para eliminar este mensaje');
        }

        return $this->repo->eliminarMensaje($mensajeId);
    }

    public function fijarMensaje(string $mensajeId, ?string $rolUsuario = null)
    {
        $this->validarRolModerador($rolUsuario);

        return $this->repo->fijarMensaje($mensajeId);
    }

    public function desfijarMensaje(string $mensajeId, ?string $rolUsuario = null)
    {
        $this->validarRolModerador($rolUsuario);

        return $this->repo->desfijarMensaje($mensajeId);
    }

    public function listarMensajesFijados(string $salaId)
    {
        return $this->repo->listarMensajesFijados($salaId);
    }

    public function listarParticipantes(string $salaId)
    {
        return $this->repo->listarParticipantes($salaId);
    }

    public function buscarMensajes(string $salaId, string $texto)
    {
        $texto = trim($texto);

        if ($texto === '') {
            return [];
        }

        return $this->repo->buscarMensajes($salaId, $texto);
    }

    public function contarMensajes(string $salaId): int
    {
        return $this->repo->contarMensajes($salaId);
    }

    public function obtenerResumenSala(string $salaId): array
    {
        return [
            'total_mensajes' => $this->repo->contarMensajes($salaId),
            'mensajes_fijados' => $this->repo->listarMensajesFijados($salaId),
            'participantes' => $this->repo->listarParticipantes($salaId),
        ];
    }

    private function validarRolModerador(?string $rolUsuario): void
    {
        $rol = strtoupper((string) $rolUsuario);

        if (!in_array($rol, ['ADMIN', 'DOCENTE'], true)) {
            throw new \DomainException('No tiene permisos para realizar esta acción');
        }
    }
}
