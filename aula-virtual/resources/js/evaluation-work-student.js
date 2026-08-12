import './global.js';

let studentWorkConfig = {
    saveUrl: '',
    finalizeUrl: '',
    downloadUrlTemplate: '',
    allowedExtensions: [],
    maxFileSizeMb: 50,
    csrfToken: '',
};

const state = {
    evaluation: {},
    work: {},
    delivery: {},
    pendingFiles: [],
    removedSavedFiles: [],
    saving: false,
};

function init() {
    hydrateStudentWorkState();
    bindEvents();
    render();
}

function bindEvents() {
    document.getElementById('studentWorkFiles')?.addEventListener('change', handleFileSelection);
    document.getElementById('studentWorkSaveBtn')?.addEventListener('click', () => saveDraft());
    document.getElementById('studentWorkFinalizeBtn')?.addEventListener('click', () => finalizeSubmission());
    document.getElementById('studentWorkNextStepAction')?.addEventListener('click', handleNextStepAction);

    document.addEventListener('click', (event) => {
        const removePendingIndex = event.target.dataset.removePendingIndex;
        const removeSavedId = event.target.dataset.removeSavedId;
        const undoSavedId = event.target.dataset.undoSavedId;

        if (removePendingIndex !== undefined) {
            state.pendingFiles.splice(Number(removePendingIndex), 1);
            syncFileInput();
            render();
            return;
        }

        if (removeSavedId !== undefined) {
            queueSavedFileRemoval(Number(removeSavedId));
            return;
        }

        if (undoSavedId !== undefined) {
            undoSavedFileRemoval(Number(undoSavedId));
        }
    });
}

function render() {
    renderStatus();
    renderFiles();
    renderNextStep();
    renderReadonlyState();
}

function renderStatus() {
    const delivery = state.delivery || {};
    const alert = document.getElementById('studentWorkAlert');
    const statusText = document.getElementById('studentWorkStatusText');
    const statusBadge = document.getElementById('studentWorkStatusBadge');
    const fileCounter = document.getElementById('studentWorkFileCounter');
    const submittedAt = document.getElementById('studentWorkSubmittedAt');
    const activeFileCount = getActiveFileCount();
    const maxFiles = getMaxFiles();
    const rawStatus = String(delivery.estado || delivery.status || delivery.entrega_estado || delivery.rendicion_estado || '').toLowerCase().trim();
    const isDelivered = Boolean(delivery.finalizada)
        || ['finalizado', 'finalizada', 'entregado', 'entregada', 'presentado', 'presentada', 'corregido', 'corregida', 'calificado', 'calificada', 'evaluado', 'evaluada'].includes(rawStatus);
    const isReviewing = ['revisando', 'reviewing', 'en_revision', 'revision', 'revisión'].includes(rawStatus);

    if (statusText) {
        if (isDelivered) {
            statusText.textContent = 'Entrega finalizada';
        } else if (delivery.fuera_de_plazo) {
            statusText.textContent = 'Fuera de plazo';
        } else if (delivery.puede_editar) {
            statusText.textContent = 'Aun no enviado';
        } else if (isReviewing) {
            statusText.textContent = 'En revisión';
        } else {
            statusText.textContent = 'Solo lectura';
        }
    }

    if (statusBadge) {
        statusBadge.className = 'rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-wide';

        if (isDelivered) {
            statusBadge.classList.add('bg-emerald-100', 'text-emerald-700');
            statusBadge.textContent = 'Finalizada';
        } else if (delivery.fuera_de_plazo) {
            statusBadge.classList.add('bg-rose-100', 'text-rose-700');
            statusBadge.textContent = 'Vencida';
        } else if (delivery.puede_editar) {
            statusBadge.classList.add('bg-amber-100', 'text-amber-700');
            statusBadge.textContent = 'Editable';
        } else if (isReviewing) {
            statusBadge.classList.add('bg-indigo-100', 'text-indigo-700');
            statusBadge.textContent = 'En revisión';
        } else {
            statusBadge.classList.add('bg-slate-200', 'text-slate-700');
            statusBadge.textContent = 'Bloqueada';
        }
    }

    if (fileCounter) {
        fileCounter.textContent = `${activeFileCount} / ${maxFiles}`;
    }

    if (submittedAt) {
        if (delivery.fecha_entrega) {
            submittedAt.textContent = delivery.fecha_entrega;
        } else if (isDelivered) {
            submittedAt.textContent = 'Entregado';
        } else if (delivery.fuera_de_plazo) {
            submittedAt.textContent = 'Plazo vencido';
        } else if (isReviewing) {
            submittedAt.textContent = 'En revisión';
        } else {
            submittedAt.textContent = 'Todavia no enviaste tu entrega';
        }
    }

    if (!alert) return;

    alert.className = 'hidden rounded-2xl border px-4 py-3 text-sm';

    if (isDelivered) {
        showAlert('Tu entrega ya fue finalizada. Todo el contenido queda en solo lectura.', 'success');
    }
}

function renderFiles() {
    const filesList = document.getElementById('studentWorkFilesList');
    const removedList = document.getElementById('studentWorkRemovedList');

    if (filesList) {
        const savedFilesMarkup = getActiveSavedFiles().map((file) => renderSavedFile(file)).join('');
        const pendingFilesMarkup = state.pendingFiles.map((file, index) => renderPendingFile(file, index)).join('');

        filesList.innerHTML = savedFilesMarkup || pendingFilesMarkup
            ? `${savedFilesMarkup}${pendingFilesMarkup}`
            : `
                <div class="rounded-2xl border border-dashed border-slate-300 px-4 py-5 text-sm text-slate-500">
                    Todavia no agregaste archivos. Sube al menos uno para enviar tu trabajo.
                </div>
            `;
    }

    if (removedList) {
        removedList.innerHTML = '';
    }
}

function renderNextStep() {
    const title = document.getElementById('studentWorkNextStepTitle');
    const text = document.getElementById('studentWorkNextStepText');
    const action = document.getElementById('studentWorkNextStepAction');

    if (!title || !text || !action) {
        return;
    }

    const delivery = state.delivery || {};
    const activeFileCount = getActiveFileCount();
    const editable = canEdit();
    const rawStatus = String(delivery.estado || delivery.status || delivery.entrega_estado || delivery.rendicion_estado || '').toLowerCase().trim();
    const isDelivered = Boolean(delivery.finalizada)
        || ['finalizado', 'finalizada', 'entregado', 'entregada', 'presentado', 'presentada', 'corregido', 'corregida', 'calificado', 'calificada', 'evaluado', 'evaluada'].includes(rawStatus);

    action.className = 'mt-3 inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition';

    if (isDelivered) {
        title.textContent = 'Entrega enviada';
        text.textContent = 'Tu trabajo quedo en solo lectura. Puedes revisar los archivos enviados y esperar la revision.';
        action.textContent = 'Entrega cerrada';
        action.disabled = true;
        action.classList.add('cursor-not-allowed', 'bg-slate-200', 'text-slate-600');
        return;
    }

    if (delivery.fuera_de_plazo || !editable) {
        title.textContent = delivery.fuera_de_plazo ? 'El plazo ya vencio' : 'Entrega en solo lectura';
        text.textContent = delivery.fuera_de_plazo
            ? 'La fecha limite paso y ya no puedes modificar esta entrega.'
            : 'Esta entrega no admite cambios en este momento.';
        action.textContent = 'No disponible';
        action.disabled = true;
        action.classList.add('cursor-not-allowed', 'bg-slate-200', 'text-slate-600');
        return;
    }

    if (activeFileCount <= 0) {
        title.textContent = 'Agrega al menos un archivo';
        text.textContent = 'Puedes subir documentos, imagenes, archivos comprimidos, JSON o YAML.';
        action.textContent = 'Agregar archivos';
        action.disabled = state.saving;
        action.classList.add('bg-indigo-600', 'text-white', 'hover:bg-indigo-700');
        return;
    }

    title.textContent = 'Revisa y envia tu trabajo';
    text.textContent = 'Tienes archivos adjuntos. Guarda tus cambios o envia la entrega cuando estes seguro.';
    action.textContent = 'Enviar entrega';
    action.disabled = state.saving;
    action.classList.add('bg-indigo-600', 'text-white', 'hover:bg-indigo-700');
}

function renderReadonlyState() {
    const editable = canEdit();
    const activeFileCount = getActiveFileCount();
    const observation = document.getElementById('studentWorkObservation');
    const saveBtn = document.getElementById('studentWorkSaveBtn');
    const finalizeBtn = document.getElementById('studentWorkFinalizeBtn');
    const fileInput = document.getElementById('studentWorkFiles');
    const fileTrigger = document.getElementById('studentWorkFileTrigger');
    const deadlineNote = document.getElementById('studentWorkDeadlineNote');
    const blockedNotice = document.getElementById('studentWorkBlockedNotice');

    if (observation) {
        observation.disabled = !editable || state.saving;
        observation.classList.toggle('bg-slate-100', observation.disabled);
        observation.classList.toggle('cursor-not-allowed', observation.disabled);
    }

    if (saveBtn) {
        saveBtn.disabled = !editable || state.saving;
        saveBtn.classList.toggle('opacity-50', saveBtn.disabled);
        saveBtn.classList.toggle('cursor-not-allowed', saveBtn.disabled);

        if (saveBtn.disabled) {
            saveBtn.classList.add('bg-slate-300', 'text-slate-600');
            saveBtn.classList.remove('bg-indigo-600', 'text-white');
        } else {
            saveBtn.classList.remove('bg-slate-300', 'text-slate-600');
            saveBtn.classList.add('bg-indigo-600', 'text-white');
        }

        saveBtn.textContent = state.delivery?.fuera_de_plazo ? 'No disponible' : 'Guardar cambios';
    }

    if (finalizeBtn) {
        finalizeBtn.disabled = !editable || state.saving || activeFileCount <= 0;
        finalizeBtn.classList.toggle('opacity-50', finalizeBtn.disabled);
        finalizeBtn.classList.toggle('cursor-not-allowed', finalizeBtn.disabled);

        if (finalizeBtn.disabled) {
            finalizeBtn.classList.add('bg-slate-100', 'text-slate-500');
            finalizeBtn.classList.remove('text-slate-800');
        } else {
            finalizeBtn.classList.remove('bg-slate-100', 'text-slate-500');
            finalizeBtn.classList.add('text-slate-800');
        }

        if (state.delivery?.fuera_de_plazo) {
            finalizeBtn.textContent = 'No disponible';
        } else if (activeFileCount <= 0) {
            finalizeBtn.textContent = 'Adjunta un archivo para enviar';
        } else {
            finalizeBtn.textContent = 'Enviar entrega';
        }
    }

    if (fileInput) {
        fileInput.disabled = !editable || state.saving || getRemainingSlots() <= 0;
    }

    if (fileTrigger) {
        fileTrigger.classList.toggle('pointer-events-none', !editable || state.saving || getRemainingSlots() <= 0);
        fileTrigger.classList.toggle('opacity-50', !editable || state.saving || getRemainingSlots() <= 0);
    }

    if (deadlineNote) {
        deadlineNote.classList.toggle('hidden', !state.delivery?.fuera_de_plazo);
    }

    if (blockedNotice) {
        blockedNotice.classList.toggle('hidden', !state.delivery?.fuera_de_plazo);
    }
}

function handleNextStepAction() {
    if (!canEdit() || state.saving) {
        return;
    }

    if (getActiveFileCount() <= 0) {
        document.getElementById('studentWorkFiles')?.click();
        return;
    }

    finalizeSubmission();
}

function renderSavedFile(file) {
    return `
        <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
            <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-900 break-all">${escapeHtml(file.nombre_original || 'Archivo')}</div>
                <div class="mt-1 text-xs text-slate-500">${formatFileMeta(file)}</div>
            </div>
            <div class="mt-3 flex justify-end gap-2">
                <a
                    href="${getLocalDownloadUrl(file.archivo_id)}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center justify-center rounded-full border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-100">
                    Descargar
                </a>
                ${canEdit() ? `
                    <button
                        type="button"
                        data-remove-saved-id="${file.archivo_id}"
                        class="inline-flex items-center justify-center rounded-full border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50">
                        Quitar
                    </button>
                ` : ''}
            </div>
        </div>
    `;
}

function renderPendingFile(file, index) {
    return `
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50/70 px-4 py-3">
            <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-900 break-all">${escapeHtml(file.name)}</div>
                <div class="mt-1 text-xs text-slate-500">${formatBytes(file.size || 0)} · Se subira al guardar</div>
            </div>
            <div class="mt-3 flex justify-end">
                <button
                    type="button"
                    data-remove-pending-index="${index}"
                    class="inline-flex shrink-0 items-center justify-center rounded-full border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-white">
                    Quitar
                </button>
            </div>
        </div>
    `;
}

function handleFileSelection(event) {
    const files = Array.from(event.target.files || []);
    const remainingSlots = getRemainingSlots();

    if (!files.length) {
        return;
    }

    const acceptedFiles = [];
    const rejectedMessages = [];
    const maxBytes = getMaxFileBytes();

    files.forEach((file) => {
        const extension = getFileExtension(file.name);

        if (!isAllowedExtension(extension)) {
            rejectedMessages.push(`${file.name}: formato no permitido`);
            return;
        }

        if ((file.size || 0) > maxBytes) {
            rejectedMessages.push(`${file.name}: supera ${getMaxFileSizeMb()} MB`);
            return;
        }

        acceptedFiles.push(file);
    });

    if (rejectedMessages.length) {
        showAlert(rejectedMessages.join('. '), 'error');
    }

    if (!acceptedFiles.length) {
        syncFileInput();
        render();
        return;
    }

    if (acceptedFiles.length > remainingSlots) {
        showAlert(`Solo puedes mantener hasta ${getMaxFiles()} archivos activos en total.`, 'error');
    }

    state.pendingFiles = state.pendingFiles.concat(acceptedFiles.slice(0, remainingSlots));
    syncFileInput();
    render();
}

async function queueSavedFileRemoval(fileId) {
    if (!canEdit() || state.saving) {
        return;
    }

    const numericFileId = Number(fileId);
    const file = getActiveSavedFiles().find((item) => Number(item.archivo_id) === numericFileId);

    if (!file) {
        return;
    }

    state.removedSavedFiles.push(file);
    render();

    const removedSnapshot = [...state.removedSavedFiles];
    const saved = await saveDraft();

    if (!saved) {
        state.removedSavedFiles = removedSnapshot.filter((item) => Number(item.archivo_id) !== numericFileId);
        render();
    }
}

function undoSavedFileRemoval(fileId) {
    state.removedSavedFiles = state.removedSavedFiles.filter((item) => Number(item.archivo_id) !== Number(fileId));
    render();
}

async function saveDraft() {
    if (!canEdit() || state.saving) {
        return false;
    }

    state.saving = true;
    renderNextStep();
    renderReadonlyState();

    const formData = new FormData();
    formData.append('observacion_alumno', document.getElementById('studentWorkObservation')?.value || '');

    state.pendingFiles.forEach((file) => {
        formData.append('archivos[]', file);
    });

    state.removedSavedFiles.forEach((file) => {
        formData.append('archivos_eliminar[]', String(file.archivo_id));
    });

    try {
        const response = await fetch(studentWorkConfig.saveUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': studentWorkConfig.csrfToken,
            },
            body: formData,
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'No se pudo guardar la entrega');
        }

        replaceDelivery(data.entrega);
        showAlert(data.message || 'Cambios guardados correctamente', 'success');
        return true;
    } catch (error) {
        showAlert(error.message || 'No se pudo guardar la entrega', 'error');
        return false;
    } finally {
        state.saving = false;
        render();
    }
}

async function finalizeSubmission() {
    if (!canEdit() || state.saving) {
        return;
    }

    const currentObservation = document.getElementById('studentWorkObservation')?.value || '';
    const totalFiles = getActiveFileCount();

    if (totalFiles <= 0) {
        showAlert('Debes adjuntar al menos 1 archivo para enviar la entrega.', 'error');
        return;
    }

    const confirmed = typeof window.confirmAction === 'function'
        ? await window.confirmAction({
            title: 'Enviar entrega',
            message: 'Cuando envies, la entrega quedara en solo lectura.',
            confirmText: 'Enviar'
        })
        : window.confirm('Cuando envies, la entrega quedara en solo lectura. Deseas continuar?');

    if (!confirmed) {
        return;
    }

    if (state.pendingFiles.length || state.removedSavedFiles.length) {
        const saved = await saveDraft();

        if (!saved) {
            return;
        }
    }

    state.saving = true;
    renderNextStep();
    renderReadonlyState();

    try {
        const response = await fetch(studentWorkConfig.finalizeUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': studentWorkConfig.csrfToken,
            },
            body: JSON.stringify({
                observacion_alumno: currentObservation,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'No se pudo finalizar la entrega');
        }

        replaceDelivery(data.entrega);
        showAlert(data.message || 'Entrega enviada correctamente', 'success');
    } catch (error) {
        showAlert(error.message || 'No se pudo finalizar la entrega', 'error');
    } finally {
        state.saving = false;
        render();
    }
}

function replaceDelivery(delivery) {
    state.delivery = delivery || {};
    state.pendingFiles = [];
    state.removedSavedFiles = [];
    syncFileInput();

    const observation = document.getElementById('studentWorkObservation');
    if (observation) {
        observation.value = state.delivery.observacion_alumno || '';
    }
}

function syncFileInput() {
    const input = document.getElementById('studentWorkFiles');

    if (!input) {
        return;
    }

    try {
        const transfer = new DataTransfer();
        state.pendingFiles.forEach((file) => transfer.items.add(file));
        input.files = transfer.files;
    } catch (error) {
        input.value = '';
    }
}

function getActiveSavedFiles() {
    const removedIds = new Set(state.removedSavedFiles.map((file) => Number(file.archivo_id)));
    return (state.delivery.archivos || []).filter((file) => !removedIds.has(Number(file.archivo_id)));
}

function getActiveFileCount() {
    return getActiveSavedFiles().length + state.pendingFiles.length;
}

function getRemainingSlots() {
    const maxFiles = getMaxFiles();
    return Math.max(0, maxFiles - getActiveFileCount());
}

function getMaxFiles() {
    return Number(state.delivery?.max_archivos || 5);
}

function getMaxFileSizeMb() {
    return Number(state.delivery?.max_file_size_mb || studentWorkConfig.maxFileSizeMb || 50);
}

function getMaxFileBytes() {
    return getMaxFileSizeMb() * 1024 * 1024;
}

function parseAllowedExtensions(value) {
    return String(value)
        .split(',')
        .map((extension) => extension.trim().toLowerCase().replace(/^\./, ''))
        .filter(Boolean);
}

function getAllowedExtensions() {
    return state.delivery?.allowed_extensions?.length
        ? state.delivery.allowed_extensions
        : (studentWorkConfig.allowedExtensions.length
            ? studentWorkConfig.allowedExtensions
            : ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'txt', 'csv', 'odt', 'ods', 'odp', 'json', 'yml', 'yaml']);
}

function getFileExtension(filename) {
    const parts = String(filename || '').split('.');
    return parts.length > 1 ? parts.pop().toLowerCase() : '';
}

function isAllowedExtension(extension) {
    return getAllowedExtensions().includes(String(extension || '').toLowerCase());
}

function getLocalDownloadUrl(attachmentId) {
    return studentWorkConfig.downloadUrlTemplate.replace('__ATTACHMENT__', String(attachmentId));
}

function hydrateStudentWorkState() {
    const context = document.getElementById('studentWorkContext');
    const payload = document.getElementById('studentWorkPayload');

    if (!context || !payload) {
        return;
    }

    studentWorkConfig = {
        saveUrl: context.dataset.saveUrl || '',
        finalizeUrl: context.dataset.finalizeUrl || '',
        downloadUrlTemplate: context.dataset.downloadUrlTemplate || '',
        allowedExtensions: parseAllowedExtensions(context.dataset.allowedExtensions || ''),
        maxFileSizeMb: Number(context.dataset.maxFileSizeMb || 50),
        csrfToken: context.dataset.csrfToken || '',
    };

    try {
        const data = JSON.parse(payload.innerHTML || '{}');
        state.evaluation = data.evaluacion || {};
        state.work = data.trabajo || {};
        state.delivery = data.entrega || {};
    } catch (error) {
        console.error('No se pudo leer la configuracion del trabajo del alumno', error);
        state.evaluation = {};
        state.work = {};
        state.delivery = {};
    }
}

function canEdit() {
    return Boolean(state.delivery?.puede_editar) && !Boolean(state.delivery?.finalizada);
}

function showAlert(message, type) {
    const alert = document.getElementById('studentWorkAlert');

    if (!alert) {
        return;
    }

    alert.textContent = message;
    alert.className = 'rounded-2xl border px-4 py-3 text-sm';

    if (type === 'success') {
        alert.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-700');
    } else if (type === 'error') {
        alert.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-700');
    } else {
        alert.classList.add('border-amber-200', 'bg-amber-50', 'text-amber-700');
    }
}

function formatFileMeta(file) {
    const parts = [];

    if (file.mime_type) {
        parts.push(file.mime_type);
    }

    if (file.peso_bytes !== undefined && file.peso_bytes !== null) {
        parts.push(formatBytes(Number(file.peso_bytes)));
    }

    return parts.join(' · ') || 'Archivo adjunto';
}

function formatBytes(bytes) {
    if (!bytes) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    const rounded = value >= 10 || unitIndex === 0 ? Math.round(value) : value.toFixed(1);
    return `${rounded} ${units[unitIndex]}`;
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

document.addEventListener('DOMContentLoaded', init);
