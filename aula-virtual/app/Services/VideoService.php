<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class VideoService
{
    private const CACHE_TTL_SECONDS = 43200;
    private const CACHE_KEY_PREFIX = 'video_upload_state:session:';
    private const RESUMABLE_UPLOAD_STATUSES = ['created', 'uploading'];
    private const CHAT_MAX_BYTES = 5242880;

    public function __construct(
        private ApiServiciosClient $api,
        private GoogleDriveService $drive
    ) {
    }

    public function startUpload(int $sessionId, array $metadata): ServiceResult
    {
        $filename = trim((string) ($metadata['filename'] ?? ''));
        $mimeType = trim((string) ($metadata['mime_type'] ?? ''));
        $filesize = (int) ($metadata['filesize'] ?? 0);

        if ($filename === '' || $mimeType === '' || $filesize <= 0) {
            return ServiceResult::failure([
                'error' => 'Metadata incompleta para iniciar subida',
            ], 422);
        }

        $existing = $this->getUploadProgress($sessionId);
        if ($existing->ok()) {
            $data = is_array($existing->data()) ? $existing->data() : [];
            $status = (string) ($data['status'] ?? 'none');

            if (!empty($data['upload_id'])
                && ($data['filename'] ?? null) === $filename
                && (int) ($data['filesize'] ?? 0) === $filesize
                && in_array($status, self::RESUMABLE_UPLOAD_STATUSES, true)) {
                return ServiceResult::success($data, 200);
            }
        }

        $uploadUrl = $this->drive->createResumableUpload([
            'filename' => $filename,
            'mime_type' => $mimeType,
            'filesize' => $filesize,
        ]);

        $started = $this->api->registerVideoUploadStarted($sessionId, [
            'upload_url' => $uploadUrl,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'filesize' => $filesize,
        ]);

        if (!$started->ok()) {
            return $started;
        }

        $payload = is_array($started->data()) ? $started->data() : [];
        $state = [
            'session_id' => $sessionId,
            'upload_id' => (int) ($payload['upload_id'] ?? 0),
            'upload_url' => $payload['upload_url'] ?? $uploadUrl,
            'filename' => $payload['filename'] ?? $filename,
            'mime_type' => $payload['mime_type'] ?? $mimeType,
            'filesize' => (int) ($payload['filesize'] ?? $filesize),
            'bytes_uploaded' => (int) ($payload['bytes_uploaded'] ?? 0),
            'file_id' => $payload['file_id'] ?? null,
            'status' => $payload['status'] ?? 'created',
            'created_at' => $payload['created_at'] ?? now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        if ($state['upload_id'] <= 0) {
            throw new RuntimeException('API externa no devolvio un upload_id valido');
        }

        $this->putCachedUploadState($sessionId, $state);

        return ServiceResult::success($this->buildUploadProgressPayload($state), 200);
    }

    public function uploadChunk(
    int $sessionId,
    UploadedFile $file,
    int $uploadId,
    int $chunkIndex,
    int $totalChunks,
    array $metadata = []
    ): ServiceResult {
        $state = $this->requireUploadState($sessionId, $uploadId);

        $filename = trim((string) ($metadata['filename'] ?? $file->getClientOriginalName()));
        $mimeType = trim((string) ($metadata['mime_type'] ?? $file->getClientMimeType()));
        $filesize = (int) ($metadata['filesize'] ?? 0);
        $startByte = array_key_exists('start_byte', $metadata) ? (int) $metadata['start_byte'] : null;
        $endByte = array_key_exists('end_byte', $metadata) ? (int) $metadata['end_byte'] : null;

        if ($filename === '' || $filesize <= 0) {
            return ServiceResult::failure([
                'error' => 'Metadata incompleta para validar la subida',
            ], 422);
        }

        if ($startByte === null || $endByte === null || $startByte < 0 || $endByte < $startByte || $endByte >= $filesize) {
            return ServiceResult::failure([
                'error' => 'Rango de chunk invalido',
            ], 422);
        }

        $chunkSize = (int) $file->getSize();
        if ($chunkSize <= 0 || ($endByte - $startByte + 1) !== $chunkSize) {
            return ServiceResult::failure([
                'error' => 'El rango del chunk no coincide con el archivo recibido',
            ], 422);
        }

        if (
            isset($state['filename'], $state['filesize']) &&
            (
                (string) $state['filename'] !== $filename ||
                (int) $state['filesize'] !== $filesize
            )
        ) {
            return ServiceResult::failure([
                'error' => 'El archivo seleccionado no corresponde a la subida activa.',
            ], 409);
        }

        $expectedStartByte = (int) ($state['bytes_uploaded'] ?? 0);
        if ($startByte !== $expectedStartByte) {
            return ServiceResult::failure([
                'error' => 'La subida esta desincronizada. Vuelve a seleccionar el video para iniciar desde cero.',
                'code' => 'upload_offset_mismatch',
                'expected_start_byte' => $expectedStartByte,
                'received_start_byte' => $startByte,
            ], 409);
        }

        try {
            $result = $this->drive->uploadChunk(
                (string) $state['upload_url'],
                $file,
                $chunkIndex,
                $totalChunks,
                (int) $state['filesize'],
                $startByte,
                $endByte
            );
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['updated_at'] = now()->toIso8601String();
            $state['error_message'] = $e->getMessage();

            $this->putCachedUploadState($sessionId, $state);

            $this->api->registerVideoUploadError($sessionId, [
                'upload_id' => $uploadId,
                'error_message' => $e->getMessage(),
            ]);

            Cache::forget($this->cacheKey($sessionId));

            throw $e;
        }

        $bytesUploaded = $result['bytes_uploaded'] ?? null;

        $state['bytes_uploaded'] = $bytesUploaded !== null
            ? (int) $bytesUploaded
            : ((int) ($state['bytes_uploaded'] ?? 0) + (int) $file->getSize());

        $state['file_id'] = $result['file_id'] ?? ($state['file_id'] ?? null);
        $state['status'] = !empty($state['file_id']) ? 'uploaded' : 'uploading';
        $state['updated_at'] = now()->toIso8601String();
        $state['last_chunk_index'] = $chunkIndex;
        $state['total_chunks'] = $totalChunks;

        $this->putCachedUploadState($sessionId, $state);

        $progressUpdate = $this->api->updateVideoUploadProgress($sessionId, [
            'upload_id' => $uploadId,
            'bytes_uploaded' => $state['bytes_uploaded'],
            'status' => $state['status'],
        ]);

        if (!$progressUpdate->ok()) {
            return $progressUpdate;
        }

        return ServiceResult::success([
            'status' => $result['status'] ?? 'chunk_uploaded',
            'bytes_uploaded' => $state['bytes_uploaded'],
            'file_id' => $state['file_id'],
            'upload_id' => $state['upload_id'],
        ], 200);
    }

    public function finalizeUpload(
        int $sessionId,
        ?string $fileId = null,
        ?int $uploadId = null,
        ?int $filesize = null
    ): ServiceResult {
        $state = $uploadId !== null
            ? $this->requireUploadState($sessionId, $uploadId)
            : $this->resolveUploadState($sessionId);

        $resolvedFileId = trim((string) ($fileId ?: ($state['file_id'] ?? '')));
        if ($resolvedFileId === '') {
            return ServiceResult::failure([
                'error' => 'No se encontro file_id para finalizar la subida',
            ], 422);
        }

        try {
            $this->drive->setPublicPermission($resolvedFileId);
            $this->drive->applyViewerRestrictions($resolvedFileId);
            $videoStatus = $this->drive->getVideoStatus($resolvedFileId);
        } catch (\Throwable $e) {
            $this->api->registerVideoUploadError($sessionId, [
                'upload_id' => (int) ($state['upload_id'] ?? 0),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $state['file_id'] = $resolvedFileId;
        $state['filesize'] = $filesize ?: ($state['filesize'] ?? null);
        $state['bytes_uploaded'] = $filesize ?: ($state['bytes_uploaded'] ?? 0);
        $state['status'] = $videoStatus['status'] ?? 'uploaded';
        $state['updated_at'] = now()->toIso8601String();
        $state['finalized_at'] = now()->toIso8601String();

        $completed = $this->api->completeVideoUpload($sessionId, [
            'upload_id' => (int) ($state['upload_id'] ?? 0),
            'drive_file_id' => $resolvedFileId,
            'filesize' => (int) ($state['filesize'] ?? 0),
            'bytes_uploaded' => (int) ($state['bytes_uploaded'] ?? 0),
            'status' => $state['status'],
        ]);

        if (!$completed->ok()) {
            return $completed;
        }

        $statusUpdated = $this->api->updateVideoStatusRecord($sessionId, [
            'drive_file_id' => $resolvedFileId,
            'video_status' => $state['status'],
        ]);

        if (!$statusUpdated->ok()) {
            return $statusUpdated;
        }

        $this->putCachedUploadState($sessionId, $state);

        return ServiceResult::success([
            'status' => $state['status'],
            'file_id' => $resolvedFileId,
            'upload_id' => $state['upload_id'],
            'bytes_uploaded' => $state['bytes_uploaded'],
            'filesize' => $state['filesize'],
        ], 200);
    }

    public function cancelUpload(int $sessionId, $uploadId): ServiceResult
    {
        $state = $this->requireUploadState($sessionId, (int) $uploadId);

        if (!empty($state['file_id'])) {
            try {
                $this->drive->deleteFile((string) $state['file_id']);
            } catch (\Throwable) {
                // Si el archivo no existe o nunca termino de crearse, igual
                // debemos continuar con la cancelacion del registro en BD.
            }
        }

        $cancelled = $this->api->registerVideoUploadCancelled($sessionId, [
            'upload_id' => (int) $uploadId,
        ]);

        if (!$cancelled->ok()) {
            return $cancelled;
        }

        Cache::forget($this->cacheKey($sessionId));

        return ServiceResult::success([
            'status' => 'cancelled',
            'upload_id' => (int) $uploadId,
        ], 200);
    }

    public function deleteVideo(int $sessionId): ServiceResult
    {
        $state = $this->resolveUploadState($sessionId, false);
        $fileId = trim((string) ($state['file_id'] ?? ''));
        $chatFileId = '';

        $status = $this->api->getVideoStatus($sessionId);
        if ($status->ok() && is_array($status->data())) {
            if ($fileId === '') {
                $fileId = trim((string) ($status->data()['file_id'] ?? ''));
            }

            $chatFileId = trim((string) ($status->data()['chat']['file_id'] ?? ''));
        }

        if ($fileId !== '') {
            $this->drive->deleteFile($fileId);
        }

        if ($chatFileId !== '') {
            try {
                $this->drive->deleteFile($chatFileId);
            } catch (\Throwable) {
                // La metadata se limpiara igual en el API.
            }
        }

        $deleted = $this->api->deleteVideo($sessionId);
        if (!$deleted->ok()) {
            return $deleted;
        }

        Cache::forget($this->cacheKey($sessionId));

        return ServiceResult::success([
            'status' => 'deleted',
            'file_id' => $fileId !== '' ? $fileId : null,
        ], 200);
    }

    public function uploadChatTranscript(int $sessionId, UploadedFile $file): ServiceResult
    {
        $validation = $this->validateChatFile($file);
        if ($validation !== null) {
            return $validation;
        }

        $filename = $this->normalizeChatFilename($file->getClientOriginalName());

        $uploaded = $this->drive->uploadSmallFile($file, $filename, 'text/plain');

        $registered = $this->api->registerVideoChatUploaded($sessionId, [
            'drive_file_id' => $uploaded['file_id'],
            'titulo' => $filename,
            'filesize' => (int) ($uploaded['filesize'] ?? $file->getSize()),
            'uploaded_at' => now()->toIso8601String(),
        ]);

        if (!$registered->ok()) {
            try {
                $this->drive->deleteFile((string) $uploaded['file_id']);
            } catch (\Throwable) {
                // Si Drive no permite borrar, el archivo queda huerfano pero no visible en Aula.
            }

            return $registered;
        }

        Cache::forget($this->cacheKey($sessionId));

        return ServiceResult::success([
            'status' => 'chat_uploaded',
            'chat' => [
                'title' => $filename,
                'filesize' => (int) ($uploaded['filesize'] ?? $file->getSize()),
            ],
        ], 200);
    }

    public function deleteChatTranscript(int $sessionId): ServiceResult
    {
        $status = $this->api->getVideoStatus($sessionId);
        $chatFileId = '';

        if ($status->ok() && is_array($status->data())) {
            $chatFileId = trim((string) ($status->data()['chat']['file_id'] ?? ''));
        }

        if ($chatFileId !== '') {
            try {
                $this->drive->deleteFile($chatFileId);
            } catch (\Throwable) {
                // Aunque falle Drive, se limpia la referencia visible.
            }
        }

        $deleted = $this->api->deleteVideoChat($sessionId);
        if (!$deleted->ok()) {
            return $deleted;
        }

        Cache::forget($this->cacheKey($sessionId));

        return ServiceResult::success([
            'status' => 'chat_deleted',
        ], 200);
    }

    public function getChatTranscript(int $sessionId): ServiceResult
    {
        $status = $this->api->getVideoStatus($sessionId);
        if (!$status->ok()) {
            return $status;
        }

        $data = is_array($status->data()) ? $status->data() : [];
        $chat = is_array($data['chat'] ?? null) ? $data['chat'] : [];
        $fileId = trim((string) ($chat['file_id'] ?? ''));

        if ($fileId === '') {
            return ServiceResult::failure([
                'error' => 'No se adjunto chat de Zoom para esta grabacion.',
            ], 404);
        }

        $download = $this->drive->downloadTextFile($fileId);
        $content = $this->normalizeTextContent((string) ($download['content'] ?? ''));

        return ServiceResult::success([
            'filename' => $chat['title'] ?? $download['filename'] ?? 'chat-de-zoom.txt',
            'mime_type' => 'text/plain; charset=UTF-8',
            'filesize' => $chat['filesize'] ?? $download['filesize'] ?? null,
            'content' => $content,
            'messages' => $this->parseZoomChat($content),
        ], 200);
    }

    public function getVideoStatus(int $sessionId): ServiceResult
    {
        $apiStatus = $this->api->getVideoStatus($sessionId);
        if (!$apiStatus->ok()) {
            return $apiStatus;
        }

        $apiData = is_array($apiStatus->data()) ? $apiStatus->data() : [];
        $fileId = trim((string) ($apiData['file_id'] ?? ''));

        if ($fileId === '') {
            return ServiceResult::success([
                'status' => $apiData['status'] ?? 'none',
                'file_id' => null,
                'chat' => $this->normalizeVideoChatStatus($apiData['chat'] ?? null),
            ], 200);
        }

        $driveStatus = $this->drive->getVideoStatus($fileId);
        $resolvedStatus = $driveStatus['status'] ?? ($apiData['status'] ?? 'unknown');

        if (($apiData['status'] ?? null) !== $resolvedStatus) {
            $this->api->updateVideoStatusRecord($sessionId, [
                'drive_file_id' => $fileId,
                'video_status' => $resolvedStatus,
            ]);
        }

        $state = $this->getCachedUploadState($sessionId) ?? [];
        $state['session_id'] = $sessionId;
        $state['upload_id'] = (int) ($apiData['upload_id'] ?? ($state['upload_id'] ?? 0));
        $state['upload_url'] = $apiData['upload_url'] ?? ($state['upload_url'] ?? null);
        $state['filename'] = $apiData['filename'] ?? ($state['filename'] ?? null);
        $state['mime_type'] = $apiData['mime_type'] ?? ($state['mime_type'] ?? null);
        $state['filesize'] = (int) ($apiData['filesize'] ?? ($state['filesize'] ?? 0));
        $state['bytes_uploaded'] = (int) ($apiData['bytes_uploaded'] ?? ($state['bytes_uploaded'] ?? 0));
        $state['file_id'] = $fileId;
        $state['status'] = $resolvedStatus;
        $state['updated_at'] = now()->toIso8601String();
        $this->putCachedUploadState($sessionId, $state);

        return ServiceResult::success([
            'status' => $resolvedStatus,
            'file_id' => $fileId,
            'chat' => $this->normalizeVideoChatStatus($apiData['chat'] ?? null),
        ], 200);
    }

    private function normalizeVideoChatStatus($chat): ?array
    {
        if (!is_array($chat)) {
            return null;
        }

        $fileId = trim((string) ($chat['file_id'] ?? ''));
        if ($fileId === '') {
            return null;
        }

        return [
            'file_id' => $fileId,
            'title' => $chat['title'] ?? 'chat-de-zoom.txt',
            'filesize' => $chat['filesize'] ?? null,
            'uploaded_at' => $chat['uploaded_at'] ?? null,
        ];
    }

    public function getUploadProgress(int $sessionId): ServiceResult
    {
        $apiProgress = $this->api->getUploadProgress($sessionId);

        if ($apiProgress->ok()) {
            $data = is_array($apiProgress->data()) ? $apiProgress->data() : [];

            $status = (string) ($data['status'] ?? 'none');

            if (in_array($status, ['deleted', 'cancelled', 'failed', 'error', 'none'], true)) {
                Cache::forget($this->cacheKey($sessionId));

                return ServiceResult::success([
                    'status' => 'none',
                ], 200);
            }

            if (!empty($data['upload_id'])) {
                $cached = $this->getCachedUploadState($sessionId) ?? [];
                $state = array_merge($cached, [
                    'session_id' => $sessionId,
                    'upload_id' => (int) ($data['upload_id'] ?? 0),
                    'upload_url' => $data['upload_url'] ?? ($cached['upload_url'] ?? null),
                    'filename' => $data['filename'] ?? ($cached['filename'] ?? null),
                    'mime_type' => $data['mime_type'] ?? ($cached['mime_type'] ?? null),
                    'filesize' => (int) ($data['filesize'] ?? ($cached['filesize'] ?? 0)),
                    'bytes_uploaded' => (int) ($data['bytes_uploaded'] ?? ($cached['bytes_uploaded'] ?? 0)),
                    'file_id' => $data['file_id'] ?? ($cached['file_id'] ?? null),
                    'status' => $status ?: ($cached['status'] ?? 'none'),
                    'updated_at' => now()->toIso8601String(),
                ]);

                $this->putCachedUploadState($sessionId, $state);

                return ServiceResult::success($this->buildUploadProgressPayload($state), 200);
            }
        }

        $state = $this->getCachedUploadState($sessionId);
        if ($state === null) {
            return ServiceResult::success([
                'status' => 'none',
            ], 200);
        }

        return ServiceResult::success($this->buildUploadProgressPayload($state), 200);
    }

    private function getCachedUploadState(int $sessionId): ?array
    {
        $state = Cache::get($this->cacheKey($sessionId));

        return is_array($state) ? $state : null;
    }

    private function putCachedUploadState(int $sessionId, array $state): void
    {
        Cache::put($this->cacheKey($sessionId), $state, self::CACHE_TTL_SECONDS);
    }

    private function requireUploadState(int $sessionId, int $uploadId): array
    {
        $state = $this->resolveUploadState($sessionId);

        if ((int) ($state['upload_id'] ?? 0) !== $uploadId) {
            throw new RuntimeException('upload_id no coincide con la subida activa');
        }

        if (empty($state['upload_url'])) {
            throw new RuntimeException('No se encontro upload_url para continuar la subida');
        }

        return $state;
    }

    private function resolveUploadState(int $sessionId, bool $failIfMissing = true): array
    {
        $state = $this->getCachedUploadState($sessionId);
        if ($state !== null) {
            return $state;
        }

        $progress = $this->getUploadProgress($sessionId);
        if ($progress->ok() && is_array($progress->data()) && ($progress->data()['status'] ?? 'none') !== 'none') {
            return $progress->data();
        }

        if ($failIfMissing) {
            throw new RuntimeException('No existe una subida activa para esta sesion');
        }

        return [];
    }

    private function buildUploadProgressPayload(array $state): array
    {
        return [
            'upload_id' => $state['upload_id'] ?? null,
            'upload_url' => $state['upload_url'] ?? null,
            'bytes_uploaded' => (int) ($state['bytes_uploaded'] ?? 0),
            'filesize' => (int) ($state['filesize'] ?? 0),
            'status' => $state['status'] ?? 'none',
            'file_id' => $state['file_id'] ?? null,
            'filename' => $state['filename'] ?? null,
            'mime_type' => $state['mime_type'] ?? null,
        ];
    }

    private function cacheKey(int $sessionId): string
    {
        return self::CACHE_KEY_PREFIX . $sessionId;
    }

    private function validateChatFile(UploadedFile $file): ?ServiceResult
    {
        if (!$file->isValid()) {
            return ServiceResult::failure(['error' => 'El chat de Zoom no es valido.'], 422);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension !== 'txt') {
            return ServiceResult::failure(['error' => 'El chat de Zoom debe ser un archivo .txt.'], 422);
        }

        $filesize = (int) $file->getSize();
        if ($filesize <= 0 || $filesize > self::CHAT_MAX_BYTES) {
            return ServiceResult::failure(['error' => 'El chat de Zoom no debe superar 5 MB.'], 422);
        }

        return null;
    }

    private function normalizeChatFilename(string $filename): string
    {
        $base = trim(pathinfo($filename, PATHINFO_FILENAME));
        $base = $base !== '' ? $base : 'chat-de-zoom';
        $base = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $base) ?: 'chat-de-zoom';

        return mb_substr($base, 0, 220) . '.txt';
    }

    private function normalizeTextContent(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'UTF-16, ISO-8859-1, Windows-1252');
            if (is_string($converted)) {
                return $converted;
            }
        }

        return $content;
    }

    private function parseZoomChat(string $content): array
    {
        $messages = [];
        $lines = preg_split('/\R/u', $content) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?)\s+(.+?):\s+(.*)$/u', $line, $matches)) {
                $messages[] = [
                    'time' => $matches[1],
                    'participant' => trim($matches[2]),
                    'message' => trim($matches[3]),
                ];
            }
        }

        return count($messages) > 0 ? $messages : [];
    }
}
