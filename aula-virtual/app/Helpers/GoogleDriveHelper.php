<?php

namespace App\Helpers;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GoogleDriveHelper
{
    private const SERVICE_ACCOUNT_PATH = 'storage/google/service-account.json';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const DRIVE_FILES_URI =
        'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&includeItemsFromAllDrives=true&fields=id';

    private const DRIVE_UPLOAD_URI =
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&includeItemsFromAllDrives=true';

    private Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    /*
    |--------------------------------------------------------------------------
    | Crear carpeta de curso
    |--------------------------------------------------------------------------
    */

    public function createCourseFolder($cursoId, string $cursoNombre, string $lmsFolderId): string
    {
        $token = $this->getAccessToken();

        $body = [
            'name' => $this->normalizeFolderName($cursoId . '-' . $cursoNombre),
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$lmsFolderId],
        ];

        return $this->createFolder($body, $token);
    }

    /*
    |--------------------------------------------------------------------------
    | Crear carpeta de sesión
    |--------------------------------------------------------------------------
    */

    public function createSessionFolder($sesionId, string $sesionNombre, string $courseFolderId): string
    {
        $token = $this->getAccessToken();

        $body = [
            'name' => $this->normalizeFolderName($sesionId . '-' . $sesionNombre),
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$courseFolderId],
        ];

        return $this->createFolder($body, $token);
    }

    /*
    |--------------------------------------------------------------------------
    | Crear carpeta genérica
    |--------------------------------------------------------------------------
    */

    private function createFolder(array $body, string $token): string
    {
        try {

            $response = $this->client->post(self::DRIVE_FILES_URI, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'http_errors' => false,
            ]);

        } catch (\Throwable $e) {

            Log::error('drive_create_folder_error', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Error conectando con Google Drive');
        }

        $status = $response->getStatusCode();
        $data = json_decode($response->getBody()->getContents(), true);

        if ($status < 200 || $status >= 300 || empty($data['id'])) {

            Log::error('drive_create_folder_error', [
                'status' => $status,
                'body' => $data,
            ]);

            throw new RuntimeException('Google Drive devolvió un error creando carpeta');
        }

        return $data['id'];
    }

    /*
    |--------------------------------------------------------------------------
    | Crear sesión resumable
    |--------------------------------------------------------------------------
    */

    public function createResumableSession(array $data): string
    {
        $token = $this->getAccessToken();

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'X-Upload-Content-Type' => $data['mime_type'],
            'X-Upload-Content-Length' => (string) $data['filesize'],
        ];

        $body = [
            'name' => $data['filename'],
            'parents' => [$data['folder_id']],
        ];

        if (!empty($data['file_id'])) {
            $body['id'] = $data['file_id'];
        }

        Log::info('DRIVE_CREATE_RESUMABLE_SESSION', [
            'filename' => $data['filename'],
            'folder_id' => $data['folder_id'],
            'file_id' => $data['file_id'] ?? null,
            'filesize' => $data['filesize'],
        ]);

        try {

            $response = $this->client->post(self::DRIVE_UPLOAD_URI, [
                'headers' => $headers,
                'json' => $body,
                'http_errors' => false,
            ]);

        } catch (\Throwable $e) {

            Log::error('drive_api_error', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Error conectando con Google Drive');
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {

            $errorBody = $response->getBody()->getContents();

            Log::error('drive_api_error', [
                'status' => $status,
                'body' => $errorBody,
            ]);

            throw new RuntimeException('Google Drive devolvió un error: ' . $errorBody);
        }

        $uploadUrl = $response->getHeaderLine('Location');

        if (!$uploadUrl) {

            Log::error('drive_api_error', [
                'status' => $status,
                'missing_location' => true,
            ]);

            throw new RuntimeException('No se recibió URL de subida de Google Drive');
        }

        return $uploadUrl;
    }

    public function generateFileId(): string
    {
        $token = $this->getAccessToken();

        try {
            $response = $this->client->get(
                'https://www.googleapis.com/drive/v3/files/generateIds',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                    'query' => [
                        'count' => 1,
                        'space' => 'drive',
                        'type' => 'files',
                    ],
                    'http_errors' => false,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('drive_generate_id_error', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Error generando file_id de Google Drive');
        }

        $status = $response->getStatusCode();
        $data = json_decode($response->getBody()->getContents(), true);
        $fileId = $data['ids'][0] ?? null;

        if ($status < 200 || $status >= 300 || !$fileId) {
            Log::error('drive_generate_id_error', [
                'status' => $status,
                'body' => $data,
            ]);

            throw new RuntimeException('Google Drive no devolvió un file_id válido');
        }

        return $fileId;
    }

    /*
    |--------------------------------------------------------------------------
    | Obtener Access Token
    |--------------------------------------------------------------------------
    */

    private function getAccessToken(): string
    {
        return Cache::remember('google_drive_token', 3500, function () {

            $path = base_path(self::SERVICE_ACCOUNT_PATH);

            if (!file_exists($path)) {
                throw new RuntimeException('No se encontró el archivo de credenciales de Google');
            }

            $creds = json_decode(file_get_contents($path), true);

            if (!$creds || empty($creds['client_email']) || empty($creds['private_key'])) {
                throw new RuntimeException('Credenciales de Google inválidas');
            }

            $now = time();

            $payload = [
                'iss' => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/drive',
                'aud' => self::TOKEN_URI,
                'exp' => $now + 3600,
                'iat' => $now,
            ];

            $jwt = $this->makeJwt([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ], $payload, $creds['private_key']);

            try {

                $response = $this->client->post(self::TOKEN_URI, [
                    'form_params' => [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $jwt,
                    ],
                    'http_errors' => false,
                ]);

            } catch (\Throwable $e) {

                Log::error('drive_token_error', [
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException('Error obteniendo token de Google');
            }

            $body = json_decode($response->getBody()->getContents(), true);

            if (empty($body['access_token'])) {

                Log::error('drive_token_error', [
                    'response' => $body,
                ]);

                throw new RuntimeException('No se pudo obtener access token de Google');
            }

            return $body['access_token'];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Crear JWT
    |--------------------------------------------------------------------------
    */

    private function makeJwt(array $header, array $payload, string $privateKey): string
    {
        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($payload)),
        ];

        $signingInput = implode('.', $segments);

        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el JWT');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    /*
    |--------------------------------------------------------------------------
    | Normalizar nombres
    |--------------------------------------------------------------------------
    */

    public function normalizeFolderName(string $name): string
    {
        $name = str_replace(' ', '_', $name);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);

        return trim($name);
    }

    public function getFile(string $fileId): ?array
{
    $token = $this->getAccessToken();

    try {

        $response = $this->client->get(
            "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=id,size",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ],
                'http_errors' => false,
                'timeout' => 10
            ]
        );

    } catch (\Throwable $e) {

        Log::error('drive_file_check_error', [
            'file_id' => $fileId,
            'error' => $e->getMessage()
        ]);

        return null;
    }

    if ($response->getStatusCode() !== 200) {
        return null;
    }

    return json_decode($response->getBody()->getContents(), true);
}

    public function findFileInFolder(string $folderId, string $filename, ?int $filesize = null): ?array
    {
        $token = $this->getAccessToken();
        $escapedName = str_replace("'", "\\'", $filename);

        try {
            $response = $this->client->get(
                'https://www.googleapis.com/drive/v3/files',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token
                    ],
                    'query' => [
                        'supportsAllDrives' => 'true',
                        'includeItemsFromAllDrives' => 'true',
                        'fields' => 'files(id,name,size,createdTime)',
                        'orderBy' => 'createdTime desc',
                        'pageSize' => 10,
                        'q' => sprintf(
                            "'%s' in parents and name = '%s' and trashed = false",
                            $folderId,
                            $escapedName
                        ),
                    ],
                    'http_errors' => false,
                    'timeout' => 10,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('drive_find_file_error', [
                'folder_id' => $folderId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            Log::warning('drive_find_file_http_error', [
                'folder_id' => $folderId,
                'filename' => $filename,
                'status' => $response->getStatusCode(),
                'body' => $response->getBody()->getContents(),
            ]);

            return null;
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $files = $data['files'] ?? [];

        if (empty($files)) {
            return null;
        }

        if ($filesize !== null) {
            foreach ($files as $file) {
                if ((int) ($file['size'] ?? -1) === $filesize) {
                    return $file;
                }
            }
        }

        return $files[0] ?? null;
    }

/*
|--------------------------------------------------------------------------
| Subir chunk a Google Drive (Resumable Upload)
|--------------------------------------------------------------------------
*/
public function uploadChunk(
    string $uploadUrl,
    \Illuminate\Http\UploadedFile $file,
    int $chunkIndex,
    int $totalChunks,
    int $fileSize
): array {

Log::info('ENTER_GOOGLE_DRIVE_HELPER_UPLOAD_CHUNK', [
        'chunk_index' => $chunkIndex,
        'total_chunks' => $totalChunks,
        'file_size' => $fileSize,
        'original_name' => $file->getClientOriginalName(),
        'real_path' => $file->getRealPath(),
    ]);

    $token = $this->getAccessToken();

    $chunkSize = filesize($file->getRealPath());
    $standardChunk = 60 * 1024 * 1024;

    $start = $chunkIndex * $standardChunk;
    $end   = $start + $chunkSize - 1;

    if ($end >= $fileSize) {
        $end = $fileSize - 1;
    }

    Log::info('DRIVE_CHUNK_REQUEST_DATA', [
        'chunk_index' => $chunkIndex,
        'total_chunks' => $totalChunks,
        'chunk_size' => $chunkSize,
        'start' => $start,
        'end' => $end,
        'file_size' => $fileSize,
        'content_range' => "bytes {$start}-{$end}/{$fileSize}",
        'upload_url_prefix' => substr($uploadUrl, 0, 120),
    ]);

    try {
        $response = $this->client->put($uploadUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/octet-stream',
                'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            ],
            'body' => file_get_contents($file->getRealPath()),
            'http_errors' => false,
            'timeout' => 0,
        ]);
    } catch (\Throwable $e) {
        Log::error('drive_chunk_upload_error', [
            'chunk_index' => $chunkIndex,
            'error' => $e->getMessage()
        ]);

        throw new RuntimeException('Error subiendo chunk a Google Drive');
    }

    $status = $response->getStatusCode();

    Log::info('DRIVE_CHUNK_UPLOAD_RESPONSE', [
        'status' => $status,
        'chunk_index' => $chunkIndex,
        'total_chunks' => $totalChunks,
        'range' => $response->getHeaderLine('Range'),
    ]);

    if ($status === 308) {
        $range = $response->getHeaderLine('Range');

        if ($range && preg_match('/bytes=0-(\d+)/', $range, $matches)) {
            return [
                'bytes_uploaded' => (int) $matches[1] + 1,
                'file_id' => null,
            ];
        }

        return [
            'bytes_uploaded' => null,
            'file_id' => null,
        ];
    }

    if ($status === 200 || $status === 201) {
        $body = json_decode($response->getBody()->getContents(), true);

        Log::info('DRIVE_UPLOAD_COMPLETED', [
            'drive_file_id' => $body['id'] ?? null,
            'size' => $body['size'] ?? null,
            'chunk_index' => $chunkIndex,
        ]);

        return [
            'bytes_uploaded' => isset($body['size']) ? (int) $body['size'] : $fileSize,
            'file_id' => $body['id'] ?? null,
        ];
    }

    $rawBody = $response->getBody()->getContents();

    Log::error('drive_chunk_upload_error', [
        'status' => $status,
        'chunk_index' => $chunkIndex,
        'body' => $rawBody,
    ]);

    throw new RuntimeException('Google Drive rechazó el chunk');
}

    public function deleteFile(string $fileId): void
    {
        $client = $this->getClient();

        $service = new \Google\Service\Drive($client);

        $service->files->delete($fileId);
    }

    public function setPublicPermission(string $fileId): void
{
    $token = $this->getAccessToken();

    $response = $this->client->post(
        "https://www.googleapis.com/drive/v3/files/{$fileId}/permissions?supportsAllDrives=true",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'type' => 'anyone',
                'role' => 'reader'
            ],
            'http_errors' => false
        ]
    );

    Log::info('DRIVE_PERMISSION_RESPONSE', [
        'status' => $response->getStatusCode(),
        'body' => $response->getBody()->getContents()
    ]);
}

 public function getVideoStatus(string $fileId)
{
    $token = $this->getAccessToken();

    try {
        $response = $this->client->get(
            "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=videoMediaMetadata,size&supportsAllDrives=true",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ],
                'http_errors' => false,
                'timeout' => 10
            ]
        );
    } catch (\Throwable $e) {
        Log::error('drive_video_status_error', [
            'file_id' => $fileId,
            'error' => $e->getMessage()
        ]);

        return [
            'status' => 'unknown',
            'file_id' => null
        ];
    }

    $statusCode = $response->getStatusCode();

    if ($statusCode === 404) {
        Log::warning('drive_video_status_http_error', [
            'file_id' => $fileId,
            'status_code' => $statusCode
        ]);

        return [
            'status' => 'missing',
            'file_id' => null
        ];
    }

    if ($statusCode !== 200) {
        Log::warning('drive_video_status_http_error', [
            'file_id' => $fileId,
            'status_code' => $statusCode
        ]);

        return [
            'status' => 'unknown',
            'file_id' => null
        ];
    }

    $body = json_decode($response->getBody()->getContents(), true);

    Log::info('drive_video_status_response', [
        'file_id' => $fileId,
        'body' => $body
    ]);

    if (!empty($body['videoMediaMetadata'])) {
        return [
            'status' => 'ready',
            'file_id' => $fileId
        ];
    }

    if (!empty($body['size'])) {
        return [
            'status' => 'uploaded',
            'file_id' => $fileId
        ];
    }

    return [
        'status' => 'processing',
        'file_id' => $fileId
    ];
}
}
