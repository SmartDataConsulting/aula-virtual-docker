    import './global.js';

    const sessionEvaluationState = {
        pendingAssignments: new Map(),
    };
    const sessionEvaluationContext = {
        courseId: 0,
        sessionId: 0,
        evaluacionesAsignadas: [],
        evaluacionesDisponibles: [],
    };
    let evaluationSyncStatusTimeoutId = null;

    function initSessionEvaluations() {
        sessionEvaluationState.pendingAssignments.clear();
        hydrateSessionEvaluationContext();
        renderAssignedEvaluations();
        renderAvailableEvaluations();

        const createButton = document.getElementById('openCreateEvaluationModalBtn');
        if (createButton && createButton.dataset.listenerBound !== '1') {
            createButton.dataset.listenerBound = '1';
            createButton.addEventListener('click', openCreateEvaluationModal);
        }

        const assignButton = document.getElementById('assignEvaluationsBtn');
        if (assignButton && assignButton.dataset.listenerBound !== '1') {
            assignButton.dataset.listenerBound = '1';
            assignButton.addEventListener('click', asignarEvaluacionesSeleccionadas);
        }

        const templateForm = document.getElementById('evaluationPlanTemplateForm');
        if (templateForm && templateForm.dataset.listenerBound !== '1') {
            templateForm.dataset.listenerBound = '1';
            templateForm.addEventListener('submit', applyEvaluationPlanTemplate);
        }

        const typeSelect = document.getElementById('tipoEvaluacion');
        if (typeSelect && typeSelect.dataset.listenerBound !== '1') {
            typeSelect.dataset.listenerBound = '1';
            typeSelect.addEventListener('change', syncEvaluationTypeFields);
        }

        const createForm = document.getElementById('createEvaluationForm');
        if (createForm && createForm.dataset.listenerBound !== '1') {
            createForm.dataset.listenerBound = '1';
            createForm.addEventListener('submit', validateCreateEvaluationForm);
        }
    }

    document.addEventListener('DOMContentLoaded', initSessionEvaluations);

    function hydrateSessionEvaluationContext() {
        const pageContainer = document.getElementById('evaluationsPageContext');
        const sessionContainer = document.getElementById('sessionEvaluationContext');

        if (pageContainer) {
            sessionEvaluationContext.courseId = Number(pageContainer.dataset.courseId || 0);
        }

        if (!sessionContainer) {
            return;
        }

        sessionEvaluationContext.courseId = Number(
            sessionContainer.dataset.courseId || sessionEvaluationContext.courseId || 0
        );
        sessionEvaluationContext.sessionId = Number(sessionContainer.dataset.sessionId || 0);
        sessionEvaluationContext.evaluacionesAsignadas = decodeEvaluationPayload(
            sessionContainer.dataset.evaluacionesAsignadas
        );
        sessionEvaluationContext.evaluacionesDisponibles = decodeEvaluationPayload(
            sessionContainer.dataset.evaluacionesDisponibles
        );
    }

    function decodeEvaluationPayload(value) {
        if (!value) {
            return [];
        }

        try {
            const decoded = window.atob(value);
            const parsed = JSON.parse(decoded);

            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            console.error('No se pudo leer el contexto de evaluaciones.', error);
            return [];
        }
    }

    function openCreateEvaluationModal() {
        document
            .getElementById('createEvaluationModal')
            ?.classList.remove('hidden');

        window.cargarParametros(21, 'tipoEvaluacion');

        setTimeout(() => syncEvaluationTypeFields(), 0);
    }

    function closeCreateEvaluationModal() {
        document
            .getElementById('createEvaluationModal')
            ?.classList.add('hidden');
    }

    function syncEvaluationTypeFields() {
        const typeSelect = document.getElementById('tipoEvaluacion');
        const sharedFields = document.getElementById('sharedRequiredFields');
        const examFields = document.getElementById('examOnlyFields');
        const workFields = document.getElementById('workOnlyFields');
        const workMessage = document.getElementById('workOnlyMessage');

        if (!typeSelect || !sharedFields || !examFields || !workFields || !workMessage) {
            return;
        }

        const typeId = parseInt(typeSelect.value || '0', 10);
        const hasTypeSelected = typeId > 0;
        const isWorkTypeSelected = typeId === 3 || typeId === 4;
        const isExamType = typeId === 1 || typeId === 2;

        sharedFields.classList.toggle('hidden', !hasTypeSelected);
        examFields.classList.toggle('hidden', !isExamType);
        workFields.classList.toggle('hidden', !isWorkTypeSelected);
        workMessage.classList.toggle('hidden', !isWorkTypeSelected);
        workMessage.style.display = 'none';

        sharedFields
            .querySelectorAll('input')
            .forEach((input) => {
                input.required = hasTypeSelected;
            });

        examFields
            .querySelectorAll('input')
            .forEach((input) => {
                input.required = isExamType;
            });

        workFields
            .querySelectorAll('input')
            .forEach((input) => {
                input.required = isWorkTypeSelected;
            });
    }

   function validateCreateEvaluationForm(event) {
        const form = event.target;
        const typeId = parseInt(form.querySelector('[name="tipo"]')?.value || '0', 10);

        const isExamType = typeId === 1 || typeId === 2;
        const weight = Number(form.querySelector('[name="peso"]')?.value || 0);
        const passScore = Number(form.querySelector('[name="puntaje_aprobacion"]')?.value || 0);

        if (weight <= 0 || weight > 100) {
            event.preventDefault();
            alert('El peso debe ser mayor a 0 y no puede exceder 100%.');
            return;
        }

        if (passScore < 1 || passScore > 20) {
            event.preventDefault();
            alert('El puntaje mínimo para aprobar debe estar entre 1 y 20.');
            return;
        }

        if (isExamType) {
            const timeMinutes = Number(form.querySelector('[name="tiempo_minutos"]')?.value || 0);

            if (timeMinutes <= 0) {
                event.preventDefault();
                alert('El tiempo debe ser mayor a 0.');
            }
        }
    }

    async function duplicateEvaluation(evaluationId, typeId) {
        const ok = await confirmAction({
            title: 'Duplicar evaluación',
            message: 'Se creará una nueva versión editable',
            confirmText: 'Duplicar'
        });

        if (!ok) return;

        showGlobalLoader('Duplicando evaluación...');

        try {
            const response = await fetch(
                `/backoffice/evaluations/${sessionEvaluationContext.courseId}/${evaluationId}/duplicate`,
                {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            .content
                    },
                    body: JSON.stringify({
                        type_id: typeId
                    })
                }
            );

            const data = await response.json().catch(() => ({}));

            if (response.ok && data?.ok && data?.redirect_url) {
                window.location.href = data.redirect_url;
                return;
            }

            hideGlobalLoader();
            alert(data?.error || 'No se pudo duplicar');

        } catch (e) {
            hideGlobalLoader();
            console.error(e);
            alert('Error al duplicar');
        }
    }

    function renderAssignedEvaluations() {
        const container = document.getElementById('evaluacionesAsignadas');

        if (!container) return;

        const items = sessionEvaluationContext.evaluacionesAsignadas || [];

        if (!items.length) {
            container.innerHTML = `
                <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm italic text-slate-500">
                    Esta sesión no tiene actividad evaluable.
                </div>
            `;
            return;
        }

        container.innerHTML = items
            .map((ev) => renderEvaluationCard(ev, {
                assigned: true,
                checked: true,
                deadlineValue: normalizeDateTimeForInput(ev.fecha_limite),
            }))
            .join('');
    }

    function renderAvailableEvaluations() {
        const container = document.getElementById('evaluacionesDisponibles');

        if (!container) return;

        const items = sessionEvaluationContext.evaluacionesDisponibles || [];

        if (!items.length) {
            container.innerHTML = `
                <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm italic text-slate-500">
                    No hay evaluaciones disponibles
                </div>
            `;
            return;
        }

        container.innerHTML = items
            .map((ev) => renderEvaluationCard(ev, {
                assigned: false,
                checked: sessionEvaluationState.pendingAssignments.has(Number(ev.id)),
                deadlineValue: normalizeDateTimeForInput(sessionEvaluationState.pendingAssignments.get(Number(ev.id))?.fecha_limite),
                metadata: sessionEvaluationState.pendingAssignments.get(Number(ev.id)) || {},
            }))
            .join('');
    }

    function renderEvaluationCard(ev, options = {}) {
        const assigned = Boolean(options.assigned);
        const checked = Boolean(options.checked);
        const isWork = isWorkType(ev);
        const deadlineValue = options.deadlineValue || '';
        const metadata = {
            hito_nombre: ev.hito_nombre || '',
            hito_orden: ev.hito_orden || '',
            grupo_nombre: ev.grupo_nombre || '',
            plazo_dias: ev.plazo_dias || '',
            ...(options.metadata || {}),
        };
        const cardClasses = assigned
            ? 'border-blue-200 bg-blue-50/70'
            : 'border-slate-200 bg-white hover:bg-slate-50 cursor-pointer';
        const checkboxClasses = assigned
            ? 'border-blue-300 text-indigo-600'
            : 'border-slate-300 text-indigo-600';
        const typeTextClass = assigned ? 'text-slate-600' : 'text-slate-500';
        const milestoneBlock = checked
            ? `
                <div class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white/70 p-3 md:grid-cols-2">
                    <label class="block text-sm font-medium text-slate-600">
                        Hito o entregable
                        <input
                            type="text"
                            class="evaluation-metadata-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800"
                            data-field="hito_nombre"
                            data-evaluation-id="${ev.id}"
                            data-assigned="${assigned ? '1' : '0'}"
                            value="${escapeHtml(metadata.hito_nombre || ev.nombre || '')}"
                            placeholder="Ej. Revisión de 1er avance"
                        >
                    </label>
                    <label class="block text-sm font-medium text-slate-600">
                        Grupo del proyecto
                        <input
                            type="text"
                            class="evaluation-metadata-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800"
                            data-field="grupo_nombre"
                            data-evaluation-id="${ev.id}"
                            data-assigned="${assigned ? '1' : '0'}"
                            value="${escapeHtml(metadata.grupo_nombre || '')}"
                            placeholder="Ej. Proyecto integrador"
                        >
                    </label>
                    <label class="block text-sm font-medium text-slate-600">
                        Orden
                        <input
                            type="number"
                            min="0"
                            class="evaluation-metadata-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800"
                            data-field="hito_orden"
                            data-evaluation-id="${ev.id}"
                            data-assigned="${assigned ? '1' : '0'}"
                            value="${escapeHtml(metadata.hito_orden || '')}"
                            placeholder="1"
                        >
                    </label>
                    <label class="block text-sm font-medium text-slate-600">
                        Plazo automático
                        <input
                            type="number"
                            min="0"
                            class="evaluation-metadata-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800"
                            data-field="plazo_dias"
                            data-evaluation-id="${ev.id}"
                            data-assigned="${assigned ? '1' : '0'}"
                            value="${escapeHtml(metadata.plazo_dias || '')}"
                            placeholder="7 días después"
                        >
                    </label>
                    <label class="block text-sm font-medium text-slate-600 md:col-span-2">
                        Fecha límite manual
                        <input
                            type="datetime-local"
                            class="evaluation-metadata-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800"
                            data-field="fecha_limite"
                            data-evaluation-id="${ev.id}"
                            data-assigned="${assigned ? '1' : '0'}"
                            value="${escapeHtml(deadlineValue)}"
                        >
                        <span class="mt-1 block text-xs text-slate-500">Si indicas plazo automático, el sistema calculará la fecha desde el fin de la sesión.</span>
                    </label>
                </div>
            `
            : '';

        const assignedAction = assigned
            ? `
                <button
                    type="button"
                    class="inline-flex w-auto items-center justify-center self-start rounded-full border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50"
                    data-remove-evaluation-id="${ev.id}">
                    Quitar
                </button>
            `
            : '';

        if (assigned) {
            return `
                <div class="rounded-2xl border px-5 py-5 shadow-sm transition ${cardClasses}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <span class="mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-indigo-500 text-xs font-bold text-white">
                                ✓
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-xl font-semibold leading-tight text-slate-800">${escapeHtml(ev.nombre || 'Evaluación')}</div>
                                <div class="mt-2 text-sm ${typeTextClass}">
                                    <span class="font-semibold text-slate-600">Tipo:</span> ${escapeHtml(ev.tipo || 'Sin tipo')}
                                </div>
                                ${milestoneBlock}
                            </div>
                        </div>
                        <div class="shrink-0 self-start">
                            ${assignedAction}
                        </div>
                    </div>
                </div>
            `;
        }

        return `
            <div class="rounded-2xl border px-5 py-5 shadow-sm transition ${cardClasses} ${checked && !assigned ? 'ring-2 ring-indigo-100 border-indigo-200' : ''}">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <label class="flex min-w-0 flex-1 items-start gap-3 ${assigned ? '' : 'cursor-pointer'}">
                        <input
                            type="checkbox"
                            class="evaluation-card-checkbox mt-1 h-5 w-5 rounded ${checkboxClasses} focus:ring-indigo-500"
                            data-evaluation-id="${ev.id}"
                            data-assigned="0"
                            ${checked ? 'checked' : ''}>
                        <div class="min-w-0">
                            <div class="text-xl font-semibold leading-tight text-slate-800">${escapeHtml(ev.nombre || 'Evaluación')}</div>
                            <div class="mt-2 text-sm ${typeTextClass}">
                                <span class="font-semibold text-slate-600">Tipo:</span> ${escapeHtml(ev.tipo || 'Sin tipo')}
                            </div>
                        </div>
                    </label>
                    ${milestoneBlock || '<div class="md:w-[280px]"></div>'}
                </div>
            </div>
        `;
    }

    function isWorkType(evaluation) {
        const typeId = Number(evaluation?.tipo_param_id || evaluation?.type_id || 0);
        return typeId === 3 || typeId === 4;
    }

    function normalizeDateTimeForInput(value) {
        if (!value) return '';
        return String(value).replace(' ', 'T').slice(0, 16);
    }

    function normalizeDateTimeForApi(value) {
        if (!value) return null;
        return String(value).trim().slice(0, 16);
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    async function asignarEvaluacionesSeleccionadas(event) {
        event?.preventDefault?.();
        event?.stopPropagation?.();

        const selections = Array.from(sessionEvaluationState.pendingAssignments.values());

        if (!selections.length) return;

        const missingDeadline = selections.find((item) => item.isWork && !item.fecha_limite && !item.plazo_dias);

        if (missingDeadline) {
            setEvaluationSyncStatus('Indica una fecha límite o un plazo automático para cada trabajo seleccionado.', 'error');
            renderAvailableEvaluations();
            return;
        }

        const movedItems = [];

        selections.forEach((item) => {
            const idx = sessionEvaluationContext.evaluacionesDisponibles.findIndex((ev) => ev.id === item.id);

            if (idx >= 0) {
                const ev = {
                    ...sessionEvaluationContext.evaluacionesDisponibles[idx],
                    fecha_limite: item.fecha_limite || null,
                    hito_nombre: item.hito_nombre || null,
                    hito_orden: item.hito_orden || null,
                    grupo_nombre: item.grupo_nombre || null,
                    plazo_dias: item.plazo_dias || null,
                };

                movedItems.push(ev);
                sessionEvaluationContext.evaluacionesAsignadas.push(ev);
                sessionEvaluationContext.evaluacionesDisponibles.splice(idx, 1);
            }
        });

        sessionEvaluationState.pendingAssignments.clear();
        renderAssignedEvaluations();
        renderAvailableEvaluations();
        setEvaluationSyncStatus(
            'Asignando evaluaciones en segundo plano...',
            'pending'
        );

        try {
            const response = await fetch(
                `/backoffice/sessions/${sessionEvaluationContext.sessionId}/evaluation`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            .content
                    },
                    body: JSON.stringify({
                        course_id: sessionEvaluationContext.courseId,
                        evaluaciones: selections.map((item) => ({
                            id: item.id,
                            fecha_limite: item.isWork ? item.fecha_limite : null,
                            hito_nombre: item.hito_nombre || null,
                            hito_orden: item.hito_orden || null,
                            grupo_nombre: item.grupo_nombre || null,
                            plazo_dias: item.plazo_dias || null,
                        }))
                    })
                }
            );

            if (!response.ok) {
                rollbackAssignedEvaluations(movedItems);
                setEvaluationSyncStatus('No se pudieron asignar las evaluaciones.', 'error');
                return;
            }

            setEvaluationSyncStatus('Evaluaciones asignadas correctamente.', 'success');

        } catch (e) {
            rollbackAssignedEvaluations(movedItems);
            setEvaluationSyncStatus('No se pudieron asignar las evaluaciones.', 'error');
            console.error(e);
        }
    }

    async function applyEvaluationPlanTemplate(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const sessionContainer = document.getElementById('sessionEvaluationContext');
        const url = sessionContainer?.dataset.templateUrl || '';
        const partialEvaluationId = form.elements.partial_evaluation_id?.value || '';
        const finalEvaluationId = form.elements.final_evaluation_id?.value || '';

        if (!url) {
            setEvaluationSyncStatus('No se encontró la ruta para aplicar la plantilla.', 'error');
            return;
        }

        if (!partialEvaluationId || !finalEvaluationId) {
            setEvaluationSyncStatus('Selecciona la evaluación parcial y la evaluación final.', 'error');
            return;
        }

        if (partialEvaluationId === finalEvaluationId) {
            setEvaluationSyncStatus('La evaluación parcial y final deben ser diferentes.', 'error');
            return;
        }

        setEvaluationSyncStatus('Aplicando plan de evaluación...', 'info');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document
                        .querySelector('meta[name="csrf-token"]')
                        .content,
                },
                body: JSON.stringify({
                    template: form.elements.template?.value || 'partial_final',
                    partial_evaluation_id: Number(partialEvaluationId),
                    final_evaluation_id: Number(finalEvaluationId),
                    final_extra_days: form.elements.final_extra_days?.value === ''
                        ? 5
                        : Number(form.elements.final_extra_days?.value || 5),
                    group_name: form.elements.group_name?.value || null,
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok || data?.ok === false) {
                setEvaluationSyncStatus(data?.error || 'No se pudo aplicar el plan de evaluación.', 'error');
                return;
            }

            setEvaluationSyncStatus(data?.message || 'Plan de evaluación aplicado.', 'success');

            setTimeout(() => {
                window.location.reload();
            }, 900);
        } catch (error) {
            console.error(error);
            setEvaluationSyncStatus('No se pudo aplicar el plan de evaluación.', 'error');
        }
    }

    function rollbackAssignedEvaluations(items) {
        if (!items.length) return;

        items.forEach((ev) => {
            sessionEvaluationContext.evaluacionesAsignadas = sessionEvaluationContext.evaluacionesAsignadas
                .filter((item) => item.id !== ev.id);

            const exists = sessionEvaluationContext.evaluacionesDisponibles
                .some((item) => item.id === ev.id);

            if (!exists) {
                sessionEvaluationContext.evaluacionesDisponibles.push({
                    ...ev,
                    fecha_limite: null,
                });
            }

            sessionEvaluationState.pendingAssignments.set(Number(ev.id), {
                id: ev.id,
                fecha_limite: normalizeDateTimeForInput(ev.fecha_limite),
                hito_nombre: ev.hito_nombre || null,
                hito_orden: ev.hito_orden || null,
                grupo_nombre: ev.grupo_nombre || null,
                plazo_dias: ev.plazo_dias || null,
                isWork: isWorkType(ev),
            });
        });

        renderAssignedEvaluations();
        renderAvailableEvaluations();
    }

    function setEvaluationSyncStatus(message, type = 'pending') {
        const status = document.getElementById('evaluationSyncStatus');

        if (!status) return;

        if (evaluationSyncStatusTimeoutId) {
            clearTimeout(evaluationSyncStatusTimeoutId);
            evaluationSyncStatusTimeoutId = null;
        }

        if (type === 'pending') {
            status.classList.add('hidden');
            status.textContent = '';
            return;
        }

        status.textContent = message;
        status.classList.remove(
            'hidden',
            'border-amber-200', 'bg-amber-50', 'text-amber-700',
            'border-emerald-200', 'bg-emerald-50', 'text-emerald-700',
            'border-rose-200', 'bg-rose-50', 'text-rose-700'
        );

        if (type === 'success') {
            status.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-700');
            window.invalidateCoursePanel?.('evaluations', sessionEvaluationContext.sessionId);
            window.invalidateCourseWorkspaceSession?.(sessionEvaluationContext.sessionId);
        } else if (type === 'error') {
            status.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-700');
        } else {
            status.classList.add('border-amber-200', 'bg-amber-50', 'text-amber-700');
        }

        evaluationSyncStatusTimeoutId = setTimeout(() => {
            status.classList.add('hidden');
            evaluationSyncStatusTimeoutId = null;
        }, 4000);
    }

    async function eliminarEvaluacion(evaluationId) {
        const idx = sessionEvaluationContext.evaluacionesAsignadas
            .findIndex((e) => e.id === evaluationId);

        if (idx < 0) return;

        const ev = sessionEvaluationContext.evaluacionesAsignadas[idx];

        sessionEvaluationContext.evaluacionesDisponibles.push({
            ...ev,
            fecha_limite: null,
        });
        sessionEvaluationContext.evaluacionesAsignadas.splice(idx, 1);

        sessionEvaluationState.pendingAssignments.delete(Number(evaluationId));
        renderAssignedEvaluations();
        renderAvailableEvaluations();
        setEvaluationSyncStatus(
            'Eliminando evaluación en segundo plano...',
            'pending'
        );

        try {
            const response = await fetch(
                `/backoffice/sessions/${sessionEvaluationContext.sessionId}/evaluation/${evaluationId}`,
                {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            .content
                    },
                    body: JSON.stringify({ course_id: sessionEvaluationContext.courseId })
                }
            );

            if (!response.ok) {
                rollbackRemovedEvaluation(ev);
                setEvaluationSyncStatus('No se pudo eliminar la evaluación.', 'error');
                return;
            }

            setEvaluationSyncStatus('Evaluación eliminada correctamente.', 'success');

        } catch (e) {
            rollbackRemovedEvaluation(ev);
            setEvaluationSyncStatus('No se pudo eliminar la evaluación.', 'error');
            console.error(e);
        }
    }

    function rollbackRemovedEvaluation(item) {
        if (!item) return;

        sessionEvaluationContext.evaluacionesDisponibles = sessionEvaluationContext.evaluacionesDisponibles
            .filter((ev) => ev.id !== item.id);

        const exists = sessionEvaluationContext.evaluacionesAsignadas
            .some((ev) => ev.id === item.id);

        if (!exists) {
            sessionEvaluationContext.evaluacionesAsignadas.push(item);
        }

        renderAssignedEvaluations();
        renderAvailableEvaluations();
    }

    function handleEvaluationCardToggle(target) {
        const evaluationId = Number(target.dataset.evaluationId || 0);
        const assigned = target.dataset.assigned === '1';
        const evaluation = assigned
            ? (sessionEvaluationContext.evaluacionesAsignadas || []).find((ev) => ev.id === evaluationId)
            : (sessionEvaluationContext.evaluacionesDisponibles || []).find((ev) => ev.id === evaluationId);

        if (!evaluation) return;

        if (target.checked) {
            if (!assigned) {
                sessionEvaluationState.pendingAssignments.set(evaluationId, {
                    id: evaluationId,
                    fecha_limite: normalizeDateTimeForInput(evaluation.fecha_limite),
                    hito_nombre: evaluation.hito_nombre || evaluation.nombre || null,
                    hito_orden: evaluation.hito_orden || null,
                    grupo_nombre: evaluation.grupo_nombre || null,
                    plazo_dias: evaluation.plazo_dias || null,
                    isWork: isWorkType(evaluation),
                });
                renderAvailableEvaluations();
            }

            return;
        }

        if (assigned) {
            eliminarEvaluacion(evaluationId);
            return;
        }

        sessionEvaluationState.pendingAssignments.delete(evaluationId);
        renderAvailableEvaluations();
    }

    function normalizeMetadataValue(field, value) {
        if (field === 'fecha_limite') {
            return normalizeDateTimeForApi(value);
        }

        if (field === 'hito_orden' || field === 'plazo_dias') {
            const number = Number(value);
            return Number.isFinite(number) && number >= 0 ? number : null;
        }

        const text = String(value || '').trim();
        return text === '' ? null : text;
    }

    function handleEvaluationMetadataInput(target) {
        const evaluationId = Number(target.dataset.evaluationId || 0);
        const assigned = target.dataset.assigned === '1';
        const field = target.dataset.field || 'fecha_limite';
        const value = normalizeMetadataValue(field, target.value);

        if (assigned) {
            updateAssignedEvaluationMetadata(evaluationId, { [field]: value }, target);
            return;
        }

        const current = sessionEvaluationState.pendingAssignments.get(evaluationId);

        if (!current) return;

        sessionEvaluationState.pendingAssignments.set(evaluationId, {
            ...current,
            [field]: value,
        });
    }

    async function updateAssignedEvaluationMetadata(evaluationId, metadata, inputElement) {
        const evaluation = (sessionEvaluationContext.evaluacionesAsignadas || []).find((ev) => ev.id === evaluationId);

        if (!evaluation) return;

        const field = Object.keys(metadata)[0];
        const nextValue = metadata[field];
        const previousValue = field === 'fecha_limite'
            ? normalizeDateTimeForApi(evaluation.fecha_limite)
            : (evaluation[field] ?? null);

        if (field === 'fecha_limite' && !nextValue && isWorkType(evaluation) && !evaluation.plazo_dias) {
            if (inputElement) {
                inputElement.value = normalizeDateTimeForInput(previousValue);
            }
            setEvaluationSyncStatus('Indica una fecha límite o un plazo automático.', 'error');
            return;
        }

        if (String(nextValue || '') === String(previousValue || '')) {
            if (inputElement) {
                inputElement.value = field === 'fecha_limite'
                    ? normalizeDateTimeForInput(previousValue)
                    : (previousValue || '');
            }
            return;
        }

        evaluation[field] = nextValue;
        setEvaluationSyncStatus('Actualizando hito en segundo plano...', 'pending');

        try {
            const response = await fetch(
                `/backoffice/sessions/${sessionEvaluationContext.sessionId}/evaluation/${evaluationId}`,
                {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            .content
                    },
                    body: JSON.stringify({
                        course_id: sessionEvaluationContext.courseId,
                        ...metadata,
                    })
                }
            );

            if (!response.ok) {
                evaluation[field] = previousValue;
                if (inputElement) {
                    inputElement.value = field === 'fecha_limite'
                        ? normalizeDateTimeForInput(previousValue)
                        : (previousValue || '');
                }
                setEvaluationSyncStatus('No se pudo actualizar el hito.', 'error');
                return;
            }

            setEvaluationSyncStatus('Hito actualizado correctamente.', 'success');
        } catch (error) {
            evaluation[field] = previousValue;
            if (inputElement) {
                inputElement.value = field === 'fecha_limite'
                    ? normalizeDateTimeForInput(previousValue)
                    : (previousValue || '');
            }
            setEvaluationSyncStatus('No se pudo actualizar el hito.', 'error');
            console.error(error);
        }
    }

    window.openCreateEvaluationModal = openCreateEvaluationModal;
    window.closeCreateEvaluationModal = closeCreateEvaluationModal;
    window.duplicateEvaluation = duplicateEvaluation;
    window.asignarEvaluacionesSeleccionadas = asignarEvaluacionesSeleccionadas;
    window.eliminarEvaluacion = eliminarEvaluacion;
    window.initSessionEvaluations = initSessionEvaluations;

    document.addEventListener('change', (event) => {
        if (event.target.matches('.evaluation-card-checkbox')) {
            handleEvaluationCardToggle(event.target);
            return;
        }

        if (event.target.matches('.evaluation-metadata-input')) {
            handleEvaluationMetadataInput(event.target);
        }
    });

    document.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-evaluation-id]');

        if (removeButton) {
            eliminarEvaluacion(Number(removeButton.dataset.removeEvaluationId));
            return;
        }

        const duplicateButton = event.target.closest('[data-duplicate-evaluation-id]');

        if (!duplicateButton) {
            return;
        }

        duplicateEvaluation(
            Number(duplicateButton.dataset.duplicateEvaluationId || 0),
            Number(duplicateButton.dataset.duplicateTypeId || 0)
        );
    });
