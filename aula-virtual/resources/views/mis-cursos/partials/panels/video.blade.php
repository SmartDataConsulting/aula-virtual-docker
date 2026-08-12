@if(($session->video_status ?? null) === 'ready' && !empty($session->video_drive_file_id))
  <section class="session-panel student-video-panel">
    <div class="session-panel-header">
      <div>
        <div class="session-panel-title">Video de la sesión</div>
        <div class="session-panel-subtitle">Repasa la clase y revisa los recursos asociados a la grabación.</div>
      </div>
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
          <div class="video-ready-title">Chat de la clase</div>
          <div class="video-ready-copy" title="{{ $session->video_chat_titulo ?? 'chat-de-zoom.txt' }}">
            {{ $session->video_chat_titulo ?? 'chat-de-zoom.txt' }}
          </div>
        </div>
        <div class="session-panel-actions">
          <button type="button" class="btn-secondary" data-preview-video-chat data-session-id="{{ $session->id }}">
            Ver chat
          </button>
          <a class="btn-secondary" href="{{ route('sessions.video.chat.download', ['session' => $session->id]) }}">
            Descargar TXT
          </a>
        </div>
      </div>
    @else
      <div class="session-info-panel mt-4">
        No se adjuntó chat de Zoom para esta grabación.
      </div>
    @endif
  </section>
@else
  <div class="student-panel-empty">
    <strong>La grabación todavía no está disponible</strong>
    <span>Aparecerá aquí cuando tu docente la publique.</span>
  </div>
@endif
