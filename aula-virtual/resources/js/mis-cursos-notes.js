function initMisCursosNotes(scope = document) {
    const root = scope.querySelector?.('[data-notes-panel]') ?? document.querySelector('[data-notes-panel]');

    if (!root || root.dataset.notesInitialized === 'true') {
        return;
    }

    root.dataset.notesInitialized = 'true';

    const courseId = root.dataset.courseId;
    const endpoint = `/mis-cursos/${courseId}/notas`;

    const loader = root.querySelector('[data-notes-loader]');
    const errorBox = root.querySelector('[data-notes-error]');
    const errorMessage = root.querySelector('[data-notes-error-message]');
    const emptyBox = root.querySelector('[data-notes-empty]');
    const content = root.querySelector('[data-notes-content]');
    const tableBody = root.querySelector('[data-notes-table-body]');
    const notesCount = root.querySelector('[data-notes-count]');
    const weightedAverage = root.querySelector('[data-weighted-average]');
    const weightedAverageFooter = root.querySelector('[data-weighted-average-footer]');
    const retryBtn = root.querySelector('[data-retry-notes]');

    if (!courseId || !loader || !errorBox || !emptyBox || !content || !tableBody || !retryBtn) {
        return;
    }

    function showState(state) {
        loader.classList.toggle('hidden', state !== 'loading');
        errorBox.classList.toggle('hidden', state !== 'error');
        emptyBox.classList.toggle('hidden', state !== 'empty');
        content.classList.toggle('hidden', state !== 'content');
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(value) {
        if (!value) {
            return '--';
        }

        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('es-PE', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(parsed);
    }

    function formatNumber(value) {
        const numericValue = Number(value);
        if (!Number.isFinite(numericValue)) {
            return '--';
        }

        return Number.isInteger(numericValue)
            ? String(numericValue)
            : numericValue.toFixed(2);
    }

    function getNoteTone(note) {
        const numericNote = Number(note);

        if (!Number.isFinite(numericNote)) {
            return 'bg-slate-100 text-slate-600 border-slate-200';
        }

        if (numericNote >= 18) {
            return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        }

        if (numericNote >= 14) {
            return 'bg-blue-50 text-blue-700 border-blue-200';
        }

        if (numericNote >= 11) {
            return 'bg-amber-50 text-amber-700 border-amber-200';
        }

        return 'bg-red-50 text-red-700 border-red-200';
    }

    function calculateWeightedAverage(items) {
        const validItems = items.filter((item) => Number.isFinite(Number(item.nota)) && Number.isFinite(Number(item.peso)));

        if (!validItems.length) {
            return null;
        }

        const weightedSum = validItems.reduce((sum, item) => sum + (Number(item.nota) * Number(item.peso)), 0);
        const totalWeight = validItems.reduce((sum, item) => sum + Number(item.peso), 0);

        return totalWeight > 0 ? weightedSum / totalWeight : null;
    }

    function buildCriteriaTable(criteria) {
        const rows = criteria.map((item) => `
            <tr class="border-t border-slate-100">
                <td class="px-4 py-3 text-sm font-medium text-slate-700">${escapeHtml(item.criterio || '--')}</td>
                <td class="px-4 py-3 text-sm text-slate-500">${escapeHtml(formatNumber(item.peso_criterio))}%</td>
                <td class="px-4 py-3 text-sm text-slate-500">${escapeHtml(formatNumber(item.puntaje))}</td>
                <td class="px-4 py-3 text-sm text-slate-500">${escapeHtml(item.comentario || '--')}</td>
            </tr>
        `).join('');

        return `
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="mb-3 text-sm font-semibold text-slate-700">Detalle por criterios</div>
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Criterio</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Peso %</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Puntaje</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Comentario</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            ${rows}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    function renderNotes(items) {
        tableBody.innerHTML = '';

        const average = calculateWeightedAverage(items);
        notesCount.textContent = String(items.length);
        weightedAverage.textContent = average === null ? '--' : formatNumber(average);
        weightedAverageFooter.textContent = average === null ? '--' : formatNumber(average);

        const fragment = document.createDocumentFragment();

        items.forEach((item, index) => {
            const criteria = Array.isArray(item.criterios) ? item.criterios : [];
            const detailId = `note-detail-${index}`;
            const canExpand = criteria.length > 0;
            const row = document.createElement('tr');

            row.className = 'hover:bg-slate-50/80 transition';
            row.innerHTML = `
                <td class="px-6 py-5 align-top">
                    <div class="font-semibold text-slate-800">${escapeHtml(item.evaluacion || '--')}</div>
                </td>
                <td class="px-6 py-5 align-top">
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        ${escapeHtml(item.tipo_evaluacion || '--')}
                    </span>
                </td>
                <td class="px-6 py-5 align-top text-sm text-slate-500">
                    ${escapeHtml(formatDate(item.fecha))}
                </td>
                <td class="px-6 py-5 align-top">
                    <span class="inline-flex min-w-[64px] items-center justify-center rounded-full border px-3 py-1 text-sm font-bold ${getNoteTone(item.nota)}">
                        ${escapeHtml(formatNumber(item.nota))}
                    </span>
                </td>
                <td class="px-6 py-5 align-top text-sm font-semibold text-slate-600">
                    ${escapeHtml(formatNumber(item.peso))}%
                </td>
                <td class="px-6 py-5 align-top">
                    ${canExpand
                        ? `<button type="button" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:text-blue-700" data-toggle-detail="${detailId}" aria-expanded="false">Ver detalle</button>`
                        : `<span class="inline-flex items-center rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-400">Sin detalle</span>`}
                </td>
            `;

            fragment.appendChild(row);

            if (canExpand) {
                const detailRow = document.createElement('tr');
                detailRow.id = detailId;
                detailRow.className = 'hidden bg-white';
                detailRow.innerHTML = `
                    <td colspan="6" class="px-6 pb-5 pt-0">
                        ${buildCriteriaTable(criteria)}
                    </td>
                `;
                fragment.appendChild(detailRow);
            }
        });

        tableBody.appendChild(fragment);

        tableBody.querySelectorAll('[data-toggle-detail]').forEach((button) => {
            button.addEventListener('click', function () {
                const detailRow = document.getElementById(this.dataset.toggleDetail);
                if (!detailRow) {
                    return;
                }

                const isHidden = detailRow.classList.contains('hidden');
                detailRow.classList.toggle('hidden', !isHidden);
                this.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
                this.textContent = isHidden ? 'Ocultar detalle' : 'Ver detalle';
            });
        });
    }

    async function loadNotes() {
        showState('loading');

        try {
            const response = await fetch(endpoint, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'No se pudieron cargar las notas.');
            }

            const notes = Array.isArray(payload.notas) ? payload.notas : [];

            if (!notes.length) {
                notesCount.textContent = '0';
                weightedAverage.textContent = '--';
                weightedAverageFooter.textContent = '--';
                showState('empty');
                return;
            }

            renderNotes(notes);
            showState('content');
        } catch (error) {
            errorMessage.textContent = error.message || 'No se pudieron cargar las notas.';
            showState('error');
        }
    }

    retryBtn.addEventListener('click', loadNotes);
    loadNotes();
}

window.initMisCursosNotes = initMisCursosNotes;

document.addEventListener('DOMContentLoaded', () => {
    initMisCursosNotes(document);
});
