
function getErrorMessage(error, fallback) {
    if (!error) {
        return fallback;
    }

    if (typeof error === 'string') {
        return error;
    }

    return error.message || fallback;
}

async function parseJsonResponse(response) {
    const contentType = response.headers.get('content-type') || '';
    const text = await response.text();

    if (!text) {
        return { __json: contentType.includes('application/json') };
    }

    if (contentType.includes('application/json')) {
        try {
            const parsed = JSON.parse(text);

            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return { ...parsed, __json: true };
            }

            return { data: parsed, __json: true };
        } catch (error) {
            return {
                __json: false,
                error: `Respuesta JSON invalida del servidor (${response.status})`
            };
        }
    }

    const cleanedText = text
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    return {
        __json: false,
        error: cleanedText || `Respuesta no JSON del servidor (${response.status})`
    };
}

const VIDEO_CHAT_MAX_BYTES = 5 * 1024 * 1024;

function getSelectedVideoChatFile() {
    const input = document.getElementById('videoChatInput');
    return input?.files?.[0] || null;
}

function validateVideoChatFile(file) {
    if (!file) return null;

    const name = String(file.name || '').toLowerCase();
    if (!name.endsWith('.txt')) {
        return 'El chat de Zoom debe ser un archivo .txt.';
    }

    if (file.size <= 0 || file.size > VIDEO_CHAT_MAX_BYTES) {
        return 'El chat de Zoom no debe superar 5 MB.';
    }

    return null;
}

function updateVideoChatMeta(file) {
    const meta = document.getElementById('videoChatMeta');
    const uploadBtn = document.getElementById('uploadVideoChatBtn');

    if (!meta) return;

    if (!file) {
        meta.classList.add('hidden');
        meta.textContent = '';
        if (uploadBtn) uploadBtn.classList.add('hidden');
        return;
    }

    const sizeKb = Math.max(1, Math.round(file.size / 1024));
    meta.textContent = `${file.name} · ${sizeKb} KB`;
    meta.classList.remove('hidden');
    if (uploadBtn) uploadBtn.classList.remove('hidden');
}

async function uploadVideoChatTranscript(file, { courseId, sessionId, csrf }) {
    const error = validateVideoChatFile(file);
    if (error) {
        throw new Error(error);
    }

    const formData = new FormData();
    formData.append('chat', file, file.name);

    const response = await fetch(`/backoffice/courses/${courseId}/sessions/${sessionId}/video/chat`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json'
        },
        body: formData
    });

    const data = await parseJsonResponse(response);

    if (response.redirected || !data.__json) {
        throw new Error(getErrorMessage(data?.message || data?.error, 'No se pudo guardar el chat de Zoom.'));
    }

    if (!response.ok) {
        throw new Error(getErrorMessage(data?.message || data?.error, 'No se pudo guardar el chat de Zoom.'));
    }

    return data;
}

function ensureVideoChatModal() {
    let modal = document.getElementById('videoChatPreviewModal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'videoChatPreviewModal';
    modal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4';
    modal.innerHTML = `
        <div class="max-h-[86vh] w-full max-w-3xl overflow-hidden rounded-lg bg-white shadow-xl">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Chat de la clase</h2>
                    <p id="videoChatPreviewSubtitle" class="text-sm text-slate-600"></p>
                </div>
                <button type="button" class="btn-secondary" data-close-video-chat-preview>Cerrar</button>
            </div>
            <div id="videoChatPreviewContent" class="max-h-[68vh] overflow-auto bg-slate-50 p-5 text-sm text-slate-800"></div>
        </div>
    `;
    document.body.appendChild(modal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal || event.target.closest('[data-close-video-chat-preview]')) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    });

    return modal;
}

function renderVideoChatPreview(data) {
    const modal = ensureVideoChatModal();
    const subtitle = modal.querySelector('#videoChatPreviewSubtitle');
    const content = modal.querySelector('#videoChatPreviewContent');
    const messages = Array.isArray(data.messages) ? data.messages : [];

    if (subtitle) {
        subtitle.textContent = data.filename || 'chat-de-zoom.txt';
    }

    if (content) {
        if (messages.length > 0) {
            content.innerHTML = messages.map((message) => `
                <article class="mb-3 rounded-md border border-slate-200 bg-white p-3">
                    <div class="mb-1 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-600">
                        <span>${escapeHtml(message.time || '')}</span>
                        <span>${escapeHtml(message.participant || 'Participante')}</span>
                    </div>
                    <p class="whitespace-pre-wrap text-slate-900">${escapeHtml(message.message || '')}</p>
                </article>
            `).join('');
        } else {
            content.innerHTML = `<pre class="whitespace-pre-wrap rounded-md border border-slate-200 bg-white p-4">${escapeHtml(data.content || '')}</pre>`;
        }
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function getUploadProgress(sessionId) {
    try {
        const resp = await fetch(
            `/backoffice/sessions/${sessionId}/video/upload-progress`
        );

        const data = await resp.json().catch(() => null);

        if (!resp.ok) {
            const error = new Error(
                getErrorMessage(
                    data?.message || data?.error,
                    'No se pudo consultar el progreso de la subida'
                )
            );

            error.status = resp.status;
            error.payload = data;

            throw error;
        }

        if (!data || !data.upload_id) return null;

        return data;
    } catch (e) {
        console.error('Error obteniendo progreso', e);
        throw e;
    }
}

function formatUploadStatus(progress) {
    const status = String(progress?.status || '');

    if (['processing', 'uploaded', 'completed'].includes(status)) {
        return (
            '✔ Conexión establecida.\n' +
            '✔ Video subido correctamente.\n' +
            '⏳ Estamos dejando listo el video para reproducirse.\n' +
            'Ya no necesita esperar en esta página; en unos minutos estará disponible para los alumnos.'
        );
    }

    return (
        '✔ Conexión establecida\n' +
        '⏳ Subiendo video...\n' +
        'Si recargaste la página, selecciona el mismo archivo para reanudar la subida.'
    );
}

async function restoreUploadStateOnLoad() {
    const container = document.getElementById('videoUploadContainer');
    if (!container) {
        return { restored: false, failed: false };
    }

    const sessionId = container.dataset.sessionId;
    const statusEl = document.getElementById('videoStatus');
    const progressContainer = document.getElementById('uploadProgress');
    const progressBar = document.getElementById('progressBar');
    const cancelBtn = document.getElementById('cancelUploadBtn');

    if (!sessionId || !statusEl) {
        return { restored: false, failed: false };
    }

    let progress = null;

    try {
        progress = await getUploadProgress(sessionId);
    } catch (error) {
        if (statusEl) {
            statusEl.innerText =
                `${getErrorMessage(error, 'No se pudo consultar el progreso de la subida')} ❌\n` +
                'Recarga la página cuando el problema de infraestructura esté resuelto.';
        }

        return { restored: false, failed: true };
    }

    if (!progress || !progress.upload_id) {
        return { restored: false, failed: false };
    }

    const progressStatus = String(progress.status || 'none');
    if (['none', 'deleted', 'cancelled', 'failed', 'error'].includes(progressStatus)) {
        return { restored: false, failed: false };
    }

    statusEl.innerText = formatUploadStatus(progress);

    if (progressContainer && progressBar) {
        const fileSize = Number(progress.filesize || 0);
        const bytesUploaded = Number(progress.bytes_uploaded || 0);

        if (fileSize > 0) {
            const percent = Math.max(0, Math.min(100, Math.round((bytesUploaded / fileSize) * 100)));
            progressContainer.classList.remove('hidden');
            progressBar.style.width = `${percent}%`;
            progressBar.innerText = `${percent}%`;

            if (percent >= 100) {
                progressBar.classList.remove('bg-indigo-600');
                progressBar.classList.add('bg-green-600');
            }
        }
    }

    if (cancelBtn && ['uploading', 'created'].includes(progressStatus)) {
        cancelBtn.style.display = 'inline-flex';
        cancelBtn.disabled = false;

        cancelBtn.onclick = async function () {
            cancelBtn.disabled = true;
            statusEl.innerText = 'Cancelando subida...';

            await fetch(`/backoffice/courses/${container.dataset.courseId}/sessions/${sessionId}/video/cancel-upload`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': container.dataset.csrf,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ upload_id: progress.upload_id })
            });

            if (progressContainer && progressBar) {
                progressBar.style.width = '0%';
                progressBar.innerText = 'Cancelado';
            }

            statusEl.innerText = 'Subida cancelada ❌';
            cancelBtn.style.display = 'none';

            if (uploadBtn) {
                uploadBtn.style.display = 'inline-flex';
            }
        };
    }

 
    if (
        ['processing', 'uploaded', 'completed'].includes(progressStatus) &&
        progress.file_id
    ) {
        waitForVideoReady(progress.file_id);
    }

    return { restored: true, failed: false };
}

async function handleVideoInputChange(input) {
        const file = input.files[0];
        if (!file) return;
        const chatFile = getSelectedVideoChatFile();
        const chatValidationError = validateVideoChatFile(chatFile);

        if (chatValidationError) {
            const status = document.getElementById('videoStatus');
            if (status) {
                status.innerText = chatValidationError;
                status.setAttribute('role', 'alert');
            }
            input.value = '';
            return;
        }

        const uploadBtn = document.getElementById('uploadVideoBtn');
        const fileMeta = document.getElementById('videoFileMeta');

        if (fileMeta) {
            const sizeMb = (file.size / (1024 * 1024)).toFixed(1);
            fileMeta.textContent = `${file.name} · ${sizeMb} MB`;
            fileMeta.classList.remove('hidden');
        }

        const container = document.getElementById('videoUploadContainer');
        const courseId = container.dataset.courseId;
        const sessionId = container.dataset.sessionId;
        const csrf = container.dataset.csrf;

        const progressContainer = document.getElementById('uploadProgress');
        const progressBar = document.getElementById('progressBar');
        const status = document.getElementById('videoStatus');
        const cancelBtn = document.getElementById('cancelUploadBtn');

        progressContainer.classList.remove('hidden');

        status.innerText = '⏳ Estableciendo conexión con el servidor...';

        progressBar.style.width = '0%';
        progressBar.innerText = '';

        cancelBtn.disabled = false;

        input.disabled = true;

        if (uploadBtn) {
            uploadBtn.style.display = 'none';
        }

        let cancelRequested = false;
        let uploadId = null;
        let fileId = null;
        let uploadCompletedByDrive = false;
        let chatWarning = '';

        let uploadedBytes = 0;
        let startChunk = 0;

        cancelBtn.onclick = async function () {

            cancelRequested = true;
            cancelBtn.disabled = true;

            status.innerText = 'Cancelando subida...';

            if (uploadId) {
                await fetch(`/backoffice/courses/${courseId}/sessions/${sessionId}/video/cancel-upload`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ upload_id: uploadId })
                });
            }

            progressBar.style.width = '0%';
            progressBar.innerText = 'Cancelado';

            status.innerText = 'Subida cancelada ❌';

            cancelBtn.style.display = 'none';

            input.disabled = false;
            input.value = '';

            if (uploadBtn) {
                uploadBtn.style.display = 'inline-flex';
            }
        };

        try {

            const CHUNK_SIZE = 24 * 1024 * 1024;

            const progress = await getUploadProgress(sessionId);

            const metadata = {
                filename: file.name,
                mime_type: file.type || 'video/mp4',
                filesize: file.size
            };

            if (progress && progress.upload_id) {

                const sameFile =
                    String(progress.filename || '') === String(metadata.filename) &&
                    Number(progress.filesize || 0) === Number(metadata.filesize);

                if (sameFile) {
                    uploadId = progress.upload_id;
                    uploadedBytes = Number(progress.bytes_uploaded || 0);
                    startChunk = Math.floor(uploadedBytes / CHUNK_SIZE);
                    fileId = progress.file_id || null;
                    uploadCompletedByDrive = Boolean(fileId);
                } else {
                    status.innerText =
                        '⚠ Hay una subida pendiente, pero corresponde a otro archivo.\n' +
                        'Se iniciará una nueva subida para evitar mezclar archivos.';

                    uploadId = null;
                    uploadedBytes = 0;
                    startChunk = 0;
                    fileId = null;
                }
            }

            if (!uploadId) {

                const startResp = await fetch(
                    `/backoffice/courses/${courseId}/sessions/${sessionId}/video/start-upload`,
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(metadata)
                    }
                );

                const startData = await parseJsonResponse(startResp);

                if (startResp.redirected || !startData.__json) {
                    throw new Error(
                        getErrorMessage(
                            startData?.message || startData?.error,
                            'El servidor no confirmo el inicio de la subida. Vuelve a intentarlo.'
                        )
                    );
                }

                if (!startResp.ok) {
                    throw new Error(
                        getErrorMessage(
                            startData?.message || startData?.error,
                            'No se pudo iniciar subida'
                        )
                    );
                }

                uploadId = startData.upload_id;
                uploadedBytes = Number(startData.bytes_uploaded || 0);
                startChunk = Math.floor(uploadedBytes / CHUNK_SIZE);
                fileId = startData.file_id || null;

            }

            cancelBtn.style.display = 'inline-flex';

            status.innerText =
                '✔ Conexión establecida\n' +
                '⏳ Subiendo video...';

            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);

            for (let chunkIndex = startChunk; chunkIndex < totalChunks; chunkIndex++) {

                if (cancelRequested) {
                    throw new Error('Subida cancelada por el usuario');
                }

                const start = chunkIndex * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);

                const chunk = file.slice(start, end);

                const formData = new FormData();

                formData.append('chunk', chunk, file.name);   // ← importante
                formData.append('filename', file.name);       // ← nuevo
                formData.append('mime_type', file.type || 'video/mp4'); // ← nuevo
                formData.append('filesize', file.size);       // ← nuevo

                formData.append('upload_id', uploadId);
                formData.append('chunk_index', chunkIndex);
                formData.append('total_chunks', totalChunks);
                formData.append('start_byte', start);
                formData.append('end_byte', end - 1);

                const resp = await fetch(
                    `/backoffice/courses/${courseId}/sessions/${sessionId}/video/upload-chunk`,
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf
                        },
                        body: formData
                    }
                );

                const respData = await parseJsonResponse(resp);

                if (resp.redirected || !respData.__json) {
                    throw new Error(
                        getErrorMessage(
                            respData?.message || respData?.error,
                            'El servidor no confirmo el chunk. Vuelve a intentar la subida.'
                        )
                    );
                }

                if (!resp.ok) {
                    if (resp.status === 409 && respData?.code === 'upload_offset_mismatch') {
                        uploadId = null;
                        uploadedBytes = 0;
                        startChunk = 0;
                    }

                    throw new Error(
                        getErrorMessage(
                            respData?.message || respData?.error,
                            `Error subiendo chunk ${chunkIndex + 1}`
                        )
                    );
                }

                if (respData.bytes_uploaded !== undefined) {
                    uploadedBytes = respData.bytes_uploaded;
                } else {
                    uploadedBytes += chunk.size;
                }

                if (respData.file_id) {
                    fileId = respData.file_id;
                    uploadCompletedByDrive = true;
                }

                const percent = Math.round((uploadedBytes / file.size) * 100);

                progressBar.style.width = percent + '%';
                progressBar.innerText = percent + '%';
            }

            if (!fileId && progress?.file_id) {
                fileId = progress.file_id;
                uploadCompletedByDrive = true;
            }

            if (!fileId) {
                const finalProgress = await getUploadProgress(sessionId).catch(() => null);

                if (finalProgress?.file_id) {
                    fileId = finalProgress.file_id;
                    uploadCompletedByDrive = true;
                }
            }

            if (!fileId) {
                throw new Error('Google Drive no confirmo el archivo final. Vuelve a intentar la subida.');
            }

            status.innerText = 'Finalizando...';

            const finalizeResp = await fetch(
                `/backoffice/courses/${courseId}/sessions/${sessionId}/video/finalize-upload`,
                {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(fileId
                        ? {
                            file_id: fileId,
                            upload_id: uploadId,
                            filesize: file.size
                        }
                        : {
                            upload_id: uploadId,
                            filesize: file.size
                        })
                }
            );

            const finalizeData = await parseJsonResponse(finalizeResp);

            if (finalizeResp.redirected || !finalizeData.__json) {
                throw new Error(
                    getErrorMessage(
                        finalizeData?.message || finalizeData?.error,
                        'El servidor no confirmo la finalizacion. Vuelve a intentarlo.'
                    )
                );
            }

            if (!finalizeResp.ok) {
                throw new Error(
                    getErrorMessage(
                        finalizeData?.message || finalizeData?.error,
                        'Error finalizando'
                    )
                );
            }

            if (finalizeData.file_id) {
                fileId = finalizeData.file_id;
                uploadCompletedByDrive = true;
            }

            if (chatFile) {
                status.innerText = 'Guardando chat de Zoom...';

                try {
                    await uploadVideoChatTranscript(chatFile, { courseId, sessionId, csrf });
                } catch (chatError) {
                    chatWarning =
                        'La grabacion quedo lista, pero no se pudo guardar el chat de Zoom.\n' +
                        getErrorMessage(chatError, 'Intenta adjuntarlo nuevamente.');
                    status.innerText = chatWarning;
                }
            }

            progressBar.style.width = '100%';
            progressBar.innerText = '100%';

            status.innerText =
                '✔ Conexión establecida.\n' +
                '✔ Video subido correctamente,\n' +
                '⏳ Estamos dejando listo el video para reproducirse.\n' +
                'Ya no necesita esperar en esta página; en unos minutos estará disponible para los alumnos.' +
                (chatWarning ? `\n\n${chatWarning}` : '');

            progressBar.classList.remove('bg-indigo-600');
            progressBar.classList.add('bg-green-600');

            cancelBtn.style.display = 'none';

            setTimeout(() => {
                progressContainer.style.display = 'none';
            }, 1500);

            waitForVideoReady(fileId);
            window.invalidateCourseWorkspaceSession?.(sessionId);

        } catch (err) {

            console.error(err);

            const status = document.getElementById('videoStatus');
            if (uploadId && !uploadCompletedByDrive) {
                try {
                    await fetch(`/backoffice/courses/${courseId}/sessions/${sessionId}/video/cancel-upload`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ upload_id: uploadId })
                    });
                } catch (cleanupError) {
                    console.warn('No se pudo limpiar la subida fallida', cleanupError);
                }
            }

            if (status) {
                status.innerText = `${getErrorMessage(err, 'Error en la subida')} ❌`;
            }

            cancelBtn.style.display = 'none';
            input.disabled = false;
            input.value = '';

            if (uploadBtn) {
                uploadBtn.style.display = 'inline-flex';
            }
        }

}

document.addEventListener('change', (event) => {
    if (event.target.id === 'videoInput') {
        handleVideoInputChange(event.target);
    }

    if (event.target.id === 'videoChatInput') {
        const file = event.target.files?.[0] || null;
        const error = validateVideoChatFile(file);
        const status = document.getElementById('videoStatus');

        updateVideoChatMeta(file);

        if (error && status) {
            status.innerText = error;
            status.setAttribute('role', 'alert');
        }
    }
});

async function waitForVideoReady(expectedFileId = null) {

    const container = document.getElementById('videoUploadContainer');
    if (!container) return;
    const generation = videoPanelGeneration;

    const courseId = container.dataset.courseId;
    const sessionId = container.dataset.sessionId;

    const statusEl = document.getElementById('videoStatus');

    const url = container.dataset.canWriteVideo === '1'
        ? `/backoffice/courses/${courseId}/sessions/${sessionId}/video/status`
        : container.dataset.videoContentUrl;
    if (!url) return;
    const POLL_INTERVAL_MS = 10000;
    const RETRY_BASE_DELAY_MS = 5000;
    const RETRY_MAX_DELAY_MS = 30000;
    let consecutiveFailures = 0;

    const scheduleNextCheck = (delay = POLL_INTERVAL_MS) => {
        setTimeout(checkStatus, delay);
    };

    const checkStatus = async () => {

        if (generation !== videoPanelGeneration) return;

        try {
            const response = await fetch(url);

            if (!response.ok) {
                throw new Error('Status request failed');
            }

            const data = await response.json();
            consecutiveFailures = 0;

            if (data.status === 'ready' && data.file_id) {
                window.invalidateCourseWorkspaceSession?.(sessionId);
                if (statusEl) {
                    statusEl.innerText =
                        '✔ Conexión establecida\n' +
                        '✔ Video subido correctamente\n' +
                        '✔ Video listo para reproducir';
                }

                setTimeout(() => {
                    renderVideoPlayer(container, data.file_id, data.chat || null);
                }, 2500);

                return;
            }

            if (statusEl && data.file_id && ['processing', 'uploaded'].includes(data.status)) {
                statusEl.innerText =
                    '✔ Conexión establecida.\n' +
                    '✔ Video subido correctamente.\n' +
                    '⏳ Estamos dejando listo el video para reproducirse.\n' +
                    'Ya no necesita esperar en esta página; en unos minutos estará disponible para los alumnos.';

                scheduleNextCheck();
                return;
            }

            if (statusEl && data.status === 'none' && expectedFileId) {
                statusEl.innerText =
                    '⚠ El video se subió, pero aún no termina de sincronizarse.\n' +
                    'Seguimos verificando automáticamente...';

                scheduleNextCheck();
                return;
            }

            if (statusEl && data.status === 'missing') {
                statusEl.innerText =
                    '❌ Google Drive no confirmó la creación real del archivo.\n' +
                    'La subida no se completó correctamente.';

                return;
            }

            if (statusEl && data.status === 'unknown') {
                statusEl.innerText =
                    '⚠ No se pudo confirmar el estado real del video.\n' +
                    'Intenta nuevamente en unos momentos.';

                return;
            }

            if (statusEl) {
                console.warn('Estado de video no manejado:', data);

                if (['created', 'uploading'].includes(String(data.status || ''))) {
                    statusEl.innerText =
                        'La subida sigue en proceso.\n' +
                        'Seguimos verificando automaticamente...';

                    scheduleNextCheck();
                    return;
                }

                statusEl.innerText =
                    '⚠ Estado inesperado del video.\n' +
                    'Revisa el proceso de subida.';
            }
           
        } catch (error) {
            console.error('Error consultando estado del video', error);
            consecutiveFailures += 1;
            const retryDelay = Math.min(
                RETRY_MAX_DELAY_MS,
                RETRY_BASE_DELAY_MS * consecutiveFailures
            );

            if (statusEl) {
                statusEl.innerText =
                    '⚠ No se pudo consultar el estado del video.\n' +
                    'Reintentando automaticamente.';
            }

            scheduleNextCheck(retryDelay);
        }
    };

    checkStatus();
}

function renderVideoPlayer(container, fileId, chat = null) {
    const canWriteVideo = container.dataset.canWriteVideo === '1';
    const sessionId = escapeHtml(container.dataset.sessionId);
    const deleteChatHtml = canWriteVideo && chat?.file_id
        ? `<button type="button" class="btn-danger" data-delete-video-chat data-session-id="${sessionId}">Eliminar chat</button>`
        : '';
    const deleteVideoHtml = canWriteVideo
        ? `<button type="button" id="deleteVideoBtn" data-session-id="${sessionId}" class="btn-danger">Eliminar video</button>`
        : '';
    const chatHtml = chat?.file_id
        ? `<article class="video-ready-card" data-video-resource="chat">
            <span class="video-ready-media-icon video-ready-media-icon--txt" aria-hidden="true">TXT</span>
            <div class="video-ready-body"><h4 class="video-ready-title">Chat de la clase</h4>
                <p class="video-ready-copy">${escapeHtml(chat.title || 'chat-de-zoom.txt')}${chat.filesize ? ' · ' + (Number(chat.filesize) / 1024).toFixed(1) + ' KB' : ''}</p></div>
            <div class="session-panel-actions">
                <a class="btn-secondary" href="/courses/sessions/${sessionId}/video/chat/preview" data-preview-video-chat data-session-id="${sessionId}">Ver chat</a>
                <a class="btn-secondary" href="/courses/sessions/${sessionId}/video/chat/download">Descargar TXT</a>
            </div></article>`
        : '<p class="video-consultation-note">El chat de la clase aún no está disponible.</p>';
    const managementHtml = canWriteVideo
        ? `<section class="video-section" data-video-management aria-label="Gestión del contenido">
            <h3>Gestión del contenido</h3><p>Estos recursos están publicados para los usuarios autorizados.</p>
            ${chat?.file_id ? '' : `<div class="session-panel-actions">
                <label class="btn-secondary" for="videoChatInput">Agregar chat de Zoom</label>
                <button type="button" id="uploadVideoChatBtn" class="btn-primary hidden">Guardar chat</button></div>
                <input type="file" id="videoChatInput" accept=".txt,text/plain" class="hidden">
                <div id="videoChatMeta" class="video-file-meta hidden" aria-live="polite"></div>`}
            </section>
            <section class="video-section video-maintenance" data-video-maintenance aria-label="Acciones de mantenimiento">
                <h3>Acciones de mantenimiento</h3><p>Estas acciones eliminan recursos publicados y pueden afectar a alumnos y docentes.</p>
                <div class="session-panel-actions">${deleteVideoHtml}${deleteChatHtml}</div>
            </section>`
        : (container.dataset.showVideoManagementNote === '1' ? '<p class="video-consultation-note">Los recursos de esta sesión son gestionados por administración.</p>' : '');
    container.classList.add('video-content-panel');
    container.dataset.videoStatus = 'ready';
    container.innerHTML = `
        <h2 class="session-panel-title">Video de la sesión</h2>
        <p class="session-panel-subtitle">${canWriteVideo ? 'Prepara y consulta los recursos de esta sesión.' : 'Consulta los recursos publicados para esta sesión.'}</p>
        <section class="video-section" aria-label="Estado del contenido"><h3>Estado del contenido</h3>
            <dl class="video-status-grid"><div><dt>Grabación</dt><dd class="video-status is-available">Disponible</dd></div>
                <div><dt>Chat de la clase</dt><dd class="video-status ${chat?.file_id ? 'is-available' : 'is-pending'}">${chat?.file_id ? 'Disponible' : 'No disponible'}</dd></div></dl>
            <div id="videoStatus" class="video-status-message" role="status" aria-live="polite">La grabación está lista para consultar.</div>
        </section>
        <section class="video-section" data-video-resources aria-label="Recursos de la sesión"><h3>Recursos de la sesión</h3>
            <article class="video-ready-card" data-video-resource="recording"><span class="video-ready-media-icon" aria-hidden="true">▶</span>
                <div class="video-ready-body"><h4 class="video-ready-title">Grabación de la sesión disponible</h4><p class="video-ready-copy">Repasa el contenido cuando lo necesites.</p></div>
                <a href="https://drive.google.com/file/d/${encodeURIComponent(fileId)}/view" target="_blank" rel="noopener noreferrer" class="btn-primary">Ver grabación<span class="sr-only"> (se abre en otra pestaña)</span></a>
            </article>${chatHtml}
        </section>${managementHtml}`;
}

function initVideoPanel() {
    const container = document.getElementById('videoUploadContainer');
    if (!container) return;
    if (container.dataset.videoInitialized === '1') return;
    container.dataset.videoInitialized = '1';
    videoPanelGeneration += 1;

    const status = document.getElementById('videoStatus');
    const persistedStatus = container.dataset.videoStatus || '';

    // Readers must not restore upload state or call management progress endpoints.
    if (container.dataset.canWriteVideo !== '1') {
        if (['processing', 'uploaded', 'completed'].includes(persistedStatus)) {
            waitForVideoReady();
        }
        return;
    }

    restoreUploadStateOnLoad().then((result) => {
        if (result.restored || result.failed) {
            return;
        }

        if (
            ['processing', 'uploaded', 'completed'].includes(persistedStatus) ||
            (status && status.innerText.includes('Procesando video'))
        ) {
            waitForVideoReady();
        }
    });

}

window.initVideoPanel = initVideoPanel;
document.addEventListener('DOMContentLoaded', initVideoPanel);

document.addEventListener('click', async function (e) {
    const previewChatBtn = e.target.closest('[data-preview-video-chat]');
    if (previewChatBtn) {
        e.preventDefault();
        const container = document.getElementById('videoUploadContainer');
        const sessionId = previewChatBtn.dataset.sessionId || container?.dataset.sessionId;

        if (!sessionId) return;

        previewChatBtn.disabled = true;
        const previousText = previewChatBtn.textContent;
        previewChatBtn.textContent = 'Cargando...';

        try {
            const response = await fetch(`/courses/sessions/${sessionId}/video/chat/preview`, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await parseJsonResponse(response);

            if (!response.ok || !data.__json) {
                throw new Error(getErrorMessage(data?.message || data?.error, 'No se pudo cargar el chat de Zoom.'));
            }

            renderVideoChatPreview(data);
        } catch (error) {
            const status = document.getElementById('videoStatus');
            if (status) {
                status.textContent = getErrorMessage(error, 'No se pudo cargar el chat de Zoom.');
                status.setAttribute('role', 'alert');
            } else {
                alert(getErrorMessage(error, 'No se pudo cargar el chat de Zoom.'));
            }
        } finally {
            previewChatBtn.disabled = false;
            previewChatBtn.textContent = previousText;
        }

        return;
    }

    const uploadChatBtn = e.target.closest('#uploadVideoChatBtn');
    if (uploadChatBtn) {
        const container = document.getElementById('videoUploadContainer');
        const file = getSelectedVideoChatFile();

        if (!container || !file) return;

        uploadChatBtn.disabled = true;
        uploadChatBtn.textContent = 'Guardando...';

        try {
            await uploadVideoChatTranscript(file, {
                courseId: container.dataset.courseId,
                sessionId: container.dataset.sessionId,
                csrf: container.dataset.csrf
            });

            window.invalidateCourseWorkspaceSession?.(container.dataset.sessionId);
            location.reload();
        } catch (error) {
            const status = document.getElementById('videoStatus') || document.getElementById('videoChatMeta');
            if (status) {
                status.textContent = getErrorMessage(error, 'No se pudo guardar el chat de Zoom.');
                status.setAttribute('role', 'alert');
            }
            uploadChatBtn.disabled = false;
            uploadChatBtn.textContent = 'Guardar chat';
        }

        return;
    }

    const deleteChatBtn = e.target.closest('[data-delete-video-chat]');
    if (deleteChatBtn) {
        const confirmed = await confirmAction({
            title: 'Eliminar chat de Zoom',
            message: 'Se quitara el chat asociado a esta grabacion. El video se conservara.',
            confirmText: 'Eliminar chat'
        });

        if (!confirmed) return;

        const container = document.getElementById('videoUploadContainer');
        const courseId = container?.dataset.courseId;
        const sessionId = deleteChatBtn.dataset.sessionId || container?.dataset.sessionId;
        const csrf = container?.dataset.csrf;

        if (!courseId || !sessionId || !csrf) return;

        deleteChatBtn.disabled = true;
        deleteChatBtn.textContent = 'Eliminando...';

        try {
            const response = await fetch(`/backoffice/courses/${courseId}/sessions/${sessionId}/video/chat`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                }
            });
            const data = await parseJsonResponse(response);

            if (!response.ok || !data.__json) {
                throw new Error(getErrorMessage(data?.message || data?.error, 'No se pudo eliminar el chat de Zoom.'));
            }

            window.invalidateCourseWorkspaceSession?.(sessionId);
            location.reload();
        } catch (error) {
            deleteChatBtn.disabled = false;
            deleteChatBtn.textContent = 'Eliminar chat';
            const status = document.getElementById('videoStatus');
            if (status) {
                status.textContent = getErrorMessage(error, 'No se pudo eliminar el chat de Zoom.');
                status.setAttribute('role', 'alert');
            }
        }

        return;
    }

    const btn = e.target.closest('#deleteVideoBtn');
    if (!btn) return;

    const confirmed = await confirmAction({
        title: "Eliminar video",
        message: "¿Estás seguro de eliminar el video de esta sesión? Esta acción no se puede deshacer.",
        confirmText: "Eliminar"
    });

    if (!confirmed) return;

    const container = document.getElementById('videoUploadContainer');
    if (!container) return;

    const sessionId = container.dataset.sessionId;
    const courseId = container.dataset.courseId;
    const csrf = container.dataset.csrf;

    btn.disabled = true;
    btn.innerText = 'Eliminando...';

    try {

        const resp = await fetch(`/backoffice/courses/${courseId}/sessions/${sessionId}/video`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json'
            }
        });

        const data = await resp.json();

        if (resp.ok && data.status === 'deleted') {
            location.reload();
        } else {
            throw new Error(data.message || 'Error eliminando video');
        }

    } catch (err) {

        console.error(err);
        btn.disabled = false;
        btn.innerText = 'Eliminar';
        const status = document.getElementById('videoStatus');
        if (status) {
            status.textContent = 'No se pudo eliminar el video. Intenta nuevamente.';
            status.setAttribute('role', 'alert');
        }

    }

});
let videoPanelGeneration = 0;
