<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SesionVideoUploadRepository
{
    private const ACTIVE_STATUSES = [
        'uploading',
        'paused',
        'processing'
    ];

    /*
    |--------------------------------------------------------------------------
    | CONSULTAS
    |--------------------------------------------------------------------------
    */

    public function getActiveUploadBySesion(int $sesionId): ?array
    {
        $row = DB::table('curso_edicion_sesion_video_upload')
            ->where('curso_edicion_sesion_id', $sesionId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('curso_edicion_sesion_video_upload_id')
            ->first();

        return $row ? (array) $row : null;
    }

    public function getLatestUploadBySesion(int $sesionId): ?array
    {
        $row = DB::table('curso_edicion_sesion_video_upload')
            ->where('curso_edicion_sesion_id', $sesionId)
            ->orderByDesc('curso_edicion_sesion_video_upload_id')
            ->first();

        return $row ? (array) $row : null;
    }

    public function getUploadById(int $uploadId): ?array
    {
        $row = DB::table('curso_edicion_sesion_video_upload')
            ->where('curso_edicion_sesion_video_upload_id', $uploadId)
            ->first();

        return $row ? (array) $row : null;
    }

    /*
    |--------------------------------------------------------------------------
    | INICIO DE CARGA
    |--------------------------------------------------------------------------
    */

    public function registerUploadStart(int $sesionId, array $data): int
    {
        $now = Carbon::now();

        return DB::table('curso_edicion_sesion_video_upload')->insertGetId([
            'curso_edicion_sesion_id' => $sesionId,
            'upload_url'              => $data['upload_url'] ?? null,
            'filename'                => $data['filename'] ?? null,
            'mime_type'               => $data['mime_type'] ?? null,
            'filesize'                => $data['filesize'] ?? 0,
            'bytes_uploaded'          => 0,
            'drive_file_id'           => null,
            'status'                  => 'uploading',
            'error_message'           => null,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PROGRESO
    |--------------------------------------------------------------------------
    */

    public function updateUploadProgress(int $uploadId, int $bytesUploaded, string $status = 'uploading'): void
    {
        DB::table('curso_edicion_sesion_video_upload')
            ->where('curso_edicion_sesion_video_upload_id', $uploadId)
            ->update([
                'bytes_uploaded' => $bytesUploaded,
                'status'         => $status,
                'updated_at'     => Carbon::now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FINALIZACIÓN
    |--------------------------------------------------------------------------
    */

    public function finalizeUpload(int $uploadId, string $driveFileId, int $filesize): void
    {
        $now = Carbon::now();

        $upload = $this->getUploadById($uploadId);

        if (!$upload) {
            return;
        }

        DB::table('curso_edicion_sesion_video_upload')
            ->where('curso_edicion_sesion_video_upload_id', $uploadId)
            ->update([
                'drive_file_id'   => $driveFileId,
                'bytes_uploaded' => $filesize,
                'status'         => 'completed',
                'updated_at'     => $now,
            ]);

        DB::table('curso_edicion_sesiones')
            ->where('id', $upload['curso_edicion_sesion_id'])
            ->update([
                'video_drive_file_id' => $driveFileId,
                'video_status'        => 'processing',
                'video_uploaded_at'   => $now,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ESTADO DEL VIDEO
    |--------------------------------------------------------------------------
    */

    public function updateVideoStatus(int $sesionId, string $status): void
    {
        DB::table('curso_edicion_sesiones')
            ->where('id', $sesionId)
            ->update([
                'video_status' => $status,
                'updated_at'   => Carbon::now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ERROR / CANCELACIÓN
    |--------------------------------------------------------------------------
    */

    public function markUploadError(int $uploadId, string $message): void
    {
        DB::table('curso_edicion_sesion_video_upload')
            ->where('curso_edicion_sesion_video_upload_id', $uploadId)
            ->update([
                'status'        => 'error',
                'error_message' => $message,
                'updated_at'    => Carbon::now(),
            ]);
    }

    public function cancelUpload(int $uploadId): void
    {
        DB::table('curso_edicion_sesion_video_upload')
            ->where('curso_edicion_sesion_video_upload_id', $uploadId)
            ->update([
                'status'     => 'cancelled',
                'updated_at' => Carbon::now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ELIMINAR VIDEO
    |--------------------------------------------------------------------------
    */

    public function deleteVideoRecord(int $sesionId): void
    {
        $now = Carbon::now();

        DB::table('curso_edicion_sesiones')
            ->where('id', $sesionId)
            ->update([
                'video_drive_file_id' => null,
                'video_status'        => null,
                'video_uploaded_at'   => null,
                'video_chat_drive_file_id' => null,
                'video_chat_titulo' => null,
                'video_chat_filesize' => null,
                'video_chat_uploaded_at' => null,
                'updated_at'          => $now,
            ]);

        DB::table('curso_edicion_sesion_video_upload')
            ->where('curso_edicion_sesion_id', $sesionId)
            ->whereIn('status', [
                'uploading',
                'paused',
                'processing',
                'completed'
            ])
            ->update([
                'status'     => 'deleted',
                'updated_at' => $now,
            ]);
    }

    public function getVideoStatus(int $sesionId): array
    {
        $row = DB::table('curso_edicion_sesiones')
            ->select([
                'video_status',
                'video_drive_file_id',
                'video_chat_drive_file_id',
                'video_chat_titulo',
                'video_chat_filesize',
                'video_chat_uploaded_at'
            ])
            ->where('id', $sesionId)
            ->first();

        if (!$row) {
            throw new \RuntimeException('Sesión no encontrada');
        }

        if (empty($row->video_drive_file_id)) {
            return [
                'status'  => 'none',
                'file_id' => null,
                'chat' => $this->mapVideoChat($row),
            ];
        }

        return [
            'status'  => $row->video_status ?: 'uploaded',
            'file_id' => $row->video_drive_file_id,
            'chat' => $this->mapVideoChat($row),
        ];
    }

    public function updateVideoChat(int $sesionId, array $data): void
    {
        DB::table('curso_edicion_sesiones')
            ->where('id', $sesionId)
            ->update([
                'video_chat_drive_file_id' => $data['drive_file_id'] ?? null,
                'video_chat_titulo' => $data['titulo'] ?? null,
                'video_chat_filesize' => $data['filesize'] ?? null,
                'video_chat_uploaded_at' => $data['uploaded_at'] ?? Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    public function clearVideoChat(int $sesionId): void
    {
        DB::table('curso_edicion_sesiones')
            ->where('id', $sesionId)
            ->update([
                'video_chat_drive_file_id' => null,
                'video_chat_titulo' => null,
                'video_chat_filesize' => null,
                'video_chat_uploaded_at' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    private function mapVideoChat(object $row): ?array
    {
        if (empty($row->video_chat_drive_file_id)) {
            return null;
        }

        return [
            'file_id' => $row->video_chat_drive_file_id,
            'title' => $row->video_chat_titulo,
            'filesize' => isset($row->video_chat_filesize) ? (int) $row->video_chat_filesize : null,
            'uploaded_at' => $row->video_chat_uploaded_at,
        ];
    }

    public function cancelActiveUploadBySesion(int $sesionId): void
{
    DB::table('curso_edicion_sesion_video_upload')
        ->where('curso_edicion_sesion_id', $sesionId)
        ->whereIn('status', ['uploading', 'paused', 'processing'])
        ->update([
            'status' => 'cancelled',
            'updated_at' => Carbon::now(),
        ]);
}

}
