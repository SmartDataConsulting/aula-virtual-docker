import './global.js';

let saveTimer = null;
let savePending = false;
let saveQueued = false;
let isLoading = true;
let descriptionEditor = null;
const REQUIRED_WORK_TOTAL_SCORE = 20;
let workEvaluationConfig = {
    saveUrl: '',
    publishUrl: '',
    viewUrl: '',
};

function criterionTemplate(item = {}) {
    const criterionName = item.nombre || item.descripcion || '';

    return `
    <div class="rubric-row grid grid-cols-1 md:grid-cols-[minmax(0,0.95fr)_minmax(0,1.45fr)_120px_44px] gap-3 items-start px-4 py-3">
        <input
            type="text"
            class="criterion-name w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
            placeholder="Nombre del criterio"
            value="${escapeHtml(criterionName)}">
        <textarea
            rows="2"
            class="criterion-description w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
            placeholder="Descripcion del criterio">${escapeHtml(item.descripcion || '')}</textarea>
        <input
            type="number"
            min="0"
            step="0.01"
            class="criterion-points w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
            placeholder="Puntaje"
            value="${item.puntaje_max ?? ''}">
        <button
            type="button"
            class="remove-criterion mt-1 h-8 w-8 rounded-md text-sm text-slate-400 hover:bg-slate-100 hover:text-red-600">
            x
        </button>
    </div>`;
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function showAutosaveStatus(text, color = 'neutral') {
    const el = document.getElementById('autosaveStatus');
    if (!el) return;

    el.textContent = text;
    el.classList.remove('bg-neutral-800', 'bg-green-600', 'bg-red-600');

    if (color === 'success') el.classList.add('bg-green-600');
    else if (color === 'error') el.classList.add('bg-red-600');
    else el.classList.add('bg-neutral-800');

    el.style.opacity = '1';

    setTimeout(() => {
        el.style.opacity = '0';
    }, 2000);
}

function addCriterion(item = {}) {
    const container = document.getElementById('rubricCriteria');
    if (!container) return;

    container.insertAdjacentHTML('beforeend', criterionTemplate(item));
    updateEmptyState();
    updateCalculatedScore();
}

function updateEmptyState() {
    const container = document.getElementById('rubricCriteria');
    const emptyState = document.getElementById('rubricEmptyState');

    if (!container || !emptyState) return;

    emptyState.classList.toggle('hidden', container.children.length > 0);
}

function calculateTotalScore(criterios) {
    return criterios.reduce((total, criterio) => total + (Number(criterio.puntaje_max) || 0), 0);
}

function formatScore(value) {
    const numericValue = Number(value || 0);
    return Number.isInteger(numericValue)
        ? String(numericValue)
        : numericValue.toFixed(2).replace(/\.00$/, '');
}

function updateCalculatedScore() {
    const criterios = Array.from(
        document.querySelectorAll('#rubricCriteria .rubric-row')
    ).map((row) => ({
        puntaje_max: Number(row.querySelector('.criterion-points')?.value || 0),
    }));

    const total = calculateTotalScore(criterios);
    const scoreDisplay = document.getElementById('workScoreDisplay');

    if (scoreDisplay) {
        scoreDisplay.textContent = formatScore(total);
    }

    updateScoreHint(total);
    updatePublishAvailability(total);

    return total;
}

function hasValidRequiredTotal(total) {
    return Math.abs(Number(total || 0) - REQUIRED_WORK_TOTAL_SCORE) < 0.0001;
}

function updateScoreHint(total) {
    const hint = document.getElementById('workScoreHint');

    if (!hint) return;

    if (hasValidRequiredTotal(total)) {
        hint.textContent = `La rúbrica ya suma ${REQUIRED_WORK_TOTAL_SCORE} puntos. Ya puedes publicar.`;
        hint.className = 'text-sm text-emerald-600 text-right';
        return;
    }

    hint.textContent = `El puntaje máximo de la rúbrica debe sumar exactamente ${REQUIRED_WORK_TOTAL_SCORE} puntos para poder publicar.`;
    hint.className = 'text-sm text-amber-600 text-right';
}

function updatePublishAvailability(total) {
    const publishBtn = document.getElementById('publishEvaluation');

    if (!publishBtn) return;

    const isPublished = publishBtn.dataset.published === 'true';

    if (isPublished) {
        publishBtn.disabled = true;
        return;
    }

    const canPublish = hasValidRequiredTotal(total);

    publishBtn.disabled = !canPublish;
    publishBtn.classList.toggle('bg-green-600', canPublish);
    publishBtn.classList.toggle('hover:bg-green-700', canPublish);
    publishBtn.classList.toggle('bg-gray-400', !canPublish);
    publishBtn.classList.toggle('cursor-not-allowed', !canPublish);
}

function collectWorkData() {
    const criterios = Array.from(
        document.querySelectorAll('#rubricCriteria .rubric-row')
    ).map((row, index) => ({
        nombre:
            row.querySelector('.criterion-name')?.value?.trim()
            || row.querySelector('.criterion-description')?.value?.trim()
            || '',
        descripcion: row.querySelector('.criterion-description')?.value?.trim() || '',
        puntaje_max: Number(row.querySelector('.criterion-points')?.value || 0),
        orden: index + 1,
    }));

    return {
        evaluacion: {
            nombre: document.getElementById('evaluationTitle')?.value?.trim() || '',
            peso: Number(document.getElementById('evaluationWeight')?.value || 0),
            puntaje_aprobacion: Number(document.getElementById('evaluationPassScore')?.value || 0),
        },
        trabajo: {
            descripcion: descriptionEditor?.root?.innerHTML || '',
            puntaje_max: calculateTotalScore(criterios),
            rubrica: {
                nombre: 'Rúbrica general',
                criterios,
            },
        },
    };
}

function scheduleSave() {
    if (isLoading) return;

    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
        performSave();
    }, 800);
}

async function performSave() {
    if (savePending) {
        saveQueued = true;
        return;
    }

    savePending = true;
    showAutosaveStatus('Guardando...');

    try {
        const response = await fetch(workEvaluationConfig.saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(collectWorkData())
        });

        if (!response.ok) {
            throw new Error('save failed');
        }

        showAutosaveStatus('Guardado', 'success');
        return await response.json();
    } catch (error) {
        console.error(error);
        showAutosaveStatus('Error al guardar', 'error');
        throw error;
    } finally {
        savePending = false;

        if (saveQueued) {
            saveQueued = false;
            performSave();
        }
    }
}

function validateWork() {
    const payload = collectWorkData();
    const errors = [];

    if (!payload.evaluacion.nombre) {
        errors.push('Debe ingresar un nombre');
    }

    if (!getDescriptionPlainText()) {
        errors.push('Debe ingresar la descripción del trabajo');
    }

    if (payload.evaluacion.peso <= 0 || payload.evaluacion.peso > 100) {
        errors.push('El peso debe ser mayor a 0 y no puede exceder 100');
    }

    if (payload.evaluacion.puntaje_aprobacion < 1 || payload.evaluacion.puntaje_aprobacion > 20) {
        errors.push('El puntaje mínimo para aprobar debe estar entre 1 y 20');
    }

    if (!payload.trabajo.rubrica.criterios.length) {
        errors.push('Debe agregar al menos un criterio en la rúbrica');
    }

    if (payload.trabajo.puntaje_max <= 0) {
        errors.push('La suma de puntajes de criterios debe ser mayor a 0');
    }

    if (!hasValidRequiredTotal(payload.trabajo.puntaje_max)) {
        errors.push(`La suma de puntajes de criterios debe ser exactamente ${REQUIRED_WORK_TOTAL_SCORE}`);
    }

    payload.trabajo.rubrica.criterios.forEach((criterio, index) => {
        if (!criterio.nombre) {
            errors.push(`Criterio ${index + 1}: debe tener nombre`);
        }

        if (!criterio.descripcion) {
            errors.push(`Criterio ${index + 1}: debe tener descripcion`);
        }

        if (criterio.puntaje_max <= 0) {
            errors.push(`Criterio ${index + 1}: el puntaje debe ser mayor a 0`);
        }
    });

    return errors;
}

function showErrorModal(message) {
    const modal = document.getElementById('appErrorModal');
    const msg = document.getElementById('appErrorMessage');
    const ok = document.getElementById('appErrorOk');

    if (!modal || !msg || !ok) return;

    msg.innerText = message;
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    ok.onclick = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };
}

document.addEventListener('click', (event) => {
    if (event.target.id === 'addCriterionBtn') {
        addCriterion();
        scheduleSave();
        return;
    }

    if (event.target.classList.contains('remove-criterion')) {
        event.target.closest('.rubric-row')?.remove();
        updateEmptyState();
        updateCalculatedScore();
        scheduleSave();
    }
});

document.addEventListener('input', (event) => {
    if (
        event.target.matches('#evaluationTitle') ||
        event.target.matches('#evaluationWeight') ||
        event.target.matches('#evaluationPassScore') ||
        event.target.matches('.criterion-name') ||
        event.target.matches('.criterion-description') ||
        event.target.matches('.criterion-points')
    ) {
        if (event.target.matches('.criterion-points')) {
            updateCalculatedScore();
        }
        scheduleSave();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const mainBtn = document.getElementById('addCriterionBtn');
    const secondaryBtn = document.getElementById('addCriterionBtnSecondary');

    if (secondaryBtn && mainBtn) {
        secondaryBtn.addEventListener('click', function () {
            mainBtn.click();
        });
    }

    const data = hydrateWorkEvaluationData();
    console.log('DATA RAW:', data);
    console.log('EVALUACION:', data.evaluacion);
    console.log('TRABAJO:', data.trabajo);

    const evaluacion = data.evaluacion || {};
    const trabajo = data.trabajo || {};
    const rubrica = trabajo.rubrica || {};
    const criterios = rubrica.criterios || [];

    console.log('TITLE INPUT:', document.getElementById('evaluationTitle'));
    console.log('WEIGHT INPUT:', document.getElementById('evaluationWeight'));
    console.log('PASS INPUT:', document.getElementById('evaluationPassScore'));
    console.log('EDITOR DIV:', document.getElementById('workDescriptionEditor'));
    console.log('RUBRIC DIV:', document.getElementById('rubricCriteria'));

    document.getElementById('evaluationTitle').value = evaluacion.nombre || evaluacion.name || '';
    document.getElementById('evaluationWeight').value = evaluacion.weight_percent ?? 0;
    document.getElementById('evaluationPassScore').value =evaluacion.pass_score ?? 0;

    console.log('TITLE VALUE:', document.getElementById('evaluationTitle').value);
    console.log('WEIGHT VALUE:', document.getElementById('evaluationWeight').value);
    console.log('PASS VALUE:', document.getElementById('evaluationPassScore').value);

    initDescriptionEditor(trabajo.descripcion || '');
    console.log('DESC HTML:', trabajo.descripcion);

    criterios.forEach((criterio) => addCriterion(criterio));
    updateEmptyState();
    updateCalculatedScore();
    isLoading = false;

    const isPublished = Boolean(evaluacion.publicada ?? evaluacion.published);

    if (isPublished) {
        document.querySelectorAll('input, textarea, button').forEach((el) => {
            if (el.id !== 'appErrorOk') {
                el.disabled = true;
            }
        });

        descriptionEditor?.enable(false);
    }

    const publishBtn = document.getElementById('publishEvaluation');

    if (publishBtn) {
        publishBtn.dataset.published = String(isPublished);
        updatePublishAvailability(updateCalculatedScore());

        publishBtn.addEventListener('click', async () => {
            if (publishBtn.disabled) return;

            const ok = await confirmAction({
                title: 'Publicar evaluación',
                message: 'Una vez publicada no podrás editarla',
                confirmText: 'Publicar'
            });

            if (!ok) return;

            const errors = validateWork();

            if (errors.length) {
                showErrorModal(errors.join('\n'));
                return;
            }

            publishBtn.disabled = true;
            showGlobalLoader('Publicando evaluación...');

            try {
                clearTimeout(saveTimer);
                await performSave();

                const response = await fetch(workEvaluationConfig.publishUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();

                if (!data.ok) {
                    throw new Error(data.error || 'No se pudo publicar');
                }

                hideGlobalLoader();

                showSuccessModal(
                    `La evaluación "${document.getElementById('evaluationTitle').value}" se publicó correctamente`,
                    () => window.location.replace(workEvaluationConfig.viewUrl)
                );
            } catch (error) {
                hideGlobalLoader();
                publishBtn.disabled = false;
                showErrorModal(error.message || 'No se pudo publicar');
            }
        });
    }
});

function initDescriptionEditor(initialHtml) {
    const editorElement = document.getElementById('workDescriptionEditor');

    if (!editorElement || typeof window.Quill === 'undefined') {
        return;
    }

    descriptionEditor = new window.Quill(editorElement, {
        theme: 'snow',
        placeholder: 'Describe la consigna del trabajo...',
        modules: {
            toolbar: [
                [{ header: [false] }],
                ['bold', 'italic', 'underline'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['link'],
                ['clean']
            ]
        }
    });

    descriptionEditor.root.innerHTML = initialHtml || '';
    const toolbar = editorElement.querySelector('.ql-toolbar');
    const container = editorElement.querySelector('.ql-container');

    if (toolbar) {
        toolbar.style.padding = '6px 8px';
        toolbar.style.borderBottom = '1px solid #e5e7eb';
    }

    if (container) {
        container.style.overflowY = 'auto';
    }

    descriptionEditor.root.style.padding = '8px 10px';
    descriptionEditor.root.style.fontSize = '14px';
    descriptionEditor.root.style.boxSizing = 'border-box';
    descriptionEditor.root.style.overflowY = 'auto';

    descriptionEditor.on('text-change', () => {
        scheduleSave();
    });
}

function getDescriptionPlainText() {
    return descriptionEditor?.getText()?.trim() || '';
}

function hydrateWorkEvaluationData() {
    const context = document.getElementById('workEvaluationContext');
    const payload = document.getElementById('workEvaluationPayload');

    if (!context || !payload) {
        return {};
    }

    workEvaluationConfig = {
        saveUrl: context.dataset.saveUrl || '',
        publishUrl: context.dataset.publishUrl || '',
        viewUrl: context.dataset.viewUrl || '',
    };

    try {
        return JSON.parse(payload.textContent || '{}');
    } catch (error) {
        console.error('No se pudo leer la configuración del trabajo.', error);
        return {};
    }
}
