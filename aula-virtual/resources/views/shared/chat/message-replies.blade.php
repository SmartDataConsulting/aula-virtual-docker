@php
    $replies = collect($replies ?? []);
@endphp

@if($replies->isNotEmpty())
    <div class="conversation-message-replies">
        @foreach($replies as $reply)
            @php
                $replyRole = data_get($reply, 'rol_usuario');
                $replyIsOwn = (bool) data_get($reply, 'es_propio', false);
                $replyRoleClass = in_array(strtolower((string) $replyRole), ['docente', 'operador', 'profesor'], true)
                    ? 'is-role-docente'
                    : 'is-role-alumno';
                $replyTimeSource = data_get($reply, 'fecha', '');
                $replyStatus = data_get($reply, 'estado_envio', '');
                $replyId = (string) data_get($reply, 'id', '');
                $replyDeleted = (bool) (data_get($reply, 'eliminado', false) || data_get($reply, 'is_deleted', false));
                $replyTemporary = in_array($replyStatus, ['Enviando...', 'No enviado'], true);
                $replyCanReply = !empty($chatSalaId ?? null)
                    && $replyId !== ''
                    && !$replyTemporary
                    && !$replyDeleted
                    && !$replyIsOwn
                    && !empty($canParticipate ?? false);
                $replyPreview = trim(strip_tags((string) data_get($reply, 'mensaje', '')));
            @endphp
            <div class="conversation-reply-item"
                 data-chat-message-id="{{ $replyId }}"
                 data-chat-own="{{ $replyIsOwn ? '1' : '0' }}"
                 data-chat-author="{{ data_get($reply, 'nombre_usuario', 'Usuario') }}"
                 data-chat-role="{{ $replyRole }}"
                 data-chat-text="{{ $replyPreview }}">
                <div class="message-header">
                    <strong>{{ data_get($reply, 'nombre_usuario', 'Usuario') }}</strong>
                    @if($replyRole)
                        <span class="message-role {{ $replyRoleClass }} {{ $replyIsOwn ? 'is-role-own' : '' }}">
                            {{ $replyIsOwn && strtoupper((string) $replyRole) === 'ALUMNO' ? 'ALUMNO (yo)' : $replyRole }}
                        </span>
                    @endif
                    @if(data_get($reply, 'tiempo_relativo') || data_get($reply, 'fecha'))
                        <span class="message-time" data-chat-time="{{ $replyTimeSource }}">
                            {{ data_get($reply, 'tiempo_relativo') ?: data_get($reply, 'fecha') }}
                        </span>
                    @endif
                </div>
                <div>{!! data_get($reply, 'mensaje', '') !!}</div>
                @if($replyCanReply)
                    <div class="message-actions">
                        <button type="button"
                                data-chat-reply-button
                                data-reply-id="{{ $replyId }}"
                                data-reply-name="{{ data_get($reply, 'nombre_usuario', 'Usuario') }}"
                                data-reply-role="{{ $replyRole }}"
                                data-reply-preview="{{ $replyPreview }}">
                            Responder
                        </button>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
