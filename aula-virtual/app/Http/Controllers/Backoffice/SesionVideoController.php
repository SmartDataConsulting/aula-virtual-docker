<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\SesionService;
use App\Services\VideoService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SesionVideoController extends Controller
{
    private VideoService $service;

    public function __construct(VideoService $service, private readonly SesionService $sesionService)
    {
        $this->service = $service;
    }

    /**
     * Inicia la subida: solo metadata
     * POST /backoffice/courses/{courseId}/sessions/{sessionId}/video/start-upload
     */
    public function startUpload(Request $request, $courseId, $sessionId)
    {
        $validated = $request->validate([
            'filename' => 'required|string|max:255',
            'mime_type' => 'required|string|max:100',
            'filesize' => 'required|integer|min:1',
        ]);

        try {
            return $this->jsonFromService(
                $this->service->startUpload((int) $sessionId, $validated)
            );
        } catch (\Throwable $e) {
            $missingDriveFolder = str_contains($e->getMessage(), 'GOOGLE_DRIVE_LMS_FOLDER_ID');

            Log::error('UPLOAD_START_ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $missingDriveFolder ? 'Configuracion de Google Drive incompleta' : 'Error iniciando subida',
                'message' => $missingDriveFolder
                    ? 'Falta configurar GOOGLE_DRIVE_LMS_FOLDER_ID en el archivo .env del portal.'
                    : $e->getMessage(),
                'code' => $missingDriveFolder ? 'google_drive_folder_missing' : 'upload_start_failed',
            ], $missingDriveFolder ? 422 : 500);
        }
    }

    /**
     * Subir chunk
     * POST /backoffice/courses/{courseId}/sessions/{sessionId}/video/upload-chunk
     */
    public function uploadChunk(Request $request, $courseId, $sessionId)
    {
        $validated = $request->validate([
            'chunk' => 'required|file',
            'upload_id' => 'required|integer|min:1',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
            'start_byte' => 'required|integer|min:0',
            'end_byte' => 'required|integer|min:0',

            // NUEVO: metadata del archivo completo
            'filename' => 'required|string|max:255',
            'mime_type' => 'required|string|max:100',
            'filesize' => 'required|integer|min:1',
        ]);

        try {
            return $this->jsonFromService(
                $this->service->uploadChunk(
                    (int) $sessionId,
                    $request->file('chunk'),
                    (int) $validated['upload_id'],
                    (int) $validated['chunk_index'],
                    (int) $validated['total_chunks'],
                    [
                        'filename' => $validated['filename'],
                        'mime_type' => $validated['mime_type'],
                        'filesize' => (int) $validated['filesize'],
                        'start_byte' => (int) $validated['start_byte'],
                        'end_byte' => (int) $validated['end_byte'],
                    ]
                )
            );
        } catch (\Throwable $e) {
            Log::error('UPLOAD_CHUNK_ERROR', [
                'session_id' => $sessionId,
                'upload_id' => $validated['upload_id'] ?? null,
                'chunk_index' => $validated['chunk_index'] ?? null,
                'filename' => $validated['filename'] ?? null,
                'filesize' => $validated['filesize'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Error subiendo chunk',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Finaliza subida
     * POST /backoffice/courses/{courseId}/sessions/{sessionId}/video/finalize-upload
     */
    public function finalizeUpload(Request $request, $courseId, $sessionId)
    {
        $validated = $request->validate([
            'upload_id' => 'required|integer|min:1',
            'filesize' => 'required|integer|min:1',
            'file_id' => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->service->finalizeUpload(
                (int) $sessionId,
                !empty($validated['file_id']) ? trim((string) $validated['file_id']) : null,
                (int) $validated['upload_id'],
                (int) $validated['filesize']
            );
            $this->forgetSessionCache($request, (int) $courseId, $result->ok());

            return $this->jsonFromService($result);
        } catch (\Throwable $e) {
            Log::error('UPLOAD_FINALIZE_ERROR', [
                'session_id' => $sessionId,
                'upload_id' => $validated['upload_id'] ?? null,
                'file_id' => $validated['file_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Error finalizando subida',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancela una subida en curso y marca el estado como cancelado
     * POST /backoffice/courses/{courseId}/sessions/{sessionId}/video/cancel-upload
     */
    public function cancelUpload(Request $request, $courseId, $sessionId)
    {
        $validated = $request->validate([
            'upload_id' => 'required|integer|min:1',
        ]);

        try {
            return $this->jsonFromService(
                $this->service->cancelUpload((int) $sessionId, (int) $validated['upload_id'])
            );
        } catch (\Throwable $e) {
            Log::error('CANCEL-UPLOAD-ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Error cancelando subida',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteVideo(Request $request, $courseId, $sessionId)
    {
        try {
            $result = $this->service->deleteVideo((int) $sessionId);
            $this->forgetSessionCache($request, (int) $courseId, $result->ok());

            return $this->jsonFromService($result);
        } catch (\Throwable $e) {
            Log::error('VIDEO_DELETE_ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Error eliminando video',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadChat(Request $request, $courseId, $sessionId)
    {
        $request->validate([
            'chat' => 'required|file|max:5120',
        ]);

        try {
            $result = $this->service->uploadChatTranscript((int) $sessionId, $request->file('chat'));
            $this->forgetSessionCache($request, (int) $courseId, $result->ok());

            return $this->jsonFromService($result);
        } catch (\Throwable $e) {
            Log::error('VIDEO_CHAT_UPLOAD_ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Error guardando chat de Zoom',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteChat(Request $request, $courseId, $sessionId)
    {
        try {
            $result = $this->service->deleteChatTranscript((int) $sessionId);
            $this->forgetSessionCache($request, (int) $courseId, $result->ok());

            return $this->jsonFromService($result);
        } catch (\Throwable $e) {
            Log::error('VIDEO_CHAT_DELETE_ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Error eliminando chat de Zoom',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function previewChat($sessionId)
    {
        try {
            return $this->jsonFromService(
                $this->service->getChatTranscript((int) $sessionId)
            );
        } catch (\Throwable $e) {
            Log::error('VIDEO_CHAT_PREVIEW_ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'No se pudo cargar el chat de Zoom',
            ], 500);
        }
    }

    public function downloadChat($sessionId)
    {
        try {
            $result = $this->service->getChatTranscript((int) $sessionId);

            if (!$result->ok()) {
                return $this->jsonFromService($result);
            }

            $data = $result->data();
            $filename = (string) ($data['filename'] ?? 'chat-de-zoom.txt');

            return response($data['content'] ?? '', 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $e) {
            Log::error('VIDEO_CHAT_DOWNLOAD_ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'No se pudo descargar el chat de Zoom',
            ], 500);
        }
    }

    public function status($courseId, $sessionId)
    {
        try {
            return $this->jsonFromService(
                $this->service->getVideoStatus((int) $sessionId)
            );
        } catch (\Throwable $e) {
            Log::error('VIDEO_STATUS_ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'unknown',
                'file_id' => null
            ], 500);
        }
    }

    /**
     * Obtiene el progreso de una subida en curso
     * GET /backoffice/courses/{courseId}/sessions/{sessionId}/video/upload-progress
     */
    public function uploadProgress($sessionId)
    {
        try {
            return $this->jsonFromService(
                $this->service->getUploadProgress((int) $sessionId)
            );
        } catch (\Throwable $e) {
            Log::error('VIDEO_UPLOAD_PROGRESS_ERROR', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'error' => 'Error consultando el progreso de la subida',
                'message' => $e->getMessage(),
                'code' => 'upload_progress_unavailable',
            ], 500);
        }
    }

    private function jsonFromService($result)
    {
        return response()->json($result->ok() ? $result->data() : $result->error(), $result->status());
    }

    private function forgetSessionCache(Request $request, int $courseId, bool $successful): void
    {
        $role = (string) $request->session()->get(AuthSessionKeys::USER_ROLE, '');

        if ($successful && $courseId > 0 && $role !== '') {
            $this->sesionService->forgetCourseSessions($courseId, $role);
        }
    }
}
