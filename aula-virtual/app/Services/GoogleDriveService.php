<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleDriveService
{
    private const DEFAULT_SERVICE_ACCOUNT_PATH = 'storage/google/service-account.json';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive';
    private const DRIVE_FILES_URI =
        'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&includeItemsFromAllDrives=true';
    private const DRIVE_UPLOAD_URI =
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&includeItemsFromAllDrives=true';

    private Client $client;
    private string $serviceAccountPath;
    private ?string $lmsFolderId;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
        $this->serviceAccountPath = (string) config(
            'services.google_drive.service_account_path',
            self::DEFAULT_SERVICE_ACCOUNT_PATH
        );
        $this->lmsFolderId = $this->normalizeConfigValue(
            config('services.google_drive.lms_folder_id')
        );
    }

    public function createResumableUpload(array $data): string
    {
        $filename = trim((string) ($data['filename'] ?? ''));
        $mimeType = trim((string) ($data['mime_type'] ?? ''));
        $folderId = trim((string) ($data['folder_id'] ?? $this->getRootFolderId()));
        $filesize = (int) ($data['filesize'] ?? 0);
        $fileId = trim((string) ($data['file_id'] ?? ''));

        if ($filename === '' || $mimeType === '' || $folderId === '' || $filesize <= 0) {
            throw new RuntimeException('Datos incompletos para crear la subida resumible');
        }

        $token = $this->getAccessToken();
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'X-Upload-Content-Type' => $mimeType,
            'X-Upload-Content-Length' => (string) $filesize,
        ];

        $body = [
            'name' => $filename,
            'parents' => [$folderId],
        ];

        if ($fileId !== '') {
            $body['id'] = $fileId;
        }

        Log::info('google_drive_service.create_resumable_upload.start', [
            'filename' => $filename,
            'folder_id' => $folderId,
            'file_id' => $fileId !== '' ? $fileId : null,
            'filesize' => $filesize,
        ]);

        try {
            $response = $this->client->post(self::DRIVE_UPLOAD_URI, [
                'headers' => $headers,
                'json' => $body,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('google_drive_service.create_resumable_upload.connection_error', [
                'filename' => $filename,
                'folder_id' => $folderId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Error conectando con Google Drive al iniciar la subida');
        }

        $status = $response->getStatusCode();
        $uploadUrl = $response->getHeaderLine('Location');
        $bodyText = $response->getBody()->getContents();

        if ($status < 200 || $status >= 300 || $uploadUrl === '') {
            Log::error('google_drive_service.create_resumable_upload.http_error', [
                'status' => $status,
                'filename' => $filename,
                'folder_id' => $folderId,
                'response_body' => $bodyText,
                'missing_location' => $uploadUrl === '',
            ]);

            throw new RuntimeException('Google Drive no devolvio una sesion resumible valida');
        }

        return $uploadUrl;
    }

    public function uploadChunk(
        string $uploadUrl,
        UploadedFile $file,
        int $chunkIndex,
        int $totalChunks,
        int $fileSize,
        int $startByte = 0,
        ?int $endByte = null
    ): array {
        if ($uploadUrl === '' || !$file->isValid() || $chunkIndex < 0 || $totalChunks <= 0 || $fileSize <= 0 || $startByte < 0) {
            throw new RuntimeException('Datos invalidos para subir el chunk a Google Drive');
        }

        $realPath = $file->getRealPath();
        if ($realPath === false || !is_file($realPath)) {
            throw new RuntimeException('No se pudo leer el archivo temporal del chunk');
        }

        $token = $this->getAccessToken();
        $chunkSize = filesize($realPath);
        $start = $startByte;
        $end = $endByte ?? ($start + $chunkSize - 1);

        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }

        if ($start > $end || $start >= $fileSize) {
            throw new RuntimeException('Offset invalido para continuar la subida a Google Drive');
        }

        if (($end - $start + 1) !== $chunkSize) {
            throw new RuntimeException('El rango del chunk no coincide con el archivo recibido');
        }

        Log::info('google_drive_service.upload_chunk.start', [
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'chunk_size' => $chunkSize,
            'file_size' => $fileSize,
            'content_range' => "bytes {$start}-{$end}/{$fileSize}",
        ]);

        try {
            $response = $this->client->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/octet-stream',
                    'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
                ],
                'body' => file_get_contents($realPath),
                'http_errors' => false,
                'timeout' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('google_drive_service.upload_chunk.connection_error', [
                'chunk_index' => $chunkIndex,
                'total_chunks' => $totalChunks,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Error conectando con Google Drive al subir el chunk');
        }

        $status = $response->getStatusCode();
        $range = $response->getHeaderLine('Range');

        Log::info('google_drive_service.upload_chunk.response', [
            'status' => $status,
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'range' => $range,
        ]);

        if ($status === 308) {
            if ($range !== '' && preg_match('/bytes=0-(\d+)/', $range, $matches)) {
                return [
                    'status' => 'chunk_uploaded',
                    'bytes_uploaded' => (int) $matches[1] + 1,
                    'file_id' => null,
                ];
            }

            return [
                'status' => 'chunk_uploaded',
                'bytes_uploaded' => null,
                'file_id' => null,
            ];
        }

        if ($status === 200 || $status === 201) {
            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'status' => 'upload_completed',
                'bytes_uploaded' => isset($body['size']) ? (int) $body['size'] : $fileSize,
                'file_id' => $body['id'] ?? null,
            ];
        }

        $rawBody = $response->getBody()->getContents();

        Log::error('google_drive_service.upload_chunk.http_error', [
            'status' => $status,
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'chunk_size' => $chunkSize,
            'file_size' => $fileSize,
            'content_range' => "bytes {$start}-{$end}/{$fileSize}",
            'response_body' => $rawBody,
        ]);

        throw new RuntimeException('Google Drive rechazo el chunk');
    }

    public function getFile(string $fileId): ?array
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return null;
        }

        $token = $this->getAccessToken();
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=id,size&supportsAllDrives=true";

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            Log::error('google_drive_service.get_file.connection_error', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            Log::warning('google_drive_service.get_file.http_error', [
                'file_id' => $fileId,
                'status' => $response->getStatusCode(),
            ]);

            return null;
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    public function uploadSmallFile(UploadedFile $file, string $filename, string $mimeType = 'text/plain'): array
    {
        $filesize = (int) $file->getSize();

        if (!$file->isValid() || $filesize <= 0) {
            throw new RuntimeException('No se pudo leer el archivo para subir a Google Drive');
        }

        $uploadUrl = $this->createResumableUpload([
            'filename' => $filename,
            'mime_type' => $mimeType,
            'filesize' => $filesize,
        ]);

        $result = $this->uploadChunk(
            $uploadUrl,
            $file,
            0,
            1,
            $filesize,
            0,
            $filesize - 1
        );

        $fileId = trim((string) ($result['file_id'] ?? ''));
        if ($fileId === '') {
            throw new RuntimeException('Google Drive no confirmo el archivo del chat');
        }

        $this->setPublicPermission($fileId);
        $this->applyViewerRestrictions($fileId);

        return [
            'file_id' => $fileId,
            'filesize' => $filesize,
        ];
    }

    public function downloadTextFile(string $fileId): array
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            throw new RuntimeException('file_id es requerido para descargar de Google Drive');
        }

        $token = $this->getAccessToken();
        $metaUrl = "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=id,name,mimeType,size&supportsAllDrives=true";
        $downloadUrl = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&supportsAllDrives=true";

        try {
            $metaResponse = $this->client->get($metaUrl, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'http_errors' => false,
                'timeout' => 10,
            ]);

            $downloadResponse = $this->client->get($downloadUrl, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'http_errors' => false,
                'timeout' => 15,
            ]);
        } catch (\Throwable $e) {
            Log::error('google_drive_service.download_text.connection_error', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Error conectando con Google Drive al descargar el chat');
        }

        if ($metaResponse->getStatusCode() !== 200 || $downloadResponse->getStatusCode() !== 200) {
            Log::warning('google_drive_service.download_text.http_error', [
                'file_id' => $fileId,
                'meta_status' => $metaResponse->getStatusCode(),
                'download_status' => $downloadResponse->getStatusCode(),
            ]);

            throw new RuntimeException('Google Drive no permitio descargar el chat');
        }

        $meta = json_decode($metaResponse->getBody()->getContents(), true) ?: [];

        return [
            'filename' => $meta['name'] ?? 'chat-de-zoom.txt',
            'mime_type' => $meta['mimeType'] ?? 'text/plain',
            'filesize' => isset($meta['size']) ? (int) $meta['size'] : null,
            'content' => (string) $downloadResponse->getBody()->getContents(),
        ];
    }

    public function setPublicPermission(string $fileId): void
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            throw new RuntimeException('file_id es requerido para publicar el archivo en Google Drive');
        }

        $token = $this->getAccessToken();
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}/permissions?supportsAllDrives=true";

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'type' => 'anyone',
                    'role' => 'reader',
                    'allowFileDiscovery' => false,
                ],
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            Log::error('google_drive_service.set_public_permission.connection_error', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Error conectando con Google Drive al publicar el archivo');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            Log::error('google_drive_service.set_public_permission.http_error', [
                'file_id' => $fileId,
                'status' => $status,
                'response_body' => $response->getBody()->getContents(),
            ]);

            throw new RuntimeException('Google Drive no permitio publicar el archivo');
        }
    }

    public function applyViewerRestrictions(string $fileId): void
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return;
        }

        $token = $this->getAccessToken();
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?supportsAllDrives=true";

        try {
            $response = $this->client->patch($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'copyRequiresWriterPermission' => true,
                ],
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            Log::warning('google_drive_service.apply_viewer_restrictions.connection_error', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            Log::warning('google_drive_service.apply_viewer_restrictions.http_error', [
                'file_id' => $fileId,
                'status' => $status,
                'response_body' => $response->getBody()->getContents(),
            ]);
        }
    }

    public function getVideoStatus(string $fileId): array
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return [
                'status' => 'none',
                'file_id' => null,
            ];
        }

        $token = $this->getAccessToken();
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=videoMediaMetadata,size,mimeType&supportsAllDrives=true";

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            Log::error('google_drive_service.get_video_status.connection_error', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'unknown',
                'file_id' => null,
            ];
        }

        $status = $response->getStatusCode();
        if ($status === 404) {
            return [
                'status' => 'missing',
                'file_id' => null,
            ];
        }

        if ($status !== 200) {
            Log::warning('google_drive_service.get_video_status.http_error', [
                'file_id' => $fileId,
                'status' => $status,
            ]);

            return [
                'status' => 'unknown',
                'file_id' => null,
            ];
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (!empty($body['videoMediaMetadata'])) {
            return [
                'status' => 'ready',
                'file_id' => $fileId,
            ];
        }

        $mimeType = (string) ($body['mimeType'] ?? '');

        if (!empty($body['size']) && str_starts_with($mimeType, 'video/')) {
            return [
                'status' => 'processing',
                'file_id' => $fileId,
            ];
        }

        if (!empty($body['size'])) {
            return [
                'status' => 'uploaded',
                'file_id' => $fileId,
            ];
        }

        return [
            'status' => 'processing',
            'file_id' => $fileId,
        ];
    }

    public function deleteFile(string $fileId): void
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            throw new RuntimeException('file_id es requerido para eliminar en Google Drive');
        }

        $token = $this->getAccessToken();
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?supportsAllDrives=true";

        try {
            $response = $this->client->delete($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            Log::error('google_drive_service.delete_file.connection_error', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Error conectando con Google Drive al eliminar el archivo');
        }

        $status = $response->getStatusCode();
        if ($status === 404) {
            Log::info('google_drive_service.delete_file.already_missing', [
                'file_id' => $fileId,
            ]);

            return;
        }

        if (!in_array($status, [200, 204], true)) {
            Log::error('google_drive_service.delete_file.http_error', [
                'file_id' => $fileId,
                'status' => $status,
                'response_body' => $response->getBody()->getContents(),
            ]);

            throw new RuntimeException('Google Drive no permitio eliminar el archivo');
        }
    }

    private function getAccessToken(): string
    {
        return Cache::remember('google_drive_service.access_token', 3500, function () {
            $path = base_path($this->serviceAccountPath);

            if (!is_file($path)) {
                Log::error('google_drive_service.access_token.credentials_missing', [
                    'path' => $path,
                ]);

                throw new RuntimeException('No se encontro el archivo de credenciales de Google Drive');
            }

            $creds = json_decode(file_get_contents($path), true);

            if (!is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
                Log::error('google_drive_service.access_token.invalid_credentials', [
                    'path' => $path,
                ]);

                throw new RuntimeException('Las credenciales de Google Drive son invalidas');
            }

            $now = time();
            $payload = [
                'iss' => $creds['client_email'],
                'scope' => self::DRIVE_SCOPE,
                'aud' => self::TOKEN_URI,
                'exp' => $now + 3600,
                'iat' => $now,
            ];

            $jwt = $this->makeJwt(
                [
                    'alg' => 'RS256',
                    'typ' => 'JWT',
                ],
                $payload,
                $creds['private_key']
            );

            try {
                $response = $this->client->post(self::TOKEN_URI, [
                    'form_params' => [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $jwt,
                    ],
                    'http_errors' => false,
                ]);
            } catch (\Throwable $e) {
                Log::error('google_drive_service.access_token.connection_error', [
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException('Error obteniendo access token de Google Drive');
            }

            $body = json_decode($response->getBody()->getContents(), true);
            if (($response->getStatusCode() < 200 || $response->getStatusCode() >= 300)
                || empty($body['access_token'])) {
                Log::error('google_drive_service.access_token.http_error', [
                    'status' => $response->getStatusCode(),
                    'response_body' => $body,
                ]);

                throw new RuntimeException('Google Drive no devolvio un access token valido');
            }

            return $body['access_token'];
        });
    }

    public function getRootFolderId(): string
    {
        if ($this->lmsFolderId === null) {
            throw new RuntimeException('GOOGLE_DRIVE_LMS_FOLDER_ID no esta configurado');
        }

        return $this->lmsFolderId;
    }

    private function makeJwt(array $header, array $payload, string $privateKey): string
    {
        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($payload)),
        ];

        $signingInput = implode('.', $segments);
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el JWT para Google Drive');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function normalizeConfigValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
