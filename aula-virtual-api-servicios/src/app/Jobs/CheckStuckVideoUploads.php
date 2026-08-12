<?php

namespace App\Jobs;

use App\Repositories\SesionVideoUploadRepository;
use App\Repositories\SesionRepository;
use App\Helpers\GoogleDriveHelper;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckStuckVideoUploads
{
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

    public function handle()
    {
        $uploads = $this->uploadRepo->getUploadingOlderThanMinutes(30);

        foreach ($uploads as $upload) {

            if (!$upload->upload_url) {
                continue;
            }

            /**
             * Intentamos obtener el fileId desde Drive
             * usando el upload URL (location header contiene el ID)
             */
            $parts = explode('/', $upload->upload_url);
            $fileId = end($parts);

            if (!$fileId) {
                continue;
            }

            $file = $this->driveHelper->getFile($fileId);

            if (!$file) {
                continue;
            }

            /**
             * Si el archivo existe en Drive
             * finalizamos automáticamente
             */
            $this->uploadRepo->finalizeUpload(
                $upload->curso_edicion_sesion_video_upload_id,
                [
                    'status' => 'completed',
                    'bytes_uploaded' => $file['size'] ?? $upload->filesize
                ]
            );

            $this->sesionRepo->updateVideoStatus(
                $upload->curso_edicion_sesion_id,
                [
                    'video_drive_file_id' => $fileId,
                    'video_status' => 'ready',
                    'video_uploaded_at' => Carbon::now(),
                    'video_filesize' => $file['size'] ?? $upload->filesize
                ]
            );

            Log::info('video_upload_auto_completed', [
                'sesion_id' => $upload->curso_edicion_sesion_id,
                'file_id' => $fileId
            ]);
        }
    }
}