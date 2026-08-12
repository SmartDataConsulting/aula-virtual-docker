<div class="community-participants"
     data-participants-panel
     data-course-edition-id="{{ $courseEditionId ?? '' }}"
     data-participants-url="{{ $participantsUrl ?? '' }}"
     data-participant-profile-url-template="{{ $participantProfileUrlTemplate ?? '' }}"
     data-participant-cv-url-template="{{ $participantCvUrlTemplate ?? '' }}"
     data-participant-photo-url-template="{{ $participantPhotoUrlTemplate ?? '' }}"
     data-participant-contact-request-url-template="{{ $participantContactRequestUrlTemplate ?? '' }}"
     data-participants-loaded="false">
    <div data-participants-list-view>
        <div class="community-participants-header">
            <h4>Participantes del curso</h4>
            <span data-participants-count>{{ $participantsCount ?? 0 }} participantes</span>
        </div>

        <label class="community-participants-search">
            <span class="sr-only">Buscar participante</span>
            <input type="search"
                   placeholder="Buscar participante..."
                   data-participants-search
                   disabled>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m20 20-4.5-4.5M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" />
            </svg>
        </label>

        <div class="community-participants-status" data-participants-status hidden></div>
        <div class="community-participants-list" data-participants-list></div>
        <div class="community-participants-empty" data-participants-empty hidden>
            Aun no hay participantes para mostrar.
        </div>
        <button type="button" class="community-participants-retry" data-participants-retry hidden>
            Reintentar
        </button>
    </div>

    <div class="community-participant-profile" data-participant-profile-view hidden>
        <button type="button" class="community-participant-back" data-back-to-participants>
            &larr; Volver a participantes
        </button>
        <div class="community-participants-status" data-participant-profile-status hidden></div>
        <div data-participant-profile-content></div>
    </div>
</div>
