@php
    $isOwn = (bool) data_get($message, 'es_propio', false);
    $role = data_get($message, 'rol_usuario', '');
    $roleClass = in_array(strtolower((string) $role), ['docente', 'operador', 'profesor'], true)
        ? 'is-role-docente'
        : 'is-role-alumno';
    $timeSource = data_get($message, 'fecha', '');
    $time = data_get($message, 'tiempo_relativo') ?: data_get($message, 'fecha', '');
    $status = data_get($message, 'estado_envio', '');
    $messageId = (string) data_get($message, 'id', '');
    $isDeleted = (bool) (data_get($message, 'eliminado', false) || data_get($message, 'is_deleted', false));
    $isTemporary = in_array($status, ['Enviando...', 'No enviado'], true);
    $canReply = !empty($chatSalaId ?? null)
        && $messageId !== ''
        && !$isTemporary
        && !$isDeleted
        && !$isOwn
        && !empty($canParticipate ?? false);
    $canDelete = $messageId !== ''
        && $isOwn
        && empty($readOnly ?? false)
        && !$isTemporary
        && !$isDeleted
        && $status !== 'Eliminando...';
    $replyPreview = trim(strip_tags((string) data_get($message, 'mensaje', '')));
    $authorName = (string) data_get($message, 'nombre_usuario', 'Usuario');
    $authorEmail = strtolower(trim((string) data_get($message, 'correo_usuario', '')));
    $authorPhoto = trim((string) data_get($message, 'foto_url', ''));
    $photoSrc = '';
    if ($authorPhoto !== '' && preg_match('/^(https?:\/\/|data:image\/|\/)/i', $authorPhoto)) {
        $photoSrc = $authorPhoto;
    } elseif ($authorPhoto !== '' && !empty($contextId ?? null) && $authorEmail !== '') {
        $photoSrc = route('community.courses.participants.photo', [
            'cursoEdicionId' => $contextId,
            'correo' => $authorEmail,
        ]);
    }
    $initials = collect(explode(' ', trim($authorName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_substr($part, 0, 1))
        ->implode('');
    $initials = $initials !== '' ? mb_strtoupper($initials) : 'U';
@endphp

<div class="conversation-message-item {{ $isOwn ? 'is-own-message' : '' }}"
     data-chat-message-id="{{ $messageId }}"
     data-chat-own="{{ $isOwn ? '1' : '0' }}"
     data-chat-author="{{ $authorName }}"
     data-chat-author-email="{{ $authorEmail }}"
     data-chat-author-photo="{{ $authorPhoto }}"
     data-chat-role="{{ $role }}"
     data-chat-text="{{ $replyPreview }}"
     data-chat-parent-id="{{ data_get($message, 'parent_id') ?? data_get($message, 'mensaje_padre_id') ?? '' }}"
     data-chat-created-at="{{ $timeSource }}">
    <div class="message-avatar {{ $roleClass }} {{ $isOwn ? 'is-role-own' : '' }}">
        @if($photoSrc !== '')
            <img src="{{ $photoSrc }}" alt="" onerror="this.hidden=true; this.nextElementSibling.hidden=false;">
            <span hidden>{{ $initials }}</span>
        @else
            <span>{{ $initials }}</span>
        @endif
    </div>
    <div class="message-content">
        <div class="message-header">
            <div>
                <strong>{{ $authorName }}</strong>
                @if(!empty($role))
                    <span class="message-role {{ $roleClass }} {{ $isOwn ? 'is-role-own' : '' }}">
                        {{ $isOwn && strtoupper((string) $role) === 'ALUMNO' ? 'ALUMNO' : $role }}
                    </span>
                @endif
                @if(!empty($time))
                    <span class="message-time" data-chat-time="{{ $timeSource }}">{{ $time }}</span>
                @endif
            </div>
            @if($canDelete)
                <div class="message-actions-menu">
                    <button type="button"
                            class="message-actions-toggle"
                            data-chat-actions-toggle
                            aria-label="Opciones del mensaje"
                            aria-expanded="false">
                        <span aria-hidden="true">⋯</span>
                    </button>

                    <div class="message-actions-dropdown" data-chat-actions-menu hidden>
                        <button type="button"
                            class="message-delete-button"
                            data-chat-delete-button
                            data-delete-id="{{ $messageId }}">
                        <svg class="message-delete-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M9 4h6" />
                            <path d="M7 8h10" />
                            <path d="M9 8l.7 11h4.6L15 8" />
                            <path d="M10.5 11v5" />
                            <path d="M13.5 11v5" />
                        </svg>
                        <span>Eliminar</span>
                    </button>
                    </div>
                </div>
            @endif
        </div>

        <div class="message-body">
            @if(data_get($message, 'referencia'))
                <button type="button"
                        class="message-reference"
                        data-chat-reference-id="{{ data_get($message, 'parent_id') ?? data_get($message, 'mensaje_padre_id') ?? data_get($message, 'referencia.id') }}">
                    <strong>{{ data_get($message, 'referencia.nombre_usuario', 'Usuario') }}</strong>
                    @if(data_get($message, 'referencia.rol_usuario'))
                        <em>{{ data_get($message, 'referencia.rol_usuario') }}</em>
                    @endif
                    <span>{{ data_get($message, 'referencia.mensaje', '') }}</span>
                </button>
            @endif
            {!! data_get($message, 'mensaje', '') !!}
        </div>

        @if($canReply)
            <div class="message-actions">
                <button type="button"
                        data-chat-reply-button
                        data-reply-id="{{ $messageId }}"
                        data-reply-name="{{ data_get($message, 'nombre_usuario', 'Usuario') }}"
                        data-reply-role="{{ $role }}"
                        data-reply-preview="{{ $replyPreview }}">
                    <span aria-hidden="true">↩</span> Responder
                </button>
            </div>
        @endif

        @if(!empty($status))
            <div class="message-send-status">{{ $status }}</div>
        @endif

        @include('shared.chat.message-replies', [
            'replies' => data_get($message, 'respuestas', []),
            'chatSalaId' => $chatSalaId ?? null,
            'canParticipate' => $canParticipate ?? false
        ])
    </div>
</div>
