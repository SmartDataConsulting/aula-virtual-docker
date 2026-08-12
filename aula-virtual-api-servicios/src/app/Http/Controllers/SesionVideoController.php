<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\SesionVideoService;

class SesionVideoController extends Controller
{
    protected SesionVideoService $service;

    public function __construct(SesionVideoService $service)
    {
        $this->service = $service;
    }

    public function uploadStarted(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id inválido'], 400);
        }

        $validator = app('validator')->make($request->all(), [
            'upload_url' => 'required|string',
            'filename'   => 'required|string|max:255',
            'mime_type'  => 'required|string|max:100',
            'filesize'   => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json(
                $this->service->registerUploadStart($sesionId, $validator->validated())
            );
        } catch (\Throwable $e) {
            Log::error('api_video_upload_started_error', [
                'sesion_id' => $sesionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error registrando inicio de carga'], 500);
        }
    }

    public function uploadProgress(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id inválido'], 400);
        }

        $validator = app('validator')->make($request->all(), [
            'upload_id'      => 'required|integer|min:1',
            'bytes_uploaded' => 'required|integer|min:0',
            'status'         => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json(
                $this->service->updateUploadProgress($sesionId, $validator->validated())
            );
        } catch (\Throwable $e) {
            Log::error('api_video_upload_progress_error', [
                'sesion_id' => $sesionId,
                'upload_id' => $request->input('upload_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error actualizando progreso'], 500);
        }
    }

    public function uploadCompleted(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id inválido'], 400);
        }

        $validator = app('validator')->make($request->all(), [
            'upload_id'      => 'required|integer|min:1',
            'drive_file_id'  => 'required|string|max:255',
            'filesize'       => 'required|integer|min:1',
            'bytes_uploaded' => 'nullable|integer|min:1',
            'status'         => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json(
                $this->service->finalizeUpload($sesionId, $validator->validated())
            );
        } catch (\Throwable $e) {
            Log::error('api_video_upload_completed_error', [
                'sesion_id' => $sesionId,
                'upload_id' => $request->input('upload_id'),
                'drive_file_id' => $request->input('drive_file_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error finalizando carga'], 500);
        }
    }

    public function uploadError(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id inválido'], 400);
        }

        $validator = app('validator')->make($request->all(), [
            'upload_id'     => 'required|integer|min:1',
            'error_message' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json(
                $this->service->markUploadError($sesionId, $validator->validated())
            );
        } catch (\Throwable $e) {
            Log::error('api_video_upload_error_register_failed', [
                'sesion_id' => $sesionId,
                'upload_id' => $request->input('upload_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error registrando error de carga'], 500);
        }
    }

    public function uploadCancelled(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id inválido'], 400);
        }

        $validator = app('validator')->make($request->all(), [
            'upload_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json(
                $this->service->cancelUpload($sesionId, $validator->validated())
            );
        } catch (\Throwable $e) {
            Log::error('api_video_upload_cancelled_error', [
                'sesion_id' => $sesionId,
                'upload_id' => $request->input('upload_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error cancelando carga'], 500);
        }
    }

    public function statusUpdated(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id inválido'], 400);
        }

        $validator = app('validator')->make($request->all(), [
            'drive_file_id' => 'nullable|string|max:255',
            'video_status'  => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json(
                $this->service->updateVideoStatus($sesionId, $validator->validated())
            );
        } catch (\Throwable $e) {
            Log::error('api_video_status_updated_error', [
                'sesion_id' => $sesionId,
                'status' => $request->input('video_status'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error actualizando estado del video'], 500);
        }
    }

    public function deleted(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id inválido'], 400);
        }

        try {
            return response()->json(
                $this->service->deleteVideoRecord($sesionId)
            );
        } catch (\Throwable $e) {
            Log::error('api_video_deleted_error', [
                'sesion_id' => $sesionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error eliminando registro de video'], 500);
        }
    }

    public function chatUploaded(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id invalido'], 400);
        }

        $validator = app('validator')->make($request->all(), [
            'drive_file_id' => 'required|string|max:255',
            'titulo' => 'required|string|max:255',
            'filesize' => 'required|integer|min:1|max:5242880',
            'uploaded_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            return response()->json(
                $this->service->registerVideoChat($sesionId, $validator->validated())
            );
        } catch (\Throwable $e) {
            Log::error('api_video_chat_uploaded_error', [
                'sesion_id' => $sesionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error registrando chat de video'], 500);
        }
    }

    public function chatDeleted(Request $request, $sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id invalido'], 400);
        }

        try {
            return response()->json(
                $this->service->deleteVideoChat($sesionId)
            );
        } catch (\Throwable $e) {
            Log::error('api_video_chat_deleted_error', [
                'sesion_id' => $sesionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error eliminando chat de video'], 500);
        }
    }

    public function getUploadProgress($sesionId)
    {
        $sesionId = (int) $sesionId;

        if ($sesionId <= 0) {
            return response()->json(['error' => 'curso_edicion_sesion_id inválido'], 400);
        }

        try {
            $progress = $this->service->getUploadProgress($sesionId);

            if (!$progress) {
                return response()->json([
                    'status' => 'none',
                ]);
            }

            return response()->json($progress);
        } catch (\Throwable $e) {
            Log::error('api_video_get_upload_progress_error', [
                'sesion_id' => $sesionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error consultando progreso'], 500);
        }
    }

    public function status($sesionId)
{
    $sesionId = (int) $sesionId;

    if ($sesionId <= 0) {
        return response()->json([
            'error' => 'curso_edicion_sesion_id inválido'
        ], 400);
    }

    try {

        return response()->json(
            $this->service->getVideoStatus($sesionId)
        );

    } catch (\Throwable $e) {

        Log::error('api_video_status_error', [
            'sesion_id' => $sesionId,
            'error'     => $e->getMessage(),
        ]);

        return response()->json([
            'error' => 'Error consultando estado del video'
        ], 500);
    }
}
}
