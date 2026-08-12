const POLLING_INTERVAL_MS = 10000;

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function normalizeRole(role) {
    return String(role || '').toUpperCase();
}

function roleClass(role, isOwn) {
    if (isOwn) {
        return 'is-role-own';
    }

    return ['DOCENTE', 'OPERADOR', 'PROFESOR'].includes(normalizeRole(role))
        ? 'is-role-docente'
        : 'is-role-alumno';
}

function roleLabel(role, isOwn) {
    const normalized = normalizeRole(role);

    if (isOwn && normalized === 'ALUMNO') {
        return 'ALUMNO';
    }

    return normalized || role || '';
}

function canParticipate(role) {
    return ['ALUMNO', 'DOCENTE', 'OPERADOR', 'PROFESOR'].includes(normalizeRole(role));
}

function initialsFor(name) {
    const parts = String(name || 'Usuario').trim().split(/\s+/).filter(Boolean).slice(0, 2);
    const initials = parts.map((part) => part.charAt(0)).join('').toUpperCase();

    return initials || 'U';
}

function normalizeReference(reference) {
    if (!reference || typeof reference !== 'object') {
        return null;
    }

    return {
        id: reference.id ?? reference.mensaje_id ?? '',
        nombre_usuario: reference.nombre_usuario ?? reference.usuario_nombre ?? reference.nombre ?? 'Usuario',
        rol_usuario: reference.rol_usuario ?? reference.rol ?? reference.role ?? '',
        mensaje: reference.mensaje ?? reference.contenido ?? reference.message ?? reference.body ?? '',
    };
}

function readMessage(payload, fallback = {}) {
    const data = payload && typeof payload === 'object' ? payload : {};
    const name = data.nombre_usuario ?? data.usuario_nombre ?? data.nombre ?? '';
    const isGenericName = !name || name === 'Usuario';
    const hasOwnershipSignal = data.es_propio !== undefined && data.es_propio !== null;

    return {
        id: data.id ?? data.mensaje_id ?? fallback.id ?? '',
        nombre_usuario: isGenericName ? (fallback.nombre_usuario ?? name ?? 'Tú') : name,
        correo_usuario: String(data.correo_usuario ?? data.user_email ?? data.email ?? data.usuario_email ?? data.usuario_id ?? fallback.correo_usuario ?? '').trim().toLowerCase(),
        foto_url: String(data.foto_url ?? data.usuario_foto_url ?? data.avatar_url ?? data.photo_url ?? fallback.foto_url ?? '').trim(),
        rol_usuario: data.rol_usuario ?? data.rol ?? data.role ?? fallback.rol_usuario ?? '',
        mensaje: data.mensaje ?? data.contenido ?? data.message ?? data.body ?? fallback.mensaje ?? '',
        fecha: data.fecha ?? data.fecha_creacion ?? data.created_at ?? data.creado_en ?? fallback.fecha ?? '',
        tiempo_relativo: data.tiempo_relativo ?? data.relative_time ?? fallback.tiempo_relativo ?? '',
        es_propio: hasOwnershipSignal ? Boolean(data.es_propio) : (fallback.es_propio ?? true),
        estado_envio: data.estado_envio ?? fallback.estado_envio ?? '',
        parent_id: data.parent_id ?? data.mensaje_padre_id ?? fallback.parent_id ?? null,
        referencia: normalizeReference(data.referencia ?? data.mensaje_padre ?? data.parent ?? data.reply_to) ?? fallback.referencia ?? null,
        eliminado: Boolean(data.eliminado ?? data.deleted ?? data.is_deleted ?? fallback.eliminado ?? false),
    };
}

function parseChatDate(value) {
    if (!value) {
        return null;
    }

    const normalized = String(value).trim().replace(' ', 'T');
    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return date;
}

function relativeTimeLabel(value) {
    const date = parseChatDate(value);

    if (!date) {
        return '';
    }

    const now = new Date();
    const diffSeconds = Math.max(0, Math.floor((now.getTime() - date.getTime()) / 1000));

    if (diffSeconds < 60) {
        return 'ahora';
    }

    if (diffSeconds < 60) {
        return `hace ${diffSeconds} seg`;
    }

    const diffMinutes = Math.floor(diffSeconds / 60);

    if (diffMinutes < 60) {
        return `hace ${diffMinutes} min`;
    }

    const diffHours = Math.floor(diffMinutes / 60);

    if (diffHours < 24) {
        return `hace ${diffHours} h`;
    }

    const diffDays = Math.floor(diffHours / 24);

    if (diffDays < 2) {
        return 'ayer';
    }

    if (diffDays < 7) {
        return `hace ${diffDays} días`;
    }

    return date.toLocaleDateString('es-PE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function refreshRelativeTimes(root = document) {
    root.querySelectorAll('[data-chat-time]').forEach((element) => {
        const label = relativeTimeLabel(element.dataset.chatTime);

        if (label) {
            element.textContent = label;
        }
    });
}

function referenceFromKnownMessage(message) {
    if (!message) {
        return null;
    }

    return {
        id: message.id || '',
        nombre_usuario: message.nombre_usuario || 'Usuario',
        rol_usuario: message.rol_usuario || '',
        mensaje: message.mensaje || '',
    };
}

function ensureReference(panel, message) {
    const msg = readMessage(message);

    if (msg.parent_id && panel._chatDeletedMessageIds?.has(String(msg.parent_id))) {
        msg.referencia = {
            id: String(msg.parent_id),
            nombre_usuario: 'Mensaje original eliminado',
            rol_usuario: '',
            mensaje: '',
        };

        return msg;
    }

    if (!msg.parent_id || msg.referencia) {
        return msg;
    }

    const knownParent = panel._chatMessagesById?.get(String(msg.parent_id));
    msg.referencia = referenceFromKnownMessage(knownParent) || {
        id: String(msg.parent_id),
        nombre_usuario: 'Respuesta a un mensaje anterior',
        rol_usuario: '',
        mensaje: '',
    };

    return msg;
}

function shouldShowReply(panel, message) {
    const msg = readMessage(message);
    const status = msg.estado_envio || '';

    return panel.dataset.chatReadOnly !== '1'
        && Boolean(panel.dataset.salaId)
        && Boolean(msg.id)
        && !['Enviando...', 'No enviado'].includes(status)
        && !msg.eliminado
        && !msg.es_propio
        && canParticipate(panel.dataset.userRole);
}

function shouldShowDelete(panel, message) {
    const msg = readMessage(message);
    const status = msg.estado_envio || '';

    return panel?.dataset.chatReadOnly !== '1'
        && Boolean(msg.id)
        && !String(msg.id).startsWith('temp-')
        && msg.es_propio
        && !msg.eliminado
        && !['Enviando...', 'No enviado', 'Eliminando...'].includes(status);
}

function getParticipantPhotoTemplate(panel = null) {
    return panel?.dataset?.participantPhotoUrlTemplate
        || panel?.closest?.('[data-community-panel]')?.dataset?.participantPhotoUrlTemplate
        || panel?.closest?.('[data-participants-panel]')?.dataset?.participantPhotoUrlTemplate
        || '';
}

function resolveAvatarSrc(rawPhoto, correo, panel = null) {
    const photo = String(rawPhoto || '').trim();
    const email = String(correo || '').trim();

    if (photo && isRenderableImageUrl(photo)) {
        return photo;
    }

    if (!photo || !email) {
        return '';
    }

    return buildParticipantUrl(getParticipantPhotoTemplate(panel), email);
}

function avatarHtml(name, rawPhoto, correo, panel = null) {
    const initials = escapeHtml(initialsFor(name));
    const src = resolveAvatarSrc(rawPhoto, correo, panel);

    if (!src) {
        return `<span>${initials}</span>`;
    }

    return `<img src="${escapeHtml(src)}" alt="" onerror="this.hidden=true; this.nextElementSibling.hidden=false;"><span hidden>${initials}</span>`;
}

function messageTemplate(message, panel = null) {
    const msg = panel ? ensureReference(panel, message) : readMessage(message);
    const role = normalizeRole(msg.rol_usuario);
    const isOwn = Boolean(msg.es_propio);
    const status = msg.estado_envio || '';
    const timeSource = msg.fecha || new Date().toISOString();
    const displayTime = relativeTimeLabel(timeSource) || msg.tiempo_relativo || msg.fecha || '';
    const reference = msg.referencia && typeof msg.referencia === 'object' ? msg.referencia : null;
    const currentRoleClass = roleClass(role, isOwn);
    const replyAllowed = panel ? shouldShowReply(panel, msg) : false;
    const deleteAllowed = panel ? shouldShowDelete(panel, msg) : false;

    return `
        <div class="conversation-message-item ${isOwn ? 'is-own-message' : ''}"
             data-chat-message-id="${escapeHtml(msg.id)}"
             data-chat-own="${isOwn ? '1' : '0'}"
             data-chat-author="${escapeHtml(msg.nombre_usuario)}"
             data-chat-author-email="${escapeHtml(msg.correo_usuario || '')}"
             data-chat-author-photo="${escapeHtml(msg.foto_url || '')}"
             data-chat-role="${escapeHtml(role)}"
             data-chat-text="${escapeHtml(msg.mensaje)}"
             data-chat-parent-id="${escapeHtml(msg.parent_id || '')}"
             data-chat-created-at="${escapeHtml(timeSource)}">
            <div class="message-avatar ${currentRoleClass}">${avatarHtml(msg.nombre_usuario, msg.foto_url, msg.correo_usuario, panel)}</div>
            <div class="message-content">
                <div class="message-header">
                    <div>
                        <strong>${escapeHtml(msg.nombre_usuario)}</strong>
                        ${role ? `<span class="message-role ${currentRoleClass}">${escapeHtml(roleLabel(role, isOwn))}</span>` : ''}
                        ${displayTime ? `<span class="message-time" data-chat-time="${escapeHtml(timeSource)}">${escapeHtml(displayTime)}</span>` : ''}
                    </div>
                    ${deleteAllowed ? `
                    <div class="message-actions-menu">
                        <button type="button"
                                class="message-actions-toggle"
                                data-chat-actions-toggle
                                aria-label="Opciones del mensaje"
                                aria-expanded="false">
                            ...
                        </button>
                        <div class="message-actions-dropdown" data-chat-actions-menu hidden>
                                <button type="button"
                                        class="message-delete-button"
                                        data-chat-delete-button
                                        data-delete-id="${escapeHtml(msg.id)}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M9 4h6m-8 4h10m-9 0 .7 11h6.6L16 8M10 11v5m4-5v5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Eliminar
                                </button>
                        </div>
                    </div>
                    ` : ''}
                </div>

                <div class="message-body">
                    ${reference ? `
                        <button type="button" class="message-reference" data-chat-reference-id="${escapeHtml(msg.parent_id || reference.id || '')}">
                            <strong>${escapeHtml(reference.nombre_usuario || 'Respuesta a un mensaje anterior')}</strong>
                            ${reference.rol_usuario ? `<em>${escapeHtml(reference.rol_usuario)}</em>` : ''}
                            ${reference.mensaje ? `<span>${escapeHtml(reference.mensaje)}</span>` : ''}
                        </button>
                    ` : ''}
                    ${escapeHtml(msg.mensaje)}
                </div>

                ${replyAllowed ? `
                    <div class="message-actions">
                        <button type="button"
                                data-chat-reply-button
                                data-reply-id="${escapeHtml(msg.id)}"
                                data-reply-name="${escapeHtml(msg.nombre_usuario)}"
                                data-reply-role="${escapeHtml(role)}"
                                data-reply-preview="${escapeHtml(msg.mensaje)}">
                            <span aria-hidden="true">↩</span> Responder
                        </button>
                    </div>
                ` : ''}

                ${status ? `<div class="message-send-status">${escapeHtml(status)}</div>` : ''}
            </div>
        </div>
    `;
}

function setFormError(panel, message) {
    const error = panel.querySelector('[data-chat-form-error]');

    if (!error) {
        return;
    }

    if (!message) {
        error.hidden = true;
        error.textContent = '';
        return;
    }

    error.textContent = message;
    error.hidden = false;
}

function setPollingError(panel, message) {
    const error = panel.querySelector('[data-chat-poll-error]');

    if (!error) {
        return;
    }

    if (!message) {
        error.hidden = true;
        error.textContent = '';
        return;
    }

    error.textContent = message;
    error.hidden = false;
}

function openDeleteModal(panel, messageElement, messageId) {
    const modal = panel.querySelector('[data-chat-delete-modal]');

    if (!modal || !messageElement || !messageId) {
        return;
    }

    panel._chatDeleteSelection = {
        messageElement,
        messageId,
    };
    modal.hidden = false;
}

function closeDeleteModal(panel) {
    const modal = panel.querySelector('[data-chat-delete-modal]');

    if (modal) {
        modal.hidden = true;
    }

    panel._chatDeleteSelection = null;
}

function cacheMessageFromElement(panel, element) {
    if (!element) {
        return;
    }

    cacheMessage(panel, {
        id: element.dataset.chatMessageId || '',
        nombre_usuario: element.dataset.chatAuthor || 'Usuario',
        correo_usuario: element.dataset.chatAuthorEmail || '',
        foto_url: element.dataset.chatAuthorPhoto || '',
        rol_usuario: element.dataset.chatRole || '',
        mensaje: element.dataset.chatText || '',
        parent_id: element.dataset.chatParentId || null,
        fecha: element.dataset.chatCreatedAt || '',
        es_propio: element.dataset.chatOwn === '1',
    });
}

async function deleteMessageOptimistically(panel) {
    const selection = panel._chatDeleteSelection;
    const deleteBaseUrl = panel.dataset.chatDeleteBaseUrl || '';

    if (!selection?.messageElement || !selection.messageId || !deleteBaseUrl) {
        closeDeleteModal(panel);
        setFormError(panel, 'No se pudo identificar el mensaje a eliminar.');
        return;
    }

    const { messageElement, messageId } = selection;
    const placeholder = document.createComment('chat deleted message placeholder');
    const originalHtml = messageElement.outerHTML;
    const messages = panel.querySelector('[data-chat-messages]');

    closeDeleteModal(panel);
    setFormError(panel, '');

    panel._chatDeletedMessageIds ??= new Set();
    panel._chatDeletedMessageIds.add(String(messageId));
    messageElement.replaceWith(placeholder);
    panel._chatMessagesById?.delete(String(messageId));
    updateCount(panel, -1);

    try {
        const response = await fetch(`${deleteBaseUrl}/${encodeURIComponent(messageId)}`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'No se pudo eliminar el mensaje. Intenta nuevamente.');
        }

        markDeletedReferences(panel, messageId);
        placeholder.remove();
    } catch (error) {
        panel._chatDeletedMessageIds?.delete(String(messageId));
        placeholder.before(document.createRange().createContextualFragment(originalHtml));
        const restored = messages?.querySelector(`[data-chat-message-id="${CSS.escape(messageId)}"]`);
        placeholder.remove();
        cacheMessageFromElement(panel, restored);
        updateCount(panel, 1);
        setFormError(panel, error.message || 'No se pudo eliminar el mensaje. Intenta nuevamente.');
    }
}

function updateCount(panel, increment = 1) {
    const count = panel.querySelector('[data-chat-count]');

    if (!count) {
        return;
    }

    const next = Math.max(0, Number(count.dataset.chatCount || 0) + increment);
    count.dataset.chatCount = String(next);
    count.textContent = `${next} comentarios`;

    const communityCount = panel.closest('[data-community-panel]')?.querySelector('[data-community-chat-count]');

    if (communityCount) {
        const participants = Number(communityCount.dataset.communityParticipantsCount || 0);
        communityCount.dataset.communityChatCount = String(next);
        communityCount.textContent = participants > 0
            ? `${next} comentarios · ${participants} participantes`
            : `${next} comentarios`;
    }
}

function updateCommunityParticipantsCount(participantsPanel, total) {
    const count = participantsPanel.querySelector('[data-participants-count]');
    const communityCount = participantsPanel.closest('[data-community-panel]')?.querySelector('[data-community-chat-count]');

    if (count) {
        count.textContent = `${total} participantes`;
    }

    if (communityCount) {
        const chatCount = Number(communityCount.dataset.communityChatCount || 0);
        communityCount.dataset.communityParticipantsCount = String(total);
        communityCount.textContent = total > 0
            ? `${chatCount} comentarios · ${total} participantes`
            : `${chatCount} comentarios`;
    }
}

function replaceMessage(element, message, panel = null) {
    const msg = panel ? ensureReference(panel, message) : readMessage(message);
    const wrapper = document.createElement('div');
    wrapper.innerHTML = messageTemplate(msg, panel).trim();
    element.replaceWith(wrapper.firstElementChild);
    cacheMessage(panel, msg);
}

function cacheMessage(panel, message) {
    if (!panel) {
        return;
    }

    const msg = readMessage(message);

    if (!msg.id) {
        return;
    }

    panel._chatMessagesById ??= new Map();
    panel._chatMessagesById.set(String(msg.id), msg);
}

function collectExistingMessages(panel) {
    panel._chatMessagesById = new Map();

    panel.querySelectorAll('[data-chat-message-id]').forEach((element) => {
        const id = element.dataset.chatMessageId || '';

        if (!id || id.startsWith('temp-')) {
            return;
        }

        cacheMessage(panel, {
            id,
            nombre_usuario: element.dataset.chatAuthor || 'Usuario',
            correo_usuario: element.dataset.chatAuthorEmail || '',
            foto_url: element.dataset.chatAuthorPhoto || '',
            rol_usuario: element.dataset.chatRole || '',
            mensaje: element.dataset.chatText || '',
            parent_id: element.dataset.chatParentId || null,
            fecha: element.dataset.chatCreatedAt || '',
            es_propio: element.dataset.chatOwn === '1',
        });
    });
}

function clearReplyMode(panel) {
    const context = panel.querySelector('[data-chat-reply-context]');
    const name = panel.querySelector('[data-chat-reply-name]');
    const role = panel.querySelector('[data-chat-reply-role]');
    const preview = panel.querySelector('[data-chat-reply-preview]');
    const textarea = panel.querySelector('[data-chat-input]');

    panel.dataset.replyToId = '';
    panel.dataset.replyToName = '';
    panel.dataset.replyToRole = '';
    panel.dataset.replyToPreview = '';

    if (name) {
        name.textContent = '';
    }

    if (role) {
        role.textContent = '';
        role.hidden = true;
    }

    if (preview) {
        preview.textContent = 'Mensaje anterior';
    }

    if (context) {
        context.hidden = true;
    }

    if (textarea) {
        textarea.placeholder = 'Escribe tu comentario, duda o aporte...';
    }
}

function setReplyMode(panel, button) {
    const context = panel.querySelector('[data-chat-reply-context]');
    const name = panel.querySelector('[data-chat-reply-name]');
    const role = panel.querySelector('[data-chat-reply-role]');
    const preview = panel.querySelector('[data-chat-reply-preview]');
    const textarea = panel.querySelector('[data-chat-input]');

    panel.dataset.replyToId = button.dataset.replyId || '';
    panel.dataset.replyToName = button.dataset.replyName || 'Usuario';
    panel.dataset.replyToRole = button.dataset.replyRole || '';
    panel.dataset.replyToPreview = button.dataset.replyPreview || '';

    if (name) {
        name.textContent = panel.dataset.replyToName;
    }

    if (role) {
        role.textContent = panel.dataset.replyToRole ? ` · ${panel.dataset.replyToRole}` : '';
        role.hidden = !panel.dataset.replyToRole;
    }

    if (preview) {
        preview.textContent = panel.dataset.replyToPreview || 'Mensaje anterior';
    }

    if (context) {
        context.hidden = false;
    }

    if (textarea) {
        textarea.placeholder = 'Escribe tu respuesta...';
        textarea.focus();
    }
}

function isNearBottom(messages) {
    return messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
}

function scrollToBottom(messages) {
    messages.scrollTo({
        top: messages.scrollHeight,
        behavior: 'smooth',
    });
}

function highlightOriginalMessage(message) {
    message.classList.add('is-chat-highlighted');

    window.setTimeout(() => {
        message.classList.remove('is-chat-highlighted');
    }, 3000);
}

function goToReferencedMessage(panel, referenceId) {
    const messages = panel.querySelector('[data-chat-messages]');

    if (!messages || !referenceId) {
        setPollingError(panel, 'El mensaje original no está disponible en la vista actual.');
        return;
    }

    const original = messages.querySelector(`[data-chat-message-id="${CSS.escape(referenceId)}"]`);

    if (!original) {
        setPollingError(panel, 'El mensaje original no está disponible en la vista actual.');
        return;
    }

    setPollingError(panel, '');

    const top = original.offsetTop - messages.offsetTop - 12;
    messages.scrollTo({
        top: Math.max(top, 0),
        behavior: 'smooth',
    });
    highlightOriginalMessage(original);
}

function showNewMessagesIndicator(panel) {
    const indicator = panel.querySelector('[data-chat-new-messages]');

    if (indicator) {
        indicator.hidden = false;
    }
}

function hideNewMessagesIndicator(panel) {
    const indicator = panel.querySelector('[data-chat-new-messages]');

    if (indicator) {
        indicator.hidden = true;
    }
}

function closeMessageActionMenus(panel, except = null) {
    panel.querySelectorAll('[data-chat-actions-menu]').forEach((menu) => {
        if (menu === except) {
            return;
        }

        menu.hidden = true;
        menu.closest('.message-actions-menu')
            ?.querySelector('[data-chat-actions-toggle]')
            ?.setAttribute('aria-expanded', 'false');
    });
}

function findMatchingTemporary(messages, message) {
    const msg = readMessage(message);

    if (!msg.es_propio || !msg.mensaje) {
        return null;
    }

    const candidates = [...messages.querySelectorAll('[data-chat-message-id^="temp-"]')]
        .filter((element) => element.dataset.chatOwn === '1'
            && element.dataset.chatText === msg.mensaje
            && (element.dataset.chatParentId || '') === (msg.parent_id || ''));

    return candidates.length === 1 ? candidates[0] : null;
}

function isTemporaryMessageElement(element) {
    const id = element.dataset.chatMessageId || '';

    return !id || id.startsWith('temp-');
}

function markDeletedReferences(panel, messageId) {
    panel.querySelectorAll(`[data-chat-reference-id="${CSS.escape(messageId)}"]`).forEach((reference) => {
        const title = reference.querySelector('strong');
        const role = reference.querySelector('em');
        const preview = reference.querySelector('span');

        if (title) {
            title.textContent = 'Mensaje original eliminado';
        }

        role?.remove();
        preview?.remove();
    });
}

function removeConfirmedMessage(panel, element) {
    const messageId = element.dataset.chatMessageId || '';

    if (!messageId || isTemporaryMessageElement(element)) {
        return false;
    }

    panel._chatDeletedMessageIds ??= new Set();
    panel._chatDeletedMessageIds.add(String(messageId));
    panel._chatMessagesById?.delete(String(messageId));
    markDeletedReferences(panel, messageId);
    element.remove();

    return true;
}

function syncDeletedMessages(panel, rawMessages) {
    const messages = panel.querySelector('[data-chat-messages]');

    if (!messages || !Array.isArray(rawMessages)) {
        return 0;
    }

    const backendMessages = rawMessages.map((rawMessage) => readMessage(rawMessage));
    const activeBackendIds = new Set(
        backendMessages
            .filter((message) => message.id && !message.eliminado)
            .map((message) => String(message.id))
    );
    const deletedBackendIds = new Set(
        backendMessages
            .filter((message) => message.id && message.eliminado)
            .map((message) => String(message.id))
    );
    const backendDates = backendMessages
        .filter((message) => message.id && !message.eliminado)
        .map((message) => parseChatDate(message.fecha))
        .filter(Boolean)
        .map((date) => date.getTime());
    const oldestBackendTime = backendDates.length ? Math.min(...backendDates) : null;
    let removed = 0;

    messages.querySelectorAll('[data-chat-message-id]').forEach((element) => {
        const messageId = element.dataset.chatMessageId || '';

        if (isTemporaryMessageElement(element)) {
            return;
        }

        const messageDate = parseChatDate(element.dataset.chatCreatedAt || '');
        const isInsideCurrentWindow = oldestBackendTime === null
            || !messageDate
            || messageDate.getTime() >= oldestBackendTime;

        if (deletedBackendIds.has(messageId) || (isInsideCurrentWindow && !activeBackendIds.has(messageId))) {
            removed += removeConfirmedMessage(panel, element) ? 1 : 0;
        }
    });

    if (removed > 0) {
        updateCount(panel, -removed);
    }

    return removed;
}

function appendMessages(panel, rawMessages) {
    const messages = panel.querySelector('[data-chat-messages]');

    if (!messages || !Array.isArray(rawMessages)) {
        return 0;
    }

    syncDeletedMessages(panel, rawMessages);

    const wasAtBottom = isNearBottom(messages);
    let added = 0;

    rawMessages.forEach((rawMessage) => {
        const message = ensureReference(panel, rawMessage);

        if (message.id && panel._chatDeletedMessageIds?.has(String(message.id))) {
            return;
        }

        if (message.eliminado) {
            return;
        }

        if (!message.id || panel._chatMessagesById?.has(String(message.id))) {
            return;
        }

        const temporary = findMatchingTemporary(messages, message);

        if (temporary) {
            replaceMessage(temporary, message, panel);
            added += 1;
            return;
        }

        const emptyState = messages.querySelector('.conversation-empty-state');
        emptyState?.remove();
        messages.insertAdjacentHTML('beforeend', messageTemplate(message, panel));
        cacheMessage(panel, message);
        added += 1;
    });

    if (added > 0) {
        updateCount(panel, added);
        refreshRelativeTimes(messages);

        if (wasAtBottom) {
            scrollToBottom(messages);
        } else {
            showNewMessagesIndicator(panel);
        }
    }

    return added;
}

function prependMessages(panel, rawMessages) {
    const messages = panel.querySelector('[data-chat-messages]');

    if (!messages || !Array.isArray(rawMessages)) {
        return 0;
    }

    const previousScrollHeight = messages.scrollHeight;
    const previousScrollTop = messages.scrollTop;
    let added = 0;
    let html = '';

    rawMessages.forEach((rawMessage) => {
        const message = ensureReference(panel, rawMessage);

        if (message.id && panel._chatDeletedMessageIds?.has(String(message.id))) {
            return;
        }

        if (message.eliminado || !message.id || panel._chatMessagesById?.has(String(message.id))) {
            return;
        }

        html += messageTemplate(message, panel);
        cacheMessage(panel, message);
        added += 1;
    });

    if (html !== '') {
        messages.insertAdjacentHTML('afterbegin', html);
        refreshRelativeTimes(messages);
        messages.scrollTop = previousScrollTop + (messages.scrollHeight - previousScrollHeight);
    }

    return added;
}

function extractMessages(data) {
    if (!data || typeof data !== 'object') {
        return [];
    }

    if (Array.isArray(data.mensajes)) {
        return data.mensajes;
    }

    if (Array.isArray(data.messages)) {
        return data.messages;
    }

    if (Array.isArray(data.data)) {
        return data.data;
    }

    return Array.isArray(data) ? data : [];
}

function extractPagination(data) {
    if (!data || typeof data !== 'object' || !data.pagination) {
        return null;
    }

    return data.pagination;
}

function hasMorePrevious(data, messageCount, limit) {
    const pagination = extractPagination(data);

    if (pagination && Object.prototype.hasOwnProperty.call(pagination, 'has_more')) {
        return Boolean(pagination.has_more);
    }

    return messageCount >= limit;
}

function setPreviousError(panel, message) {
    const error = panel.querySelector('[data-chat-previous-error]');

    if (!error) {
        return;
    }

    if (!message) {
        error.hidden = true;
        error.textContent = '';
        return;
    }

    error.textContent = message;
    error.hidden = false;
}

function updatePreviousButton(panel, { loading = false, hasMore = null } = {}) {
    const button = panel.querySelector('[data-chat-load-previous]');

    if (!button) {
        return;
    }

    if (hasMore !== null) {
        panel.dataset.chatHasMore = hasMore ? '1' : '0';
    }

    const canShow = panel.dataset.chatHasMore === '1';
    button.hidden = !canShow;
    button.disabled = loading;
    button.textContent = loading ? 'Cargando mensajes anteriores...' : 'Ver mensajes anteriores';
}

async function loadPreviousMessages(panel) {
    const refreshUrl = panel.dataset.chatRefreshUrl || '';

    if (!refreshUrl || panel._chatLoadingPrevious || panel.dataset.chatHasMore !== '1') {
        return;
    }

    const limit = Number(panel.dataset.chatLimit || 20);
    const currentOffset = Number(panel.dataset.chatOffset || 0);
    const nextOffset = currentOffset + limit;

    panel._chatLoadingPrevious = true;
    updatePreviousButton(panel, { loading: true });
    setPreviousError(panel, '');

    try {
        const url = new URL(refreshUrl, window.location.origin);
        url.searchParams.set('limit', String(limit));
        url.searchParams.set('offset', String(nextOffset));

        const response = await fetch(url.toString(), {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'No se pudieron cargar mensajes anteriores. Intenta nuevamente.');
        }

        const olderMessages = extractMessages(data);
        prependMessages(panel, olderMessages);
        panel.dataset.chatOffset = String(nextOffset);
        updatePreviousButton(panel, {
            loading: false,
            hasMore: hasMorePrevious(data, olderMessages.length, limit),
        });
    } catch (error) {
        setPreviousError(panel, error.message || 'No se pudieron cargar mensajes anteriores. Intenta nuevamente.');
        updatePreviousButton(panel, { loading: false });
    } finally {
        panel._chatLoadingPrevious = false;
    }
}

async function fetchUpdatedMessages(panel) {
    const salaId = panel.dataset.salaId || '';
    const refreshUrl = panel.dataset.chatRefreshUrl || '';

    if (!salaId || !refreshUrl || panel._chatPollingInFlight || panel._chatSendingInFlight || document.hidden) {
        return;
    }

    panel._chatPollingInFlight = true;

    try {
        const url = new URL(refreshUrl, window.location.origin);
        url.searchParams.set('limit', '20');
        url.searchParams.set('offset', '0');

        const response = await fetch(url.toString(), {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'No se pudo actualizar la conversación.');
        }

        appendMessages(panel, extractMessages(data));
        setPollingError(panel, '');
    } catch (error) {
        setPollingError(panel, error.message || 'No se pudo actualizar la conversación.');
    } finally {
        panel._chatPollingInFlight = false;
    }
}

function startPolling(panel) {
    if (!panel.dataset.salaId || !panel.dataset.chatRefreshUrl || panel._chatPollingInterval) {
        return;
    }

    panel._chatPollingInterval = window.setInterval(() => {
        if (!document.body.contains(panel)) {
            stopPolling(panel);
            return;
        }

        fetchUpdatedMessages(panel);
    }, POLLING_INTERVAL_MS);
}

function stopPolling(panel) {
    if (!panel?._chatPollingInterval) {
        return;
    }

    window.clearInterval(panel._chatPollingInterval);
    panel._chatPollingInterval = null;
    panel._chatPollingInFlight = false;
}

function initComposer(panel) {
    const textarea = panel.querySelector('[data-chat-input]');
    const button = panel.querySelector('[data-chat-send]');
    const messages = panel.querySelector('[data-chat-messages]');
    const cancelReply = panel.querySelector('[data-chat-reply-cancel]');
    const readOnly = panel.dataset.chatReadOnly === '1';

    if (!messages) {
        return;
    }

    panel.addEventListener('click', (event) => {
        const reference = event.target.closest('[data-chat-reference-id]');

        if (reference) {
            goToReferencedMessage(panel, reference.dataset.chatReferenceId || '');
            return;
        }

        if (readOnly) {
            return;
        }

        const actionsToggle = event.target.closest('[data-chat-actions-toggle]');

        if (actionsToggle) {
            const menu = actionsToggle.closest('.message-actions-menu')?.querySelector('[data-chat-actions-menu]');

            if (menu) {
                const willOpen = menu.hidden;
                closeMessageActionMenus(panel, willOpen ? menu : null);
                menu.hidden = !willOpen;
                actionsToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            }

            return;
        }

        const deleteButton = event.target.closest('[data-chat-delete-button]');

        if (deleteButton) {
            const messageElement = deleteButton.closest('[data-chat-message-id]');
            closeMessageActionMenus(panel);
            openDeleteModal(panel, messageElement, deleteButton.dataset.deleteId || '');
            return;
        }

        const replyButton = event.target.closest('[data-chat-reply-button]');

        if (!replyButton) {
            return;
        }

        setReplyMode(panel, replyButton);
    });

    document.addEventListener('click', (event) => {
        if (!panel.contains(event.target)) {
            closeMessageActionMenus(panel);
        }
    });

    if (readOnly || !textarea || !button) {
        return;
    }

    cancelReply?.addEventListener('click', () => clearReplyMode(panel));

    button.addEventListener('click', async () => {
        const salaId = panel.dataset.salaId || '';
        const postUrl = panel.dataset.chatPostUrl || '';
        const text = textarea.value.trim();
        const replyToId = panel.dataset.replyToId || '';
        const isReply = Boolean(replyToId);

        setFormError(panel, '');

        if (!text) {
            setFormError(panel, isReply ? 'Escribe una respuesta antes de enviar.' : 'Escribe un comentario antes de enviar.');
            return;
        }

        if (!salaId || !postUrl) {
            setFormError(panel, 'No se pudo identificar la conversación del curso.');
            return;
        }

        if (isReply && !replyToId) {
            setFormError(panel, 'No se pudo identificar el mensaje que estás respondiendo.');
            return;
        }

        const tempId = `temp-${Date.now()}`;
        const role = panel.dataset.userRole || '';
        const replyReference = replyToId ? {
            id: replyToId,
            nombre_usuario: panel.dataset.replyToName || 'Usuario',
            rol_usuario: panel.dataset.replyToRole || '',
            mensaje: panel.dataset.replyToPreview || '',
        } : null;
        const tempMessage = {
            id: tempId,
            nombre_usuario: panel.dataset.userName || 'Tú',
            rol_usuario: role,
            mensaje: text,
            fecha: new Date().toISOString(),
            es_propio: true,
            estado_envio: 'Enviando...',
            parent_id: replyToId || null,
            referencia: replyReference,
        };

        const emptyState = messages.querySelector('.conversation-empty-state');
        emptyState?.remove();

        messages.insertAdjacentHTML('beforeend', messageTemplate(tempMessage, panel));
        const tempElement = messages.querySelector(`[data-chat-message-id="${tempId}"]`);
        textarea.value = '';
        if (isReply) {
            clearReplyMode(panel);
        }
        scrollToBottom(messages);

        button.disabled = true;
        textarea.disabled = true;
        panel._chatSendingInFlight = true;

        try {
            const response = await fetch(postUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    mensaje: text,
                    mensaje_padre_id: replyToId || null,
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.message || (isReply
                    ? 'No se pudo publicar la respuesta. Intenta nuevamente.'
                    : 'No se pudo publicar el comentario. Intenta nuevamente.'));
            }

            const confirmed = readMessage(data.message, {
                ...tempMessage,
                estado_envio: 'Enviado',
            });

            confirmed.estado_envio = 'Enviado';
            confirmed.referencia = confirmed.referencia || replyReference;

            if (tempElement) {
                replaceMessage(tempElement, confirmed, panel);
            }

            textarea.value = '';
            clearReplyMode(panel);
            updateCount(panel);
            scrollToBottom(messages);
        } catch (error) {
            if (tempElement) {
                const failed = {
                    ...tempMessage,
                    estado_envio: 'No enviado',
                };
                replaceMessage(tempElement, failed, panel);
            }

            setFormError(panel, error.message || (isReply
                ? 'No se pudo publicar la respuesta. Intenta nuevamente.'
                : 'No se pudo publicar el comentario. Intenta nuevamente.'));
            textarea.value = text;
            if (isReply) {
                panel.dataset.replyToId = replyToId;
                panel.dataset.replyToName = replyReference?.nombre_usuario || 'Usuario';
                panel.dataset.replyToRole = replyReference?.rol_usuario || '';
                panel.dataset.replyToPreview = replyReference?.mensaje || '';
                setReplyMode(panel, {
                    dataset: {
                        replyId: replyToId,
                        replyName: replyReference?.nombre_usuario || 'Usuario',
                        replyRole: replyReference?.rol_usuario || '',
                        replyPreview: replyReference?.mensaje || '',
                    },
                });
            }
        } finally {
            textarea.disabled = false;
            button.disabled = false;
            panel._chatSendingInFlight = false;
            textarea.focus();
        }
    });
}

function initPanel(panel) {
    const messages = panel.querySelector('[data-chat-messages]');
    const indicator = panel.querySelector('[data-chat-new-messages]');
    const loadPrevious = panel.querySelector('[data-chat-load-previous]');
    const deleteModal = panel.querySelector('[data-chat-delete-modal]');
    const deleteCancel = panel.querySelector('[data-chat-delete-cancel]');
    const deleteConfirm = panel.querySelector('[data-chat-delete-confirm]');

    if (!messages || panel.dataset.chatInitialized === '1') {
        return;
    }

    panel.dataset.chatInitialized = '1';
    collectExistingMessages(panel);
    initComposer(panel);
    startPolling(panel);

    indicator?.addEventListener('click', () => {
        scrollToBottom(messages);
        hideNewMessagesIndicator(panel);
    });

    loadPrevious?.addEventListener('click', () => loadPreviousMessages(panel));
    updatePreviousButton(panel);

    deleteCancel?.addEventListener('click', () => closeDeleteModal(panel));
    deleteConfirm?.addEventListener('click', () => deleteMessageOptimistically(panel));
    deleteModal?.addEventListener('click', (event) => {
        if (event.target === deleteModal) {
            closeDeleteModal(panel);
        }
    });

    messages.addEventListener('scroll', () => {
        if (isNearBottom(messages)) {
            hideNewMessagesIndicator(panel);
        }
    });
}

function extractParticipants(data) {
    if (!data || typeof data !== 'object') {
        return [];
    }

    if (Array.isArray(data.participants)) {
        return data.participants;
    }

    if (Array.isArray(data.participantes)) {
        return data.participantes;
    }

    if (Array.isArray(data.alumnos)) {
        return data.alumnos;
    }

    if (Array.isArray(data.data)) {
        return data.data;
    }

    return Array.isArray(data) ? data : [];
}

function normalizeParticipant(participant) {
    const data = participant && typeof participant === 'object' ? participant : {};
    const fullName = data.nombre
        ?? data.alumno
        ?? `${data.nombres ?? ''} ${data.apellidos ?? ''}`.trim()
        ?? 'Participante';

    return {
        id: data.id ?? data.alumno_id ?? data.usuario_id ?? '',
        nombre: String(fullName || 'Participante').trim() || 'Participante',
        correo: String(data.correo ?? data.correo_personal ?? data.email ?? data.correo_corporativo ?? '').trim().toLowerCase(),
        fotoUrl: String(data.foto_url ?? data.fotoUrl ?? data.avatar_url ?? data.photo_url ?? '').trim(),
        contactStatus: normalizeContactStatus(data.contact_status ?? data.contactStatus),
        contactStatusLabel: String(data.contact_status_label || data.contactStatusLabel || contactStatusLabel(data.contact_status ?? data.contactStatus)).trim(),
    };
}

function buildParticipantUrl(template, correo) {
    if (!template || !correo) {
        return '';
    }

    return template.replace('__CORREO__', encodeURIComponent(correo));
}

function normalizeContactStatus(status) {
    const value = String(status || '').trim().toLowerCase();
    return ['available', 'private', 'pending'].includes(value) ? value : 'private';
}

function contactStatusLabel(status) {
    switch (normalizeContactStatus(status)) {
    case 'available':
        return 'Contacto disponible';
    case 'pending':
        return 'Solicitud enviada';
    default:
        return 'Contacto privado';
    }
}

function contactStatusIcon(status) {
    switch (normalizeContactStatus(status)) {
    case 'available':
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5v-9Z"/><path d="M8 10h3"/><path d="M8 14h8"/></svg>';
    case 'pending':
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5v-9Z"/><path d="m5 8 7 5 7-5"/></svg>';
    default:
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2"/><path d="M6.5 10h11A1.5 1.5 0 0 1 19 11.5v6A1.5 1.5 0 0 1 17.5 19h-11A1.5 1.5 0 0 1 5 17.5v-6A1.5 1.5 0 0 1 6.5 10Z"/></svg>';
    }
}

function participantTemplate(participant, panel = null) {
    const item = normalizeParticipant(participant);
    const profileUrl = buildParticipantUrl(panel?.dataset.participantProfileUrlTemplate || '', item.correo);
    const status = normalizeContactStatus(item.contactStatus);
    const statusLabel = item.contactStatusLabel || contactStatusLabel(item.contactStatus);

    return `
        <button type="button"
                class="community-participant-item"
                data-participant-name="${escapeHtml(item.nombre.toLowerCase())}"
                data-participant-email="${escapeHtml(item.correo)}"
                data-participant-profile-url="${escapeHtml(profileUrl)}"
                ${profileUrl ? '' : 'disabled'}>
            <div class="community-participant-avatar">${avatarHtml(item.nombre, item.fotoUrl, item.correo, panel)}</div>
            <div class="community-participant-info">
                <div>
                    <strong>${escapeHtml(item.nombre)}</strong>
                </div>
            </div>
            <span class="community-participant-contact-status contact-status-${escapeHtml(status)}"
                  data-contact-status="${escapeHtml(status)}"
                  title="${escapeHtml(statusLabel)}"
                  aria-label="${escapeHtml(statusLabel)}">
                ${contactStatusIcon(status)}
            </span>
        </button>
    `;
}

function setParticipantsStatus(panel, message = '') {
    const status = panel.querySelector('[data-participants-status]');

    if (!status) {
        return;
    }

    status.textContent = message;
    status.hidden = message === '';
}

function renderParticipants(panel, participants) {
    const list = panel.querySelector('[data-participants-list]');
    const empty = panel.querySelector('[data-participants-empty]');
    const search = panel.querySelector('[data-participants-search]');

    if (!list || !empty) {
        return;
    }

    list.innerHTML = participants.map((participant) => participantTemplate(participant, panel)).join('');
    empty.textContent = 'Aún no hay participantes para mostrar.';
    empty.hidden = participants.length > 0;

    if (search) {
        search.disabled = participants.length === 0;
    }
}

function filterParticipants(panel) {
    const search = panel.querySelector('[data-participants-search]');
    const list = panel.querySelector('[data-participants-list]');
    const empty = panel.querySelector('[data-participants-empty]');

    if (!search || !list || !empty) {
        return;
    }

    const query = search.value.trim().toLowerCase();
    let visible = 0;

    list.querySelectorAll('[data-participant-name]').forEach((item) => {
        const matches = item.dataset.participantName.includes(query);
        item.hidden = !matches;

        if (matches) {
            visible += 1;
        }
    });

    empty.textContent = query ? 'No se encontraron participantes.' : 'Aún no hay participantes para mostrar.';
    empty.hidden = visible > 0;
}

function setParticipantProfileStatus(panel, message = '') {
    const status = panel.querySelector('[data-participant-profile-status]');

    if (!status) {
        return;
    }

    status.textContent = message;
    status.hidden = message === '';
}

function showParticipantsList(panel) {
    const listView = panel.querySelector('[data-participants-list-view]');
    const profileView = panel.querySelector('[data-participant-profile-view]');

    if (listView) {
        listView.hidden = false;
    }

    if (profileView) {
        profileView.hidden = true;
    }

    setParticipantProfileStatus(panel, '');
}

function showParticipantProfile(panel) {
    const listView = panel.querySelector('[data-participants-list-view]');
    const profileView = panel.querySelector('[data-participant-profile-view]');

    if (listView) {
        listView.hidden = true;
    }

    if (profileView) {
        profileView.hidden = false;
    }
}

function normalizePublicProfile(data) {
    const profile = data?.participant ?? data?.participante ?? data?.alumno ?? data?.data ?? data ?? {};
    const contact = profile.contacto && typeof profile.contacto === 'object' ? profile.contacto : {};
    const name = String(profile.nombre_completo || `${profile.nombres ?? ''} ${profile.apellidos ?? ''}`.trim() || 'Participante').trim();

    return {
        correo: String(profile.correo || contact.correo || '').trim(),
        nombre: name || 'Participante',
        iniciales: String(profile.iniciales || initialsFor(name || 'Participante')).trim(),
        fotoUrl: String(profile.foto_url || '').trim(),
        presentacion: String(profile.presentacion_profesional || '').trim(),
        cvUrl: String(profile.cv_url || '').trim(),
        puedeVerContacto: Boolean(profile.puede_ver_contacto ?? Number(profile.contacto_publico || 0) === 1),
        esPropio: Boolean(profile.es_propio),
        solicitudContactoEstado: String(profile.solicitud_contacto_estado || '').trim().toUpperCase(),
        contacto: {
            correo: String(contact.correo || profile.correo || '').trim(),
            correo_corporativo: String(contact.correo_corporativo || profile.correo_corporativo || '').trim(),
            telefono: String(contact.telefono || profile.telefono || '').trim(),
            linkedin_url: String(contact.linkedin_url || profile.linkedin_url || '').trim(),
        },
    };
}

function contactRow(label, value, href = '') {
    if (!value) {
        return '';
    }

    const content = href
        ? `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">${escapeHtml(value)}</a>`
        : `<span>${escapeHtml(value)}</span>`;

    return `
        <div class="community-participant-profile-row">
            <span>${escapeHtml(label)}</span>
            ${content}
        </div>
    `;
}

function isRenderableImageUrl(url) {
    return /^(https?:\/\/|data:image\/|\/)/i.test(String(url || '').trim());
}

function renderParticipantProfile(panel, payload, correo) {
    const content = panel.querySelector('[data-participant-profile-content]');

    if (!content) {
        return;
    }

    const profile = normalizePublicProfile(payload);
    const cvTemplate = panel.dataset.participantCvUrlTemplate || '';
    const cvHref = profile.cvUrl ? buildParticipantUrl(cvTemplate, correo || profile.correo) : '';
    const avatar = avatarHtml(profile.nombre, profile.fotoUrl, correo || profile.correo, panel);
    const presentation = profile.presentacion || 'Aun no ha registrado su presentacion profesional.';
    const requestTemplate = panel.dataset.participantContactRequestUrlTemplate || '';
    const requestUrl = buildParticipantUrl(requestTemplate, correo || profile.correo);
    const hasPendingRequest = profile.solicitudContactoEstado === 'PENDIENTE';
    const hasRejectedRequest = profile.solicitudContactoEstado === 'RECHAZADA';
    const canRequestContact = !profile.puedeVerContacto && !profile.esPropio && !hasPendingRequest && !hasRejectedRequest && requestUrl;
    const contactHtml = profile.puedeVerContacto
        ? `
            <div class="community-participant-profile-section">
                <h5>Contacto</h5>
                ${contactRow('Correo personal', profile.contacto.correo, profile.contacto.correo ? `mailto:${profile.contacto.correo}` : '')}
                ${contactRow('Correo corporativo', profile.contacto.correo_corporativo, profile.contacto.correo_corporativo ? `mailto:${profile.contacto.correo_corporativo}` : '')}
                ${contactRow('Telefono', profile.contacto.telefono, profile.contacto.telefono ? `tel:${profile.contacto.telefono}` : '')}
                ${contactRow('LinkedIn', profile.contacto.linkedin_url, profile.contacto.linkedin_url)}
            </div>
        `
        : `
            <div class="community-participant-profile-section">
                <h5>Datos de contacto</h5>
                <div class="community-participant-profile-private">
                    <strong>Datos privados</strong>
                    <span>Este alumno mantiene sus datos de contacto privados.</span>
                </div>
                <div class="community-participant-contact-request" data-contact-request-state>
                    ${hasPendingRequest
                        ? '<span class="community-participant-contact-pending">Solicitud pendiente</span>'
                        : ''}
                    ${hasRejectedRequest
                        ? '<span class="community-participant-contact-pending">Solicitud no aprobada</span>'
                        : ''}
                    ${canRequestContact
                        ? `<button type="button"
                                   class="participant-contact-request-button"
                                   data-request-contact-button
                                   data-request-contact-url="${escapeHtml(requestUrl)}"
                                   data-participant-email="${escapeHtml(profile.correo)}">
                                Solicitar datos de contacto
                           </button>`
                        : ''}
                </div>
                <p class="community-participant-contact-message" data-contact-request-message hidden></p>
            </div>
        `;

    content.innerHTML = `
        <article class="community-participant-profile-card">
            <div class="community-participant-profile-head">
                <div class="community-participant-profile-avatar">${avatar}</div>
                <div>
                    <h4>${escapeHtml(profile.nombre)}</h4>
                    <span>Participante del curso</span>
                </div>
            </div>

            <div class="community-participant-profile-section">
                <h5>Presentacion profesional</h5>
                <p>${escapeHtml(presentation)}</p>
            </div>

            <div class="community-participant-profile-section">
                <h5>CV</h5>
                ${profile.cvUrl && cvHref
                    ? `<a class="community-participant-profile-cv" href="${escapeHtml(cvHref)}" target="_blank" rel="noopener noreferrer">Ver CV</a>`
                    : '<p>No registrado.</p>'}
            </div>

            ${contactHtml}
        </article>
    `;
}

async function loadParticipantProfile(panel, trigger) {
    const url = trigger.dataset.participantProfileUrl || '';
    const correo = trigger.dataset.participantEmail || '';

    if (!url || !correo || panel._participantProfileLoading) {
        return;
    }

    panel._participantProfileLoading = true;
    showParticipantProfile(panel);
    setParticipantProfileStatus(panel, 'Cargando perfil...');

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'No se pudo cargar el perfil del participante.');
        }

        renderParticipantProfile(panel, data, correo);
        setParticipantProfileStatus(panel, '');
    } catch (error) {
        setParticipantProfileStatus(panel, error.message || 'No se pudo cargar el perfil del participante.');
    } finally {
        panel._participantProfileLoading = false;
    }
}

function setContactRequestState(container, message = '') {
    container.innerHTML = '<span class="community-participant-contact-pending">Solicitud pendiente</span>';

    const wrapper = container.closest('.community-participant-profile-section');
    const messageBox = wrapper?.querySelector('[data-contact-request-message]');

    if (messageBox && message) {
        messageBox.textContent = message;
        messageBox.hidden = false;
    }
}

async function submitContactRequest(button) {
    const url = button.dataset.requestContactUrl || '';
    const container = button.closest('[data-contact-request-state]');
    const wrapper = button.closest('.community-participant-profile-section');
    const messageBox = wrapper?.querySelector('[data-contact-request-message]');
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    if (!url || !container) {
        return;
    }

    button.disabled = true;
    button.textContent = 'Enviando solicitud...';
    messageBox && (messageBox.hidden = true);

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({
                mensaje: 'Hola, me gustaria acceder a tus datos de contacto.',
            }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.ok === false) {
            throw new Error(data.message || 'No se pudo enviar la solicitud. Intenta nuevamente.');
        }

        setContactRequestState(container, data.message || 'Solicitud enviada correctamente.');
    } catch (error) {
        button.disabled = false;
        button.textContent = 'Solicitar datos de contacto';

        if (messageBox) {
            messageBox.textContent = error.message || 'No se pudo enviar la solicitud. Intenta nuevamente.';
            messageBox.hidden = false;
        }
    }
}

async function loadParticipants(panel) {
    const url = panel.dataset.participantsUrl || '';
    const courseEditionId = panel.dataset.courseEditionId || '';
    const list = panel.querySelector('[data-participants-list]');
    const empty = panel.querySelector('[data-participants-empty]');
    const retry = panel.querySelector('[data-participants-retry]');
    const search = panel.querySelector('[data-participants-search]');

    if (panel.dataset.participantsLoaded === 'true' || panel._participantsLoading) {
        return;
    }

    if (!url || !courseEditionId) {
        setParticipantsStatus(panel, 'No se pudo identificar el curso.');
        retry?.removeAttribute('hidden');
        return;
    }

    panel._participantsLoading = true;
    list && (list.innerHTML = '');
    empty && (empty.hidden = true);
    retry && (retry.hidden = true);
    search && (search.disabled = true);
    setParticipantsStatus(panel, 'Cargando participantes...');

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'No se pudieron cargar los participantes. Intenta nuevamente.');
        }

        const participants = extractParticipants(data).map(normalizeParticipant);
        panel._participants = participants;
        panel.dataset.participantsLoaded = 'true';
        updateCommunityParticipantsCount(panel, participants.length);
        renderParticipants(panel, participants);
        setParticipantsStatus(panel, '');
    } catch (error) {
        panel.dataset.participantsLoaded = 'false';
        setParticipantsStatus(panel, error.message || 'No se pudieron cargar los participantes. Intenta nuevamente.');
        retry?.removeAttribute('hidden');
    } finally {
        panel._participantsLoading = false;
    }
}

function initCommunityPanel(panel) {
    if (panel.dataset.communityInitialized === '1') {
        return;
    }

    const tabs = [...panel.querySelectorAll('[data-community-tab]')];
    const contents = [...panel.querySelectorAll('[data-community-panel-content]')];
    const participantsPanel = panel.querySelector('[data-participants-panel]');
    const participantsSearch = panel.querySelector('[data-participants-search]');
    const participantsRetry = panel.querySelector('[data-participants-retry]');
    const participantsList = panel.querySelector('[data-participants-list]');
    const backToParticipants = panel.querySelector('[data-back-to-participants]');

    if (!tabs.length || !contents.length) {
        return;
    }

    panel.dataset.communityInitialized = '1';

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.communityTab || '';

            tabs.forEach((currentTab) => {
                const isActive = currentTab === tab;
                currentTab.classList.toggle('is-active', isActive);
                currentTab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            contents.forEach((content) => {
                const isActive = content.dataset.communityPanelContent === target;
                content.classList.toggle('is-active', isActive);
                content.hidden = !isActive;
            });

            if (target === 'participants' && participantsPanel) {
                loadParticipants(participantsPanel);
            }
        });
    });

    participantsSearch?.addEventListener('input', () => filterParticipants(participantsPanel));
    participantsRetry?.addEventListener('click', () => loadParticipants(participantsPanel));
    participantsList?.addEventListener('click', (event) => {
        const item = event.target.closest('[data-participant-profile-url]');

        if (!participantsPanel || !item) {
            return;
        }

        loadParticipantProfile(participantsPanel, item);
    });
    backToParticipants?.addEventListener('click', () => {
        if (participantsPanel) {
            showParticipantsList(participantsPanel);
        }
    });
    participantsPanel?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-request-contact-button]');

        if (button) {
            submitContactRequest(button);
        }
    });
}

function initCommunityPanels(root = document) {
    root.querySelectorAll('[data-community-panel]').forEach(initCommunityPanel);

    const panels = [...root.querySelectorAll('.session-conversation-panel')];

    panels.forEach(initPanel);
    refreshRelativeTimes();

    window.addEventListener('pagehide', () => panels.forEach(stopPolling));
    window.addEventListener('beforeunload', () => panels.forEach(stopPolling));
}

window.initCommunityPanels = initCommunityPanels;
document.addEventListener('DOMContentLoaded', () => initCommunityPanels());
window.setInterval(refreshRelativeTimes, 30000);
