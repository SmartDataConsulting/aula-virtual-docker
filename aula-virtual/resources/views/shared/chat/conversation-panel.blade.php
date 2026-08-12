@php
    $chatMessages = collect($chatMessages ?? []);
    $chatPostUrl = !empty($chatSalaId ?? null)
        ? route('chat.mensajes.store', ['sala' => $chatSalaId])
        : '';
    $chatRefreshUrl = !empty($chatSalaId ?? null)
        ? route('chat.mensajes.index', ['sala' => $chatSalaId])
        : '';
    $normalizedRole = strtolower((string) ($userRole ?? ''));
    $readOnly = (bool) ($readOnly ?? in_array($normalizedRole, ['admin', 'administrador'], true));
    $canParticipate = !$readOnly
        && in_array($normalizedRole, ['alumno', 'operador', 'docente', 'profesor'], true);
    $chatPagination = $chatPagination ?? $chat['pagination'] ?? [];
    $chatLimit = (int) data_get($chatPagination, 'limit', 20);
    $chatOffset = (int) data_get($chatPagination, 'offset', 0);
    $chatHasMore = (bool) data_get($chatPagination, 'has_more', false);
    $showChatHeader = (bool) ($showChatHeader ?? true);
@endphp

<aside class="session-conversation-panel"
       data-chat-context="{{ $chatContext ?? 'COURSE' }}"
       data-context-id="{{ $contextId ?? '' }}"
       data-user-role="{{ $userRole ?? '' }}"
       data-user-name="{{ session(\App\Support\AuthSessionKeys::USER_NAME, 'Tú') }}"
       data-sala-id="{{ $chatSalaId ?? '' }}"
       data-chat-post-url="{{ $chatPostUrl }}"
       data-chat-refresh-url="{{ $chatRefreshUrl }}"
       data-chat-delete-base-url="{{ url('chat/mensajes') }}"
       data-chat-read-only="{{ $readOnly ? '1' : '0' }}"
       data-chat-limit="{{ $chatLimit }}"
       data-chat-offset="{{ $chatOffset }}"
       data-chat-has-more="{{ $chatHasMore ? '1' : '0' }}">
    @if($showChatHeader)
        <div class="conversation-header">
            <div>
                <h3>{{ $chatTitle ?? 'Conversación' }}</h3>
                <span data-chat-count="{{ $chatCount ?? 0 }}">{{ $chatCount ?? 0 }} comentarios</span>
            </div>
        </div>
    @else
        <span class="conversation-count-only" data-chat-count="{{ $chatCount ?? 0 }}" hidden>{{ $chatCount ?? 0 }} comentarios</span>
    @endif

    <button type="button" class="conversation-load-previous" data-chat-load-previous {{ $chatHasMore ? '' : 'hidden' }}>
        Ver mensajes anteriores
    </button>
    <div class="conversation-previous-error" data-chat-previous-error hidden></div>

    <div class="conversation-messages" data-chat-messages>
        @if(!empty($chatLoading))
            <div class="conversation-loading">Cargando conversación...</div>
        @endif

        @if(!empty($chatError))
            <div class="conversation-error">{{ $chatError }}</div>
        @endif

        @if(empty($chatError) && $chatMessages->isEmpty())
            @include('shared.chat.empty-state')
        @else
            @foreach($chatMessages as $message)
                @include('shared.chat.message-item', [
                    'message' => $message,
                    'contextId' => $contextId ?? null,
                    'chatSalaId' => $chatSalaId ?? null,
                    'canParticipate' => $canParticipate,
                    'readOnly' => $readOnly
                ])
            @endforeach
        @endif
    </div>

    <button type="button" class="conversation-new-messages" data-chat-new-messages hidden>
        Nuevos mensajes
    </button>
    <div class="conversation-poll-error" data-chat-poll-error hidden></div>

    @if($readOnly)
        <div class="conversation-readonly-note">Vista solo lectura</div>
    @else
        <div class="conversation-reply-context" data-chat-reply-context hidden>
            <div>
                <span>
                    Respondiendo a
                    <strong data-chat-reply-name></strong>
                    <em data-chat-reply-role hidden></em>
                </span>
                <p data-chat-reply-preview>Mensaje anterior</p>
            </div>
            <button type="button" data-chat-reply-cancel aria-label="Cancelar respuesta">×</button>
        </div>

        <div class="conversation-input">
            <textarea data-chat-input placeholder="Escribe tu comentario, duda o aporte..."></textarea>
            <button type="button" data-chat-send aria-label="Enviar comentario" title="Enviar">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M4 12L20 4L16 20L12 13L4 12Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
        <div class="conversation-form-error" data-chat-form-error hidden></div>

        <div class="conversation-delete-modal" data-chat-delete-modal hidden>
            <div class="conversation-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="chat-delete-title">
                <h4 id="chat-delete-title">¿Eliminar mensaje?</h4>
                <p>Este mensaje se quitará de la conversación.</p>
                <div>
                    <button type="button" data-chat-delete-cancel>Cancelar</button>
                    <button type="button" data-chat-delete-confirm>Eliminar</button>
                </div>
            </div>
        </div>
    @endif
</aside>
