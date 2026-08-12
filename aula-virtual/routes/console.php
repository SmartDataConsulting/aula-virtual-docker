<?php

use App\Support\AuthSessionKeys;
use Illuminate\Http\UploadedFile;
use App\Services\GoogleDriveService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('google-drive:smoke-test {--file=} {--heavy-file=} {--keep-file}', function () {
    $drive = app(GoogleDriveService::class);

    $sourcePath = (string) $this->option('file');
    $heavySourcePath = (string) $this->option('heavy-file');
    $createdTempFile = false;
    $createdFileId = null;
    $heavyCreatedFileId = null;
    $chunkSize = 60 * 1024 * 1024;

    try {
        $rootFolderId = $drive->getRootFolderId();
        $this->info('OK root_folder_id');

        if ($sourcePath === '') {
            $sourcePath = storage_path('app/google-drive-smoke-test.txt');

            File::ensureDirectoryExists(dirname($sourcePath));
            File::put(
                $sourcePath,
                "Google Drive smoke test\n" .
                'timestamp=' . now()->toIso8601String() . "\n" .
                'app=' . config('app.name') . "\n"
            );

            $createdTempFile = true;
        }

        if (!is_file($sourcePath)) {
            throw new RuntimeException("No existe el archivo de prueba: {$sourcePath}");
        }

        $filename = basename($sourcePath);
        $mimeType = File::mimeType($sourcePath) ?: 'application/octet-stream';
        $fileSize = filesize($sourcePath);

        if ($fileSize === false || $fileSize <= 0) {
            throw new RuntimeException("No se pudo obtener un filesize valido para {$sourcePath}");
        }

        $uploadUrl = $drive->createResumableUpload([
            'filename' => $filename,
            'mime_type' => $mimeType,
            'filesize' => $fileSize,
            'folder_id' => $rootFolderId,
        ]);
        $this->info('OK createResumableUpload');

        $uploadedFile = new UploadedFile(
            $sourcePath,
            $filename,
            $mimeType,
            null,
            true
        );

        $uploadResult = $drive->uploadChunk(
            $uploadUrl,
            $uploadedFile,
            0,
            1,
            $fileSize
        );

        $createdFileId = $uploadResult['file_id'] ?? null;

        if (!$createdFileId) {
            throw new RuntimeException('Google Drive no devolvio file_id luego de uploadChunk');
        }

        $this->info('OK uploadChunk');

        $file = $drive->getFile((string) $createdFileId);

        if (!$file || ($file['id'] ?? null) !== $createdFileId) {
            throw new RuntimeException('getFile no pudo recuperar el archivo subido');
        }

        $this->info('OK getFile');

        if ($this->option('keep-file')) {
            $this->warn("SKIP deleteFile file_id={$createdFileId}");
        } else {
            $drive->deleteFile((string) $createdFileId);

            if ($drive->getFile((string) $createdFileId) !== null) {
                throw new RuntimeException('deleteFile no elimino el archivo de Google Drive');
            }

            $this->info('OK deleteFile');
        }

        if ($heavySourcePath !== '') {
            if (!is_file($heavySourcePath)) {
                throw new RuntimeException("No existe el archivo pesado: {$heavySourcePath}");
            }

            $heavyFilename = basename($heavySourcePath);
            $heavyMimeType = File::mimeType($heavySourcePath) ?: 'application/octet-stream';
            $heavyFileSize = filesize($heavySourcePath);

            if ($heavyFileSize === false || $heavyFileSize <= 0) {
                throw new RuntimeException("No se pudo obtener un filesize valido para {$heavySourcePath}");
            }

            $this->line('');
            $this->info("Iniciando prueba pesada por chunks: {$heavyFilename}");
            $this->line('Tamano total: ' . number_format($heavyFileSize) . ' bytes');

            $heavyUploadUrl = $drive->createResumableUpload([
                'filename' => $heavyFilename,
                'mime_type' => $heavyMimeType,
                'filesize' => $heavyFileSize,
                'folder_id' => $rootFolderId,
            ]);

            $totalChunks = (int) ceil($heavyFileSize / $chunkSize);
            $chunkDirectory = storage_path('app/google-drive-smoke-chunks');
            File::ensureDirectoryExists($chunkDirectory);

            $handle = fopen($heavySourcePath, 'rb');
            if ($handle === false) {
                throw new RuntimeException("No se pudo abrir el archivo pesado: {$heavySourcePath}");
            }

            try {
                for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
                    $chunkData = fread($handle, $chunkSize);

                    if ($chunkData === false) {
                        throw new RuntimeException("Error leyendo chunk {$chunkIndex} de {$heavySourcePath}");
                    }

                    if ($chunkData === '') {
                        throw new RuntimeException("Google Drive smoke test leyo un chunk vacio inesperado");
                    }

                    $chunkPath = $chunkDirectory . DIRECTORY_SEPARATOR . sprintf(
                        'chunk-%s-%03d.part',
                        date('YmdHis'),
                        $chunkIndex
                    );

                    File::put($chunkPath, $chunkData);

                    $chunkUploadedFile = new UploadedFile(
                        $chunkPath,
                        $heavyFilename . '.part',
                        'application/octet-stream',
                        null,
                        true
                    );

                    $this->output->write(sprintf(
                        "\rSubiendo chunk %d/%d...",
                        $chunkIndex + 1,
                        $totalChunks
                    ));

                    $heavyUploadResult = $drive->uploadChunk(
                        $heavyUploadUrl,
                        $chunkUploadedFile,
                        $chunkIndex,
                        $totalChunks,
                        $heavyFileSize
                    );

                    File::delete($chunkPath);

                    $heavyCreatedFileId = $heavyUploadResult['file_id'] ?? $heavyCreatedFileId;
                    $bytesUploaded = (int) ($heavyUploadResult['bytes_uploaded'] ?? 0);
                    $percent = min(100, (int) floor(($bytesUploaded / $heavyFileSize) * 100));

                    $this->output->write(sprintf(
                        "\rSubiendo chunk %d/%d... %d%% (%s/%s bytes)",
                        $chunkIndex + 1,
                        $totalChunks,
                        $percent,
                        number_format($bytesUploaded),
                        number_format($heavyFileSize)
                    ));
                }

                $this->line('');
            } finally {
                fclose($handle);

                if (File::exists($chunkDirectory)) {
                    File::deleteDirectory($chunkDirectory);
                }
            }

            if (!$heavyCreatedFileId) {
                throw new RuntimeException('La subida pesada termino sin devolver file_id');
            }

            $heavyFile = $drive->getFile((string) $heavyCreatedFileId);
            if (!$heavyFile || ($heavyFile['id'] ?? null) !== $heavyCreatedFileId) {
                throw new RuntimeException('No se pudo confirmar el archivo pesado en Google Drive');
            }

            $this->info('OK heavy_upload_chunked');
            $this->line('Drive File ID pesado: ' . $heavyCreatedFileId);
        }

        $this->line('');
        $this->info('Smoke test completado');
        $this->line("Archivo probado: {$sourcePath}");
        $this->line('Folder ID: ' . $rootFolderId);

        if ($createdFileId) {
            $this->line('Drive File ID: ' . $createdFileId);
        }

        return SymfonyCommand::SUCCESS;
    } catch (Throwable $e) {
        if ($createdFileId && !$this->option('keep-file')) {
            try {
                $drive->deleteFile((string) $createdFileId);
            } catch (Throwable $cleanupException) {
                $this->warn('No se pudo limpiar el archivo de prueba en Drive: ' . $cleanupException->getMessage());
            }
        }

        if ($heavyCreatedFileId && !$this->option('keep-file')) {
            try {
                $drive->deleteFile((string) $heavyCreatedFileId);
            } catch (Throwable $cleanupException) {
                $this->warn('No se pudo limpiar el archivo pesado de prueba en Drive: ' . $cleanupException->getMessage());
            }
        }

        $this->error('FAIL ' . $e->getMessage());

        return SymfonyCommand::FAILURE;
    } finally {
        if ($createdTempFile && is_file($sourcePath)) {
            File::delete($sourcePath);
        }
    }
})->purpose('Valida GoogleDriveService con prueba basica y subida pesada por chunks');

Artisan::command('video:endpoint-heavy-test {courseId} {sessionId} {file?} {--poll=12} {--sleep=10}', function () {
    $courseId = (int) $this->argument('courseId');
    $sessionId = (int) $this->argument('sessionId');
    $sourcePath = (string) ($this->argument('file') ?: storage_path('app/test-videos/video_small.mp4'));
    $pollAttempts = max(1, (int) $this->option('poll'));
    $sleepSeconds = max(1, (int) $this->option('sleep'));
    $chunkSize = 60 * 1024 * 1024;

    if ($courseId <= 0 || $sessionId <= 0) {
        $this->error('courseId y sessionId deben ser enteros positivos');

        return SymfonyCommand::FAILURE;
    }

    if (!is_file($sourcePath)) {
        $this->error("No existe el archivo: {$sourcePath}");

        return SymfonyCommand::FAILURE;
    }

    $kernel = app(HttpKernel::class);
    $session = app('session')->driver();
    $session->start();
    $session->put([
        AuthSessionKeys::LOGGED_IN => true,
        AuthSessionKeys::USER_ID => 37,
        AuthSessionKeys::USER_EMAIL => 'test@smartdata.com.pe',
        AuthSessionKeys::USER_NAME => 'Usuario Test',
        AuthSessionKeys::JWT_TOKEN => null,
        AuthSessionKeys::USER_ROLE => 'admin',
    ]);
    $session->regenerateToken();
    $session->save();

    $sendRequest = function (string $method, string $uri, array $parameters = [], array $files = []) use ($kernel, $session) {
        $parameters['_token'] = $session->token();

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $session->token(),
        ];

        $request = Request::create(
            $uri,
            strtoupper($method),
            $parameters,
            [$session->getName() => $session->getId()],
            $files,
            $server
        );

        $request->setLaravelSession($session);

        $response = $kernel->handle($request);
        $content = $response->getContent();
        $json = json_decode($content, true);

        return [
            'status' => $response->getStatusCode(),
            'json' => is_array($json) ? $json : null,
            'raw' => $content,
        ];
    };

    $assertOk = function (string $label, array $response) {
        if (!in_array($response['status'], [200, 201, 202], true)) {
            $body = $response['raw'] ?: json_encode($response['json']);
            throw new RuntimeException("{$label} fallo con status {$response['status']}: {$body}");
        }
    };

    $filename = basename($sourcePath);
    $mimeType = File::mimeType($sourcePath) ?: 'application/octet-stream';
    $fileSize = filesize($sourcePath);

    if ($fileSize === false || $fileSize <= 0) {
        $this->error("No se pudo obtener filesize valido para {$sourcePath}");

        return SymfonyCommand::FAILURE;
    }

    $baseRoute = "/backoffice/courses/{$courseId}/sessions/{$sessionId}/video";
    $progressRoute = "/backoffice/sessions/{$sessionId}/video/upload-progress";
    $statusRoute = "/backoffice/courses/{$courseId}/sessions/{$sessionId}/video/status";

    $uploadId = null;
    $fileId = null;
    $chunkDirectory = storage_path('app/video-endpoint-test-chunks');

    try {
        $this->info('1. start-upload');
        $startResponse = $sendRequest('POST', "{$baseRoute}/start-upload", [
            'filename' => $filename,
            'mime_type' => $mimeType,
            'filesize' => $fileSize,
        ]);
        $assertOk('start-upload', $startResponse);

        $uploadId = (int) ($startResponse['json']['upload_id'] ?? 0);
        if ($uploadId <= 0) {
            throw new RuntimeException('start-upload no devolvio upload_id valido');
        }

        $this->line("OK start-upload upload_id={$uploadId}");

        $this->info('2. upload-progress inicial');
        $initialProgress = $sendRequest('GET', $progressRoute, []);
        $assertOk('upload-progress inicial', $initialProgress);
        $this->line('Progress inicial: ' . json_encode($initialProgress['json'], JSON_UNESCAPED_UNICODE));

        $this->info('3. upload-chunk por endpoints');
        File::ensureDirectoryExists($chunkDirectory);
        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo {$sourcePath}");
        }

        $totalChunks = (int) ceil($fileSize / $chunkSize);

        try {
            for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
                $chunkData = fread($handle, $chunkSize);

                if ($chunkData === false || $chunkData === '') {
                    throw new RuntimeException("No se pudo leer el chunk {$chunkIndex}");
                }

                $chunkPath = $chunkDirectory . DIRECTORY_SEPARATOR . sprintf(
                    'endpoint-chunk-%03d.part',
                    $chunkIndex
                );

                File::put($chunkPath, $chunkData);

                $chunkFile = new UploadedFile(
                    $chunkPath,
                    $filename . '.part',
                    'application/octet-stream',
                    null,
                    true
                );

                $chunkResponse = $sendRequest('POST', "{$baseRoute}/upload-chunk", [
                    'upload_id' => $uploadId,
                    'chunk_index' => $chunkIndex,
                    'total_chunks' => $totalChunks,
                ], [
                    'chunk' => $chunkFile,
                ]);

                File::delete($chunkPath);

                $assertOk("upload-chunk {$chunkIndex}", $chunkResponse);

                $bytesUploaded = (int) ($chunkResponse['json']['bytes_uploaded'] ?? 0);
                $fileId = $chunkResponse['json']['file_id'] ?? $fileId;
                $percent = min(100, (int) floor(($bytesUploaded / $fileSize) * 100));

                $this->output->write(sprintf(
                    "\rSubiendo chunk %d/%d... %d%% (%s/%s bytes)",
                    $chunkIndex + 1,
                    $totalChunks,
                    $percent,
                    number_format($bytesUploaded),
                    number_format($fileSize)
                ));
            }

            $this->line('');
        } finally {
            fclose($handle);

            if (File::exists($chunkDirectory)) {
                File::deleteDirectory($chunkDirectory);
            }
        }

        if (!$fileId) {
            throw new RuntimeException('La subida por endpoints no devolvio file_id');
        }

        $this->line("OK upload-chunk file_id={$fileId}");

        $this->info('4. finalize-upload');
        $finalizeResponse = $sendRequest('POST', "{$baseRoute}/finalize-upload", [
            'upload_id' => $uploadId,
            'filesize' => $fileSize,
            'file_id' => $fileId,
        ]);
        $assertOk('finalize-upload', $finalizeResponse);
        $this->line('OK finalize-upload ' . json_encode($finalizeResponse['json'], JSON_UNESCAPED_UNICODE));

        $this->info('5. status');
        $lastStatus = null;
        for ($attempt = 1; $attempt <= $pollAttempts; $attempt++) {
            $statusResponse = $sendRequest('GET', $statusRoute, []);
            $assertOk("status intento {$attempt}", $statusResponse);

            $lastStatus = $statusResponse['json']['status'] ?? 'unknown';
            $returnedFileId = $statusResponse['json']['file_id'] ?? null;
            if ($returnedFileId) {
                $fileId = $returnedFileId;
            }

            $this->line(sprintf(
                'Status intento %d/%d: %s%s',
                $attempt,
                $pollAttempts,
                $lastStatus,
                $fileId ? " file_id={$fileId}" : ''
            ));

            if ($lastStatus === 'ready') {
                break;
            }

            if ($attempt < $pollAttempts) {
                sleep($sleepSeconds);
            }
        }

        $this->line('');
        $this->info('Prueba final de endpoints completada');
        $this->line("upload_id={$uploadId}");
        $this->line("file_id={$fileId}");
        $this->line("status_final={$lastStatus}");
        $this->line("view_url=https://drive.google.com/file/d/{$fileId}/view");

        return SymfonyCommand::SUCCESS;
    } catch (Throwable $e) {
        $this->error('FAIL ' . $e->getMessage());

        return SymfonyCommand::FAILURE;
    }
})->purpose('Prueba final de endpoints de video subiendo un archivo real por chunks y devolviendo el file_id');
