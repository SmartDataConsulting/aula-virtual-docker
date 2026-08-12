@php
    $chat = $chat ?? [];
    $participants = $participants ?? [];
    $participantsCount = is_countable($participants) ? count($participants) : 0;
    $chatCount = data_get($chat, 'total_mensajes', 0);
    $courseEditionId = $courseEditionId ?? $contextId ?? data_get($chat, 'context_id');
    $participantsUrl = !empty($courseEditionId)
        ? route('community.courses.participants.index', ['cursoEdicionId' => $courseEditionId])
        : '';
    $participantProfileUrlTemplate = !empty($courseEditionId)
        ? route('community.courses.participants.profile', ['cursoEdicionId' => $courseEditionId, 'correo' => '__CORREO__'])
        : '';
    $participantCvUrlTemplate = !empty($courseEditionId)
        ? route('community.courses.participants.cv', ['cursoEdicionId' => $courseEditionId, 'correo' => '__CORREO__'])
        : '';
    $participantPhotoUrlTemplate = !empty($courseEditionId)
        ? route('community.courses.participants.photo', ['cursoEdicionId' => $courseEditionId, 'correo' => '__CORREO__'])
        : '';
    $participantContactRequestUrlTemplate = !empty($courseEditionId)
        ? route('curso.participantes.solicitar-contacto', ['cursoEdicionId' => $courseEditionId, 'correo' => '__CORREO__'])
        : '';
    $summary = $participantsCount > 0
        ? "{$chatCount} comentarios · {$participantsCount} participantes"
        : "{$chatCount} comentarios";
@endphp

<section class="course-community-panel"
         data-community-panel
         data-participant-photo-url-template="{{ $participantPhotoUrlTemplate }}">
    <div class="community-header">
        <div>
            <h3>Comunidad del curso</h3>
            <span data-community-chat-count="{{ $chatCount }}"
                  data-community-participants-count="{{ $participantsCount }}">{{ $summary }}</span>
        </div>
    </div>

    <div class="community-tabs" role="tablist" aria-label="Secciones de comunidad">
        <button type="button"
                class="community-tab is-active"
                role="tab"
                aria-selected="true"
                data-community-tab="conversation">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M7 8h10M7 12h6m-7.5 6.5 2.2-2.2H17a4 4 0 0 0 4-4V8a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v4.3a4 4 0 0 0 2.5 3.7v2.5Z" />
            </svg>
            Conversación
        </button>
        <button type="button"
                class="community-tab"
                role="tab"
                aria-selected="false"
                data-community-tab="participants">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M16 11a3 3 0 1 0-2.4-4.8M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3 0-5 1.6-5 3.6V18h10v-1.4C13 14.6 11 13 8 13Zm8.2.4c2.3.4 3.8 1.7 3.8 3.2V18h-4.5" />
            </svg>
            Participantes
        </button>
    </div>

    <div class="community-tab-panel is-active"
         data-community-panel-content="conversation"
         role="tabpanel">
        @include('shared.community.conversation', [
            'chat' => $chat,
            'chatContext' => $context ?? $chatContext ?? 'COURSE',
            'contextId' => $contextId ?? data_get($chat, 'context_id'),
            'userRole' => $userRole ?? '',
            'readOnly' => $readOnly ?? false
        ])
    </div>

    <div class="community-tab-panel"
         data-community-panel-content="participants"
         role="tabpanel"
         hidden>
        @include('shared.community.participants', [
            'participants' => $participants,
            'participantsCount' => $participantsCount,
            'courseEditionId' => $courseEditionId,
            'participantsUrl' => $participantsUrl,
            'participantProfileUrlTemplate' => $participantProfileUrlTemplate,
            'participantCvUrlTemplate' => $participantCvUrlTemplate,
            'participantPhotoUrlTemplate' => $participantPhotoUrlTemplate,
            'participantContactRequestUrlTemplate' => $participantContactRequestUrlTemplate
        ])
    </div>
</section>
