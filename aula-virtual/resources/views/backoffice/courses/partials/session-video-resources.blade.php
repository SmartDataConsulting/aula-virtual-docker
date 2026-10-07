@php
    $videoAvailable = ($session->video_status ?? '') === 'ready' && !empty($session->video_drive_file_id);
    $chatAvailable = !empty($session->video_chat_drive_file_id);
    $preparing = in_array($session->video_status ?? '', ['processing', 'uploaded', 'completed'], true);
    $uploading = in_array($session->video_status ?? '', ['uploading', 'created'], true);
    $videoError = in_array($session->video_status ?? '', ['error', 'failed'], true);
@endphp
<section class="video-section" aria-label="Estado del contenido">
    <h3>Estado del contenido</h3>
    <dl class="video-status-grid">
        <div><dt>Grabación</dt><dd class="video-status {{ $videoAvailable ? 'is-available' : ($videoError ? 'is-error' : 'is-pending') }}">{{ $videoAvailable ? 'Disponible' : ($preparing ? 'En preparación' : ($uploading ? 'Subiendo' : ($videoError ? 'Requiere atención' : 'Pendiente'))) }}</dd></div>
        <div><dt>Chat de la clase</dt><dd class="video-status {{ $chatAvailable ? 'is-available' : 'is-pending' }}">{{ $chatAvailable ? 'Disponible' : 'No disponible' }}</dd></div>
    </dl>
    <div id="videoStatus" class="video-status-message" role="status" aria-live="polite">
        @if($videoAvailable)
            La grabación está lista para consultar.
        @elseif($preparing)
            La grabación se está preparando. Puedes salir de esta página; aparecerá cuando esté lista.
        @elseif($uploading && ($canWriteVideo ?? false))
            Estamos recibiendo el archivo. Mantén esta ventana abierta hasta que termine la transferencia.
        @elseif($videoError && ($canWriteVideo ?? false))
            No se pudo completar la grabación. Revisa el archivo y vuelve a intentarlo.
        @else
            Aún no hay grabación disponible para esta sesión. Cuando esté lista, aparecerá en esta sección.
        @endif
    </div>
</section>
<section class="video-section" data-video-resources aria-label="Recursos de la sesión">
    <h3>Recursos de la sesión</h3>
    @if($videoAvailable)
        <article class="video-ready-card" data-video-resource="recording">
            <span class="video-ready-media-icon" aria-hidden="true">▶</span>
            <div class="video-ready-body"><h4 class="video-ready-title">Grabación de la sesión disponible</h4><p class="video-ready-copy">Repasa el contenido cuando lo necesites.</p></div>
            <a href="https://drive.google.com/file/d/{{ $session->video_drive_file_id }}/view" target="_blank" rel="noopener noreferrer" class="btn-primary">Ver grabación<span class="sr-only"> (se abre en otra pestaña)</span></a>
        </article>
    @endif
    @if($chatAvailable)
        <article class="video-ready-card" data-video-resource="chat">
            <span class="video-ready-media-icon video-ready-media-icon--txt" aria-hidden="true">TXT</span>
            <div class="video-ready-body">
                <h4 class="video-ready-title">Chat de la clase</h4>
                <p class="video-ready-copy">
                    {{ $session->video_chat_titulo ?? 'chat-de-zoom.txt' }}
                    @if(!empty($session->video_chat_filesize))
                        · {{ number_format(((int) $session->video_chat_filesize) / 1024, 1) }} KB
                    @endif
                </p>
            </div>
            <div class="session-panel-actions">
                <a class="btn-secondary" href="{{ route('sessions.video.chat.preview', ['session' => $session->id]) }}" data-preview-video-chat data-session-id="{{ $session->id }}">Ver chat</a>
                <a class="btn-secondary" href="{{ route('sessions.video.chat.download', ['session' => $session->id]) }}">Descargar TXT</a>
            </div>
        </article>
    @else
        <p class="video-consultation-note">El chat de la clase aún no está disponible.</p>
    @endif
</section>
