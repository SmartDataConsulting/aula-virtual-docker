<?php

namespace App\Services;

use App\Repositories\SesionVideoUploadRepository;
use App\Repositories\SesionRepository;
use App\Helpers\GoogleDriveHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SesionVideoService
{
    private const STATUS_UPLOADING  = 'uploading';
    private const STATUS_COMPLETED  = 'completed';
    private const STATUS_CANCELLED  = 'cancelled';
    private const STATUS_ERROR      = 'error';
    private const STATUS_DELETED    = 'deleted';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_READY      = 'ready';

    protected SesionVideoUploadRepository $uploadRepo;
    protected SesionRepository $sesionRepo;
    protected GoogleDriveHelper $driveHelper;

    public function __construct(
        SesionVideoUploadRepository $uploadRepo,
        SesionRepository $sesionRepo,
        GoogleDriveHelper $driveHelper
    ) {
        $this->uploadRepo = $uploadRepo;
        $this->sesionRepo = $sesionRepo;
        $this->driveHelper = $driveHelper;
    }

    public function registerUploadStart(int $sesionId, array $data): array
    {
        $sesion = $this->sesionRepo->obtenerPorId($sesionId);

        if (!$sesion) {
            throw new \RuntimeException('Sesión no encontrada');
        }

        foreach (['upload_url', 'filename', 'mime_type', 'filesize'] as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("Campo requerido faltante: {$field}");
            }
        }

        $this->uploadRepo->cancelActiveUploadBySesion($sesionId);

        $uploadId = $this->uploadRepo->registerUploadStart($sesionId, [
            'upload_url' => $data['upload_url'],
            'filename'   => $data['filename'],
            'mime_type'  => $data['mime_type'],
            'filesize'   => (int) $data['filesize'],
        ]);

        Log::info('video_upload_started_registered', [
            'sesion_id' => $sesionId,
            'upload_id' => $uploadId,
            'filename'  => $data['filename'],
            'filesize'  => $data['filesize'],
        ]);

        return [
            'upload_id'      => $uploadId,
            'bytes_uploaded' => 0,
            'status'         => self::STATUS_UPLOADING,
            'file_id'        => null,
        ];
    }

    public function updateUploadProgress(int $sesionId, array $data): array
    {
        $uploadId = (int) ($data['upload_id'] ?? 0);

        if ($uploadId <= 0) {
            throw new \RuntimeException('upload_id inválido');
        }

        $upload = $this->uploadRepo->getUploadById($uploadId);

        if (!$upload || (int) $upload['curso_edicion_sesion_id'] !== $sesionId) {
            throw new \RuntimeException('La subida no pertenece a la sesión indicada');
        }

        $bytesUploaded = (int) ($data['bytes_uploaded'] ?? 0);
        $status = $data['status'] ?? self::STATUS_UPLOADING;

        $this->uploadRepo->updateUploadProgress($uploadId, $bytesUploaded, $status);

        return [
            'upload_id'      => $uploadId,
            'bytes_uploaded' => $bytesUploaded,
            'status'         => $status,
        ];
    }

    public function finalizeUpload(int $sesionId, array $data): array
    {
        $uploadId = (int) ($data['upload_id'] ?? 0);
        $driveFileId = trim((string) ($data['drive_file_id'] ?? ''));
        $filesize = (int) ($data['filesize'] ?? $data['bytes_uploaded'] ?? 0);

        if ($uploadId <= 0) {
            throw new \RuntimeException('upload_id inválido');
        }

        if ($driveFileId === '') {
            throw new \RuntimeException('drive_file_id requerido');
        }

        if ($filesize <= 0) {
            throw new \RuntimeException('filesize inválido');
        }

        $upload = $this->uploadRepo->getUploadById($uploadId);

        if (!$upload || (int) $upload['curso_edicion_sesion_id'] !== $sesionId) {
            throw new \RuntimeException('La subida no pertenece a la sesión indicada');
        }

        $this->uploadRepo->finalizeUpload($uploadId, $driveFileId, $filesize);

        Log::info('video_upload_completed_registered', [
            'sesion_id'     => $sesionId,
            'upload_id'     => $uploadId,
            'drive_file_id' => $driveFileId,
            'filesize'      => $filesize,
        ]);

        return [
            'upload_id' => $uploadId,
            'file_id'   => $driveFileId,
            'status'    => self::STATUS_PROCESSING,
        ];
    }

    public function markUploadError(int $sesionId, array $data): array
    {
        $uploadId = (int) ($data['upload_id'] ?? 0);
        $message = $data['error_message'] ?? 'Error no especificado';

        if ($uploadId <= 0) {
            throw new \RuntimeException('upload_id inválido');
        }

        $upload = $this->uploadRepo->getUploadById($uploadId);

        if (!$upload || (int) $upload['curso_edicion_sesion_id'] !== $sesionId) {
            throw new \RuntimeException('La subida no pertenece a la sesión indicada');
        }

        $this->uploadRepo->markUploadError($uploadId, $message);

        return [
            'upload_id' => $uploadId,
            'status'    => self::STATUS_ERROR,
            'message'   => $message,
        ];
    }

    public function cancelUpload(int $sesionId, array $data): array
    {
        $uploadId = (int) ($data['upload_id'] ?? 0);

        if ($uploadId <= 0) {
            throw new \RuntimeException('upload_id inválido');
        }

        $upload = $this->uploadRepo->getUploadById($uploadId);

        if (!$upload || (int) $upload['curso_edicion_sesion_id'] !== $sesionId) {
            throw new \RuntimeException('La subida no pertenece a la sesión indicada');
        }

        $this->uploadRepo->cancelUpload($uploadId);

        return [
            'upload_id' => $uploadId,
            'status'    => self::STATUS_CANCELLED,
        ];
    }

    public function updateVideoStatus(int $sesionId, array $data): array
    {
        $status = $data['video_status'] ?? null;

        if (!$status) {
            throw new \RuntimeException('video_status requerido');
        }

        $this->uploadRepo->updateVideoStatus($sesionId, $status);

        return [
            'sesion_id' => $sesionId,
            'status'    => $status,
        ];
    }

    public function deleteVideoRecord(int $sesionId): array
    {
        $this->uploadRepo->deleteVideoRecord($sesionId);

        return [
            'sesion_id' => $sesionId,
            'status'    => self::STATUS_DELETED,
        ];
    }

    public function registerVideoChat(int $sesionId, array $data): array
    {
        $sesion = $this->sesionRepo->obtenerPorId($sesionId);

        if (!$sesion) {
            throw new \RuntimeException('Sesion no encontrada');
        }

        $driveFileId = trim((string) ($data['drive_file_id'] ?? ''));
        $title = trim((string) ($data['titulo'] ?? ''));
        $filesize = (int) ($data['filesize'] ?? 0);

        if ($driveFileId === '') {
            throw new \RuntimeException('drive_file_id requerido');
        }

        if ($title === '') {
            throw new \RuntimeException('titulo requerido');
        }

        if ($filesize <= 0) {
            throw new \RuntimeException('filesize invalido');
        }

        $uploadedAt = $this->normalizeDatabaseDate($data['uploaded_at'] ?? null);

        $this->uploadRepo->updateVideoChat($sesionId, [
            'drive_file_id' => $driveFileId,
            'titulo' => $title,
            'filesize' => $filesize,
            'uploaded_at' => $uploadedAt,
        ]);

        return [
            'sesion_id' => $sesionId,
            'status' => 'chat_uploaded',
            'chat' => [
                'file_id' => $driveFileId,
                'title' => $title,
                'filesize' => $filesize,
                'uploaded_at' => $uploadedAt,
            ],
        ];
    }

    private function normalizeDatabaseDate($value): string
    {
        if (empty($value)) {
            return Carbon::now()->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return Carbon::now()->format('Y-m-d H:i:s');
        }
    }

    public function deleteVideoChat(int $sesionId): array
    {
        $this->uploadRepo->clearVideoChat($sesionId);

        return [
            'sesion_id' => $sesionId,
            'status' => 'chat_deleted',
        ];
    }

    public function getUploadProgress(int $sesionId): ?array
    {
        $upload = $this->uploadRepo->getActiveUploadBySesion($sesionId)
            ?? $this->uploadRepo->getLatestUploadBySesion($sesionId);

        if (!$upload) {
            return null;
        }

        return [
            'upload_id'      => $upload['curso_edicion_sesion_video_upload_id'],
            'upload_url'     => $upload['upload_url'] ?? null,
            'bytes_uploaded' => (int) ($upload['bytes_uploaded'] ?? 0),
            'filesize'       => (int) ($upload['filesize'] ?? 0),
            'filename'       => $upload['filename'] ?? null,
            'mime_type'      => $upload['mime_type'] ?? null,
            'file_id'        => $upload['drive_file_id'] ?? null,
            'status'         => $upload['status'] ?? null,
        ];
    }

    public function getVideoStatus(int $sesionId): array
    {
        $current = $this->uploadRepo->getVideoStatus($sesionId);
        $fileId = trim((string) ($current['file_id'] ?? ''));

        if ($fileId === '') {
            return $current;
        }

        $driveStatus = $this->driveHelper->getVideoStatus($fileId);
        $status = $driveStatus['status'] ?? ($current['status'] ?? 'unknown');

        if (($current['status'] ?? null) !== $status) {
            $this->uploadRepo->updateVideoStatus($sesionId, $status);
        }

        return [
            'status' => $status,
            'file_id' => $fileId,
            'chat' => $current['chat'] ?? null,
        ];
    }
}
