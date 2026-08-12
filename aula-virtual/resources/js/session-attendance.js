const labels = {
    asistio: 'Asistió',
    presente: 'Presente',
    tardanza: 'Tardanza',
    falta: 'Falta',
    justificada: 'Justificada',
    no_aplica: 'No aplica',
};

const normalize = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

window.initSessionAttendance = (scope = document) => {
    const panel = scope.matches?.('[data-session-attendance-panel]')
        ? scope
        : scope.querySelector?.('[data-session-attendance-panel]');
    if (!panel || panel.dataset.attendanceInitialized === 'true') return;
    panel.dataset.attendanceInitialized = 'true';

    const search = panel.querySelector('[data-attendance-search]');
    const status = panel.querySelector('[data-attendance-status-filter]');
    const rows = [...panel.querySelectorAll('[data-attendance-row]')];
    const count = panel.querySelector('[data-attendance-visible-count]');
    const noResults = panel.querySelector('[data-attendance-no-results]');
    const feedback = panel.querySelector('[data-attendance-feedback]');
    const dialog = panel.querySelector('[data-attendance-dialog]');
    const correctionForm = panel.querySelector('[data-attendance-correction-form]');
    const correctionStatus = panel.querySelector('[data-attendance-correction-status]');
    const participant = panel.querySelector('[data-attendance-participant]');
    const studentDetails = panel.querySelector('[data-attendance-students]');
    const refreshUrl = panel.dataset.attendanceRefreshUrl;
    const storageKey = `session-attendance:${panel.dataset.attendanceSessionId || 'current'}`;
    let dialogTrigger = null;

    const saveViewState = () => {
        sessionStorage.setItem(storageKey, JSON.stringify({
            open: Boolean(studentDetails?.open),
            search: search?.value || '',
            status: status?.value || '',
        }));
    };

    const setFeedback = (message, error = false) => {
        const currentPanel = document.querySelector('[data-session-attendance-panel]');
        const target = currentPanel?.querySelector('[data-attendance-feedback]') || feedback;
        if (!target) return;
        target.textContent = message;
        target.hidden = false;
        target.classList.toggle('is-error', error);
        target.focus?.({ preventScroll: true });
    };

    const filterRows = () => {
        const query = normalize(search?.value);
        const selectedStatus = status?.value || '';
        let visible = 0;
        rows.forEach((row) => {
            const matchesSearch = query === '' || normalize(row.dataset.search).includes(query);
            const matchesStatus = selectedStatus === '' || row.dataset.status === selectedStatus;
            row.hidden = !(matchesSearch && matchesStatus);
            if (!row.hidden) visible += 1;
        });
        if (count) count.textContent = `${visible} ${visible === 1 ? 'alumno' : 'alumnos'}`;
        if (noResults) noResults.hidden = visible !== 0;
    };

    const closeDialog = () => {
        if (!dialog?.open) return;
        dialog.close();
        dialogTrigger?.focus();
    };

    const openCorrection = (button) => {
        if (!dialog || !correctionForm || !correctionStatus) return;
        dialogTrigger = button;
        correctionForm.action = button.dataset.action;
        correctionForm.reset();
        if (participant) participant.textContent = button.dataset.participant || '';
        const states = button.dataset.type === 'docente'
            ? ['presente', 'tardanza', 'falta', 'justificada', 'no_aplica']
            : ['asistio', 'falta', 'justificada', 'no_aplica'];
        correctionStatus.replaceChildren(...states.map((state) => {
            const option = document.createElement('option');
            option.value = state;
            option.textContent = labels[state];
            option.selected = state === button.dataset.status;
            return option;
        }));
        dialog.showModal();
        requestAnimationFrame(() => correctionStatus.focus());
    };

    const submitAction = async (form) => {
        const submit = form.querySelector('[type="submit"]');
        submit?.setAttribute('disabled', '');
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) {
                const validation = Object.values(data.errors || {}).flat()[0];
                throw new Error(validation || data.message || 'No se pudo completar la accion.');
            }
            closeDialog();
            if (window.reloadCoursePanel) {
                window.invalidateCoursePanel?.('attendance');
                await window.reloadCoursePanel('attendance');
            } else if (refreshUrl) {
                const refreshResponse = await fetch(refreshUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const refreshed = await refreshResponse.json().catch(() => ({}));
                if (!refreshResponse.ok || refreshed.ok === false || !refreshed.html) {
                    throw new Error(refreshed.message || 'La asistencia se actualizó, pero no se pudo refrescar el detalle.');
                }
                panel.outerHTML = refreshed.html;
                window.initSessionAttendance(document);
            }
            setFeedback(data.message || 'Asistencia actualizada.');
        } catch (error) {
            setFeedback(error.message || 'No se pudo completar la accion.', true);
        } finally {
            submit?.removeAttribute('disabled');
        }
    };

    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
        if (studentDetails && saved.open) studentDetails.open = true;
        if (search && typeof saved.search === 'string') search.value = saved.search;
        if (status && typeof saved.status === 'string') status.value = saved.status;
    } catch (_) {
        sessionStorage.removeItem(storageKey);
    }
    filterRows();

    search?.addEventListener('input', () => {
        filterRows();
        saveViewState();
    });
    status?.addEventListener('change', () => {
        filterRows();
        saveViewState();
    });
    studentDetails?.addEventListener('toggle', saveViewState);
    panel.addEventListener('click', (event) => {
        const correction = event.target.closest('[data-attendance-correct]');
        if (correction) openCorrection(correction);
        if (event.target.closest('[data-attendance-dialog-close]')) closeDialog();
    });
    panel.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-attendance-action]');
        if (!form) return;
        event.preventDefault();
        submitAction(form);
    });
    dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog();
    });
    dialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeDialog();
    });
};

const bootAttendance = () => window.initSessionAttendance?.(document);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAttendance, { once: true });
} else {
    bootAttendance();
}
