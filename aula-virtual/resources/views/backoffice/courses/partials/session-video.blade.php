@php
    $canWriteVideo = \App\Support\BackofficePermission::allows(session(\App\Support\AuthSessionKeys::USER_ROLE), \App\Support\BackofficePermission::VIDEO_WRITE);
    $videoReady = ($session->video_status ?? '') === 'ready' && !empty($session->video_drive_file_id);
    $videoBusy = in_array($session->video_status ?? '', ['processing', 'uploaded', 'completed', 'uploading', 'created'], true);
    $hasChat = !empty($session->video_chat_drive_file_id);
@endphp
@if(empty($session?->id))
<div class="session-panel"><h2 class="session-panel-title">Video de la sesión</h2><p class="session-empty-panel">Selecciona una sesión para consultar sus recursos.</p></div>
@elseif(!$canWriteVideo)
    @include('backoffice.courses.partials.session-video-readonly', ['showVideoManagementNote' => true])
@else
<section id="videoUploadContainer" class="session-panel video-content-panel"
         data-can-write-video="1" data-course-id="{{ $course->id }}" data-session-id="{{ $session->id }}"
         data-video-status="{{ $session->video_status ?? '' }}" data-csrf="{{ csrf_token() }}">
    <h2 class="session-panel-title">Video de la sesión</h2>
    <p class="session-panel-subtitle">Prepara y consulta los recursos que verán los usuarios autorizados.</p>
    @include('backoffice.courses.partials.session-video-resources')
    <section class="video-section" data-video-management aria-label="Gestión del contenido">
        <h3>Gestión del contenido</h3>
        @if(!$videoReady && !$videoBusy)
            <p>{{ $hasChat ? 'Sube la grabación. El chat de la clase ya está disponible.' : 'Sube la grabación y, si lo deseas, adjunta el chat de la clase.' }}</p>
            <div class="session-panel-actions">
                <button id="uploadVideoBtn" type="button" onclick="document.getElementById('videoInput').click()" class="btn-primary">Subir video</button>
                <button id="cancelUploadBtn" type="button" style="display:none" class="btn-danger">Cancelar subida</button>
            </div>
            <input type="file" id="videoInput" accept="video/*" class="hidden" aria-label="Seleccionar grabación">
            @if(!$hasChat)
                <label class="btn-secondary" for="videoChatInput">Adjuntar chat (.txt)</label>
                <input type="file" id="videoChatInput" accept=".txt,text/plain" class="hidden">
                <div id="videoChatMeta" class="video-file-meta hidden" aria-live="polite"></div>
            @endif
            <div id="videoFileMeta" class="video-file-meta hidden" aria-live="polite"></div>
            <div id="uploadProgress" class="hidden mt-4">
                <div class="upload-progress-track"><div id="progressBar" class="upload-progress-bar">0%</div></div>
            </div>
        @elseif(!$hasChat && $videoReady)
            <p>La grabación está publicada. Puedes completar los recursos con el chat.</p>
            <div class="session-panel-actions">
                <label class="btn-secondary" for="videoChatInput">Agregar chat de Zoom</label>
                <button type="button" id="uploadVideoChatBtn" class="btn-primary hidden">Guardar chat</button>
            </div>
            <input type="file" id="videoChatInput" accept=".txt,text/plain" class="hidden">
            <div id="videoChatMeta" class="video-file-meta hidden" aria-live="polite"></div>
        @else
            <p>{{ $videoBusy ? 'La grabación se está preparando. Puedes salir de esta página.' : 'Estos recursos están publicados para los usuarios autorizados.' }}</p>
        @endif
    </section>
    @if(!$videoBusy && ($videoReady || $hasChat))
        <section class="video-section video-maintenance" data-video-maintenance aria-label="Acciones de mantenimiento">
            <h3>Acciones de mantenimiento</h3>
            <p>Estas acciones eliminan recursos publicados y pueden afectar a alumnos y docentes.</p>
            <div class="session-panel-actions">
                @if($videoReady)
                    <button type="button" id="deleteVideoBtn" data-session-id="{{ $session->id }}" class="btn-danger">Eliminar video</button>
                @endif
                @if($hasChat)
                    <button type="button" class="btn-danger" data-delete-video-chat data-session-id="{{ $session->id }}">Eliminar chat</button>
                @endif
            </div>
        </section>
    @endif
</section>
@endif
