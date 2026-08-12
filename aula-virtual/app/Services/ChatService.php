<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;
use Carbon\Carbon;

class ChatService
{
    public function __construct(
        private readonly ApiServiciosClient $client
    ) {
    }

    public function obtenerConversacionCurso(int $courseId): ServiceResult
    {
        $salaResult = $this->client->obtenerSalaChatPorContexto('COURSE', $courseId);

        if (!$salaResult->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudo cargar la conversación del curso.',
                'stage' => 'sala',
                'api_error' => $salaResult->error(),
            ], $salaResult->status());
        }

        $sala = $this->extractSala($salaResult->data());
        $salaId = (string) ($sala['id'] ?? $sala['sala_id'] ?? $sala['chat_sala_id'] ?? '');

        if (trim($salaId) === '') {
            return ServiceResult::failure([
                'message' => 'No se pudo cargar la conversación del curso.',
                'stage' => 'sala',
            ]);
        }

        $mensajesResult = $this->client->listarMensajesChat($salaId, 20, 0);

        if (!$mensajesResult->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudieron cargar los mensajes. Intenta nuevamente.',
                'stage' => 'mensajes',
                'sala' => $sala,
                'api_error' => $mensajesResult->error(),
            ], $mensajesResult->status());
        }

        $payload = is_array($mensajesResult->data()) ? $mensajesResult->data() : [];
        $mensajes = $this->normalizeMessages($this->extractMessageItems($payload));
        $pagination = $this->extractPagination($payload, $mensajes->count(), 20, 0);

        return ServiceResult::success([
            'sala' => $sala,
            'sala_id' => $salaId,
            'total_mensajes' => $pagination['total'] ?? $mensajes->count(),
            'mensajes' => $mensajes,
            'pagination' => $pagination,
            'error' => null,
        ]);
    }

    public function publicarComentarioPrincipal(string $salaId, string $mensaje): ServiceResult
    {
        return $this->crearMensaje($salaId, null, $mensaje);
    }

    public function obtenerMensajesSala(string $salaId, int $limit = 20, int $offset = 0): ServiceResult
    {
        $salaId = trim($salaId);

        if ($salaId === '') {
            return ServiceResult::failure([
                'message' => 'No se pudo identificar la conversación del curso.',
            ], 422);
        }

        $result = $this->client->listarMensajesChat($salaId, $limit, $offset);

        if (!$result->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudo actualizar la conversación.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        $payload = is_array($result->data()) ? $result->data() : [];
        $mensajes = $this->normalizeMessages($this->extractMessageItems($payload));
        $pagination = $this->extractPagination($payload, $mensajes->count(), $limit, $offset);

        return ServiceResult::success([
            'total_mensajes' => $pagination['total'] ?? $mensajes->count(),
            'mensajes' => $mensajes,
            'pagination' => $pagination,
        ], $result->status());
    }

    public function responderComentario(string $salaId, string $mensajePadreId, string $mensaje): ServiceResult
    {
        return $this->crearMensaje($salaId, $mensajePadreId, $mensaje);
    }

    public function eliminarMensajePropio(string $mensajeId): ServiceResult
    {
        $mensajeId = trim($mensajeId);

        if ($mensajeId === '') {
            return ServiceResult::failure([
                'message' => 'No se pudo identificar el mensaje a eliminar.',
            ], 422);
        }

        $result = $this->client->eliminarMensajeChat($mensajeId);

        if (!$result->ok()) {
            return ServiceResult::failure([
                'message' => 'No se pudo eliminar el mensaje. Intenta nuevamente.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        return ServiceResult::success($result->data(), $result->status());
    }

    private function crearMensaje(string $salaId, ?string $mensajePadreId, string $mensaje): ServiceResult
    {
        $salaId = trim($salaId);
        $mensajePadreId = $mensajePadreId !== null ? trim($mensajePadreId) : null;
        $mensaje = trim($mensaje);
        $isReply = $mensajePadreId !== null && $mensajePadreId !== '';

        if ($salaId === '') {
            return ServiceResult::failure([
                'message' => 'No se pudo identificar la conversación del curso.',
            ], 422);
        }

        if ($isReply && $mensajePadreId === '') {
            return ServiceResult::failure([
                'message' => 'No se pudo identificar el mensaje que estás respondiendo.',
            ], 422);
        }

        if ($mensaje === '') {
            return ServiceResult::failure([
                'message' => $isReply
                    ? 'Escribe una respuesta antes de enviar.'
                    : 'Escribe un comentario antes de enviar.',
            ], 422);
        }

        $result = $this->client->crearMensajeChat($salaId, [
            'mensaje' => $mensaje,
            'mensaje_padre_id' => $isReply ? $mensajePadreId : null,
        ]);

        if (!$result->ok()) {
            return ServiceResult::failure([
                'message' => $isReply
                    ? 'No se pudo publicar la respuesta. Intenta nuevamente.'
                    : 'No se pudo publicar el comentario. Intenta nuevamente.',
                'api_error' => $result->error(),
            ], $result->status());
        }

        $payload = is_array($result->data()) ? $result->data() : [];
        $message = $this->extractCreatedMessage($payload);
        $message = $this->applyAuthenticatedUserFallback(
            $this->normalizeMessage($message, (string) session(AuthSessionKeys::USER_EMAIL, ''))
        );

        if ($isReply && empty($message['parent_id'])) {
            $message['parent_id'] = $mensajePadreId;
        }

        return ServiceResult::success([
            'message' => (object) $message,
        ], $result->status());
    }

    private function extractSala(mixed $payload): array
    {
        $data = is_array($payload) ? $payload : (array) $payload;

        if (isset($data['sala']) && is_array($data['sala'])) {
            return $data['sala'];
        }

        if (isset($data['data']) && is_array($data['data'])) {
            return $this->extractSala($data['data']);
        }

        return $data;
    }

    private function extractMessageItems(array $payload): array
    {
        foreach (['mensajes', 'messages', 'data', 'items'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return array_is_list($payload) ? $payload : [];
    }

    private function extractPagination(array $payload, int $messageCount, int $limit, int $offset): array
    {
        $pagination = isset($payload['pagination']) && is_array($payload['pagination'])
            ? $payload['pagination']
            : [];
        $total = (int) (
            $pagination['total']
            ?? $payload['total']
            ?? $payload['total_mensajes']
            ?? ($offset + $messageCount)
        );
        $hasMore = array_key_exists('has_more', $pagination)
            ? (bool) $pagination['has_more']
            : $messageCount >= $limit;

        return [
            'limit' => (int) ($pagination['limit'] ?? $limit),
            'offset' => (int) ($pagination['offset'] ?? $offset),
            'total' => $total,
            'has_more' => $hasMore,
            'next_offset' => $hasMore
                ? (int) ($pagination['next_offset'] ?? ($offset + $limit))
                : null,
        ];
    }

    private function extractCreatedMessage(array $payload): array
    {
        foreach (['mensaje', 'message', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }

    private function normalizeMessages(array $items)
    {
        $correo = (string) session(AuthSessionKeys::USER_EMAIL, '');
        $normalized = collect($items)
            ->map(fn ($item) => $this->normalizeMessage($item, $correo))
            ->sortBy(fn ($message) => $this->timestampValue($message['fecha'] ?? null))
            ->values();

        $byId = $normalized
            ->filter(fn ($message) => !empty($message['id']))
            ->keyBy(fn ($message) => (string) $message['id']);

        return $normalized
            ->map(function ($message) use ($byId) {
                if (!empty($message['parent_id'])) {
                    $parent = $byId->get((string) $message['parent_id']);
                    $message['referencia'] = $parent
                        ? [
                            'id' => $parent['id'] ?? null,
                            'nombre_usuario' => $parent['nombre_usuario'] ?? 'Usuario',
                            'rol_usuario' => $parent['rol_usuario'] ?? '',
                            'mensaje' => $parent['mensaje'] ?? '',
                        ]
                        : null;
                }

                $message['respuestas'] = collect();

                return (object) $message;
            })
            ->values();
    }

    private function normalizeMessage(mixed $item, string $correo): array
    {
        $data = is_array($item) ? $item : (array) $item;
        $user = $this->arrayValue($data, ['usuario', 'user', 'author'], []);
        $user = is_array($user) ? $user : (array) $user;
        $createdAt = $this->arrayValue($data, [
            'fecha',
            'fecha_creacion',
            'created_at',
            'creado_en',
            'createdAt',
        ]);
        $email = (string) $this->arrayValue($data, [
            'correo_usuario',
            'user_email',
            'email',
            'usuario_email',
            'usuario_id',
        ], $user['email'] ?? '');

        $replies = collect($this->arrayValue($data, ['respuestas', 'replies', 'children'], []))
            ->map(fn ($reply) => (object) $this->normalizeMessage($reply, $correo))
            ->values();

        return [
            'id' => $this->arrayValue($data, ['id', 'mensaje_id']),
            'parent_id' => $this->arrayValue($data, [
                'mensaje_padre_id',
                'parent_id',
                'reply_to_id',
                'respuesta_a_id',
                'id_mensaje_padre',
                'mensaje_id_padre',
            ]),
            'nombre_usuario' => $this->arrayValue($data, [
                'nombre_usuario',
                'usuario_nombre',
                'author_name',
                'nombre',
            ], $user['name'] ?? $user['nombre'] ?? 'Usuario'),
            'correo_usuario' => strtolower(trim($email)),
            'foto_url' => $this->arrayValue($data, [
                'foto_url',
                'usuario_foto_url',
                'avatar_url',
                'photo_url',
            ], $user['foto_url'] ?? $user['avatar_url'] ?? null),
            'rol_usuario' => $this->arrayValue($data, [
                'rol_usuario',
                'rol',
                'role',
                'usuario_rol',
            ], $user['rol'] ?? ''),
            'mensaje' => $this->arrayValue($data, ['mensaje', 'contenido', 'message', 'body'], ''),
            'fecha' => $createdAt,
            'tiempo_relativo' => $this->arrayValue($data, ['tiempo_relativo', 'relative_time'], $this->relativeTime($createdAt)),
            'es_propio' => $correo !== '' && $email !== '' && strcasecmp($correo, $email) === 0,
            'eliminado' => (bool) $this->arrayValue($data, ['eliminado', 'deleted', 'is_deleted'], false),
            'respuestas' => $replies,
        ];
    }

    private function applyAuthenticatedUserFallback(array $message): array
    {
        $userName = (string) session(AuthSessionKeys::USER_NAME, '');
        $userRole = strtoupper((string) session(AuthSessionKeys::USER_ROLE, ''));
        $userEmail = (string) session(AuthSessionKeys::USER_EMAIL, '');

        if (($message['nombre_usuario'] ?? 'Usuario') === 'Usuario' && $userName !== '') {
            $message['nombre_usuario'] = $userName;
        }

        if (empty($message['rol_usuario']) && $userRole !== '') {
            $message['rol_usuario'] = $userRole;
        }

        if (empty($message['fecha'])) {
            $message['fecha'] = now()->toDateTimeString();
            $message['tiempo_relativo'] = 'ahora';
        }

        if ($userEmail !== '') {
            $message['es_propio'] = true;
        }

        return $message;
    }

    private function arrayValue(array $data, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return $default;
    }

    private function relativeTime(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            $date = Carbon::parse($value);
            $now = now();
            $diffSeconds = max(0, $date->diffInSeconds($now));

            if ($diffSeconds < 60) {
                return 'ahora';
            }

            $diffMinutes = intdiv($diffSeconds, 60);

            if ($diffMinutes < 60) {
                return "hace {$diffMinutes} min";
            }

            $diffHours = intdiv($diffMinutes, 60);

            if ($diffHours < 24) {
                return "hace {$diffHours} h";
            }

            $diffDays = intdiv($diffHours, 24);

            if ($diffDays < 2) {
                return 'ayer';
            }

            if ($diffDays < 7) {
                return "hace {$diffDays} días";
            }

            return $date->format('d/m/Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function timestampValue(mixed $value): int
    {
        if (empty($value)) {
            return PHP_INT_MAX;
        }

        try {
            return Carbon::parse($value)->timestamp;
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }
}
