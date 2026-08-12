@if(empty($session?->id))
<div class="session-panel">
    <div class="session-panel-title">Video de la sesión</div>
    <div class="session-empty-panel">Selecciona una sesión para gestionar su video.</div>
</div>
@elseif(($session->video_status ?? null) === 'ready' && !empty($session->video_drive_file_id))
<div id="videoUploadContainer"
     data-course-id="{{ $course->id }}"
     data-session-id="{{ $session->id }}"
     data-video-status="{{ $session->video_status }}"
     data-csrf="{{ csrf_token() }}">
    <div class="session-panel">
        <div class="session-panel-header">
            <div>
                <div class="session-panel-title">Video de la sesión</div>
                <div class="session-panel-subtitle">Grabación disponible para los alumnos.</div>
            </div>
            <button id="deleteVideoBtn"
                    data-session-id="{{ $session->id }}"
                    class="btn-danger btn-danger-strong">
                <span aria-hidden="true">🗑</span>
                Eliminar video
            </button>
        </div>

        <div class="video-ready-card">
            <span class="video-ready-media-icon" aria-hidden="true">🎥</span>
            <div class="video-ready-body">
                <div class="video-ready-title">Grabación de la sesión disponible</div>
                <div class="video-ready-copy">El video se abrirá en Google Drive.</div>
            </div>

            <a href="https://drive.google.com/file/d/{{ $session->video_drive_file_id }}/view"
               target="_blank"
               rel="noopener noreferrer"
               class="btn-primary">
                <span aria-hidden="true">▶</span>
                Ver grabación
            </a>
        </div>

        @if(!empty($session->video_chat_drive_file_id))
            <div class="video-ready-card mt-4">
                <span class="video-ready-media-icon video-ready-media-icon--txt" aria-hidden="true">TXT</span>
                <div class="video-ready-body">
                    <div class="video-ready-title">Chat de Zoom adjunto</div>
                    <div class="video-ready-copy">
                        {{ $session->video_chat_titulo ?? 'chat-de-zoom.txt' }}
                        @if(!empty($session->video_chat_filesize))
                            · {{ number_format(((int) $session->video_chat_filesize) / 1024, 1) }} KB
                        @endif
                    </div>
                </div>
                <div class="session-panel-actions">
                    <button type="button" class="btn-secondary" data-preview-video-chat data-session-id="{{ $session->id }}">
                        Ver chat
                    </button>
                    <a class="btn-secondary" href="{{ route('sessions.video.chat.download', ['session' => $session->id]) }}">
                        Descargar TXT
                    </a>
                    <button type="button" class="btn-danger" data-delete-video-chat data-session-id="{{ $session->id }}">
                        Eliminar chat
                    </button>
                </div>
            </div>
        @else
            <div class="session-info-panel mt-4">
                <div class="session-panel-subtitle mb-3">No se adjuntó chat de Zoom para esta grabación.</div>
                <div class="session-panel-actions justify-start">
                    <label class="btn-secondary" for="videoChatInput">Agregar chat de Zoom</label>
                    <button type="button" id="uploadVideoChatBtn" class="btn-primary hidden">Guardar chat</button>
                </div>
                <input type="file" id="videoChatInput" accept=".txt,text/plain" class="hidden">
                <div id="videoChatMeta" class="video-file-meta hidden mt-3" aria-live="polite"></div>
            </div>
        @endif
    </div>
</div>
@elseif(($session->video_status ?? null) === 'processing')
<div class="session-panel">
    <div id="videoUploadContainer"
         data-course-id="{{ $course->id }}"
         data-session-id="{{ $session->id }}"
         data-video-status="{{ $session->video_status }}"
         data-csrf="{{ csrf_token() }}">
    </div>

    <div class="session-panel-title">Video de la sesión</div>
    <div id="videoStatus" class="session-info-panel">
        Video subido correctamente. Estamos preparando la reproducción; puedes salir de esta página y volver en unos minutos.
    </div>
</div>
@else
<div class="session-panel">
    <div id="videoUploadContainer"
         data-course-id="{{ $course->id }}"
         data-session-id="{{ $session->id }}"
         data-video-status="{{ $session->video_status ?? '' }}"
         data-csrf="{{ csrf_token() }}">
    </div>

    <div class="session-panel-header">
        <div>
            <div class="session-panel-title">Video de la sesión</div>
            <div class="session-panel-subtitle">Sube la grabación cuando termine la clase.</div>
        </div>

        <div class="session-panel-actions">
            <button id="uploadVideoBtn"
                    type="button"
                    onclick="document.getElementById('videoInput').click()"
                    class="btn-primary">
                Subir video
            </button>

            <button type="button"
                    id="cancelUploadBtn"
                    style="display:none"
                    class="btn-danger">
                Cancelar
            </button>
        </div>
    </div>

    <input type="file" id="videoInput" accept="video/*" class="hidden">

    <div class="session-info-panel mb-4">
        <div class="session-panel-subtitle mb-3">Opcional: adjunta el chat exportado de Zoom junto a la grabación.</div>
        <label class="btn-secondary" for="videoChatInput">Seleccionar Chat de Zoom (.txt)</label>
        <input type="file" id="videoChatInput" accept=".txt,text/plain" class="hidden">
        <div id="videoChatMeta" class="video-file-meta hidden mt-3" aria-live="polite"></div>
    </div>

    <div id="videoFileMeta" class="video-file-meta hidden" aria-live="polite"></div>

    <div id="videoStatus" class="session-empty-panel">
        Aún no hay video cargado para esta sesión.
    </div>

    <div id="uploadProgress" class="hidden mt-4">
        <div class="upload-progress-track">
            <div id="progressBar" class="upload-progress-bar">0%</div>
        </div>
    </div>
</div>
@endif
