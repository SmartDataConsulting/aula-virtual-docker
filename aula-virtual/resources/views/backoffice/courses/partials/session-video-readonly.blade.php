<section id="videoUploadContainer" class="session-panel video-content-panel"
         data-can-write-video="0" data-course-id="{{ $course->id }}" data-session-id="{{ $session->id }}"
         data-show-video-management-note="{{ ($showVideoManagementNote ?? false) ? '1' : '0' }}"
         data-video-status="{{ $session->video_status ?? '' }}"
         data-video-content-url="{{ route('sessions.video.content', ['session' => $session->id]) }}">
    <h2 class="session-panel-title">Video de la sesión</h2>
    <p class="session-panel-subtitle">{{ $videoAudience ?? 'Consulta los recursos publicados para esta sesión.' }}</p>
    @include('backoffice.courses.partials.session-video-resources')
    @if($showVideoManagementNote ?? false)
        <p class="video-consultation-note">Los recursos de esta sesión son gestionados por administración.</p>
    @endif
</section>
