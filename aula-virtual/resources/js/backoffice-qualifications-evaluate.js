function formatQualificationScore(value) {
    if (value === null || Number.isNaN(value)) {
        return '--';
    }

    const formatted = Number(value).toFixed(2);
    return formatted.replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
}

function scoreFromLevel(level, maxScore) {
    if (!level || !maxScore || maxScore <= 0) {
        return null;
    }

    return (maxScore * (level - 1)) / 4;
}

const STUDENT_STATUS_CONFIG = {
    missing: {
        stateText: 'Sin entrega',
        avatarClass: 'qualification-review-student-avatar--missing',
        indicatorClass: 'qualification-review-student-indicator--missing',
        stateClass: 'qualification-review-student-state--missing',
    },
    pending: {
        stateText: 'Pendiente',
        avatarClass: 'qualification-review-student-avatar--pending',
        indicatorClass: 'qualification-review-student-indicator--pending',
        stateClass: 'qualification-review-student-state--pending',
    },
    reviewing: {
        stateText: 'Pendiente',
        avatarClass: 'qualification-review-student-avatar--pending',
        indicatorClass: 'qualification-review-student-indicator--pending',
        stateClass: 'qualification-review-student-state--pending',
    },
    corrected: {
        stateText: 'Corregido',
        avatarClass: 'qualification-review-student-avatar--corrected',
        indicatorClass: 'qualification-review-student-indicator--corrected',
        stateClass: 'qualification-review-student-state--corrected',
    },
};

function syncQualificationReview() {
    const form = document.getElementById('qualificationReviewForm');

    if (!form) {
        return;
    }

    const rows = Array.from(form.querySelectorAll('[data-review-criterion]'));
    const totalTargets = Array.from(document.querySelectorAll('[data-review-total-score]'));
    const submitButtons = Array.from(document.querySelectorAll('button[form="qualificationReviewForm"]'));
    const totalMax = rows.reduce((sum, row) => sum + Number(row.dataset.maxScore || 0), 0);

    const update = () => {
        let total = 0;
        let selectedCount = 0;

        rows.forEach((row) => {
            const maxScore = Number(row.dataset.maxScore || 0);
            const selected = row.querySelector('input[type="radio"]:checked');
            const scoreTarget = row.querySelector('[data-review-criterion-score]');

            if (selected) {
                selectedCount += 1;
                const score = scoreFromLevel(Number(selected.value), maxScore);
                total += score || 0;

                if (scoreTarget) {
                    scoreTarget.textContent = `${formatQualificationScore(score)}/${formatQualificationScore(maxScore)}`;
                }
            } else if (scoreTarget) {
                scoreTarget.textContent = `--/${formatQualificationScore(maxScore)}`;
            }
        });

        totalTargets.forEach((target) => {
            target.textContent = `${formatQualificationScore(total)}/${formatQualificationScore(totalMax)}`;
        });

        submitButtons.forEach((button) => {
            if (!button.classList.contains('is-saving')) {
                button.disabled = rows.length === 0 || selectedCount !== rows.length;
            }
        });
    };

    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.type === 'radio') {
            update();
        }
    });

    update();
}

function syncParticipantSearch() {
    const searchRoot = document.querySelector('[data-student-search]');
    const list = document.querySelector('[data-student-list]');

    if (!searchRoot || !list) {
        return;
    }

    const input = searchRoot.querySelector('[data-student-search-input]');
    const cards = Array.from(list.querySelectorAll('[data-student-card]'));
    const searchEmpty = list.querySelector('[data-student-search-empty]');
    const countTarget = document.querySelector('[data-student-count]');
    const totalStudents = Number(countTarget?.dataset.totalStudents || cards.length);

    if (!(input instanceof HTMLInputElement) || cards.length === 0) {
        return;
    }

    const update = () => {
        const query = input.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        cards.forEach((card) => {
            const name = card.dataset.studentName || '';
            const matches = query === '' || name.includes(query);

            card.hidden = !matches;

            if (matches) {
                visibleCount += 1;
            }
        });

        if (searchEmpty) {
            searchEmpty.hidden = visibleCount > 0;
        }

        if (countTarget) {
            countTarget.textContent = query === ''
                ? `${totalStudents} estudiantes`
                : `${visibleCount} de ${totalStudents} estudiantes`;
        }
    };

    input.addEventListener('input', update);
    input.addEventListener('search', update);
    update();
}

function updateActiveStudentCard(url) {
    const currentUrl = new URL(url, window.location.origin);
    const currentDeliveryId = currentUrl.searchParams.get('entregaId') || '';
    const cards = Array.from(document.querySelectorAll('[data-student-card]'));

    cards.forEach((card) => {
        const deliveryId = card.dataset.deliveryId || '';
        const isActive = currentDeliveryId !== '' && deliveryId === currentDeliveryId;

        card.classList.toggle('qualification-review-student-card--active', isActive);
    });
}

function getActiveStudentCard() {
    return document.querySelector('.qualification-review-student-card--active[data-student-card]');
}

function scrollActiveStudentCardIntoView() {
    const activeCard = getActiveStudentCard();

    if (!(activeCard instanceof HTMLElement)) {
        return;
    }

    activeCard.scrollIntoView({
        block: 'nearest',
        behavior: 'smooth',
    });
}

function scrollReviewPanelsIntoView() {
    const reviewMain = document.querySelector('[data-review-main]');

    if (!(reviewMain instanceof HTMLElement) || window.innerWidth > 1279) {
        return;
    }

    reviewMain.scrollIntoView({
        block: 'start',
        behavior: 'smooth',
    });
}

function createLoadingOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'qualification-review-loading-overlay';
    overlay.hidden = true;
    overlay.innerHTML = `
        <div class="qualification-review-loading-card" role="status" aria-live="polite">
            <span class="qualification-review-loading-spinner" aria-hidden="true"></span>
            <strong data-loading-title>Cargando entrega</strong>
            <span data-loading-description>Estamos actualizando la información.</span>
        </div>
    `;

    document.body.appendChild(overlay);

    return overlay;
}

function setLoadingOverlayState(overlay, title, description) {
    const titleTarget = overlay.querySelector('[data-loading-title]');
    const descriptionTarget = overlay.querySelector('[data-loading-description]');

    if (titleTarget) {
        titleTarget.textContent = title;
    }

    if (descriptionTarget) {
        descriptionTarget.textContent = description;
    }
}

function renderReviewFeedback(type, message) {
    const feedbackRoot = document.querySelector('[data-review-feedback]');

    if (!feedbackRoot) {
        return;
    }

    if (!message) {
        feedbackRoot.replaceChildren();
        return;
    }

    const alert = document.createElement('div');
    alert.className = type === 'success'
        ? 'mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700'
        : 'mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700';
    alert.textContent = message;

    feedbackRoot.replaceChildren(alert);
}

function applyStudentCardStatus(card, statusKey) {
    const config = STUDENT_STATUS_CONFIG[statusKey];

    if (!config || !(card instanceof HTMLElement)) {
        return;
    }

    const avatar = card.querySelector('[data-student-avatar]');
    const indicator = card.querySelector('[data-student-indicator]');
    const state = card.querySelector('[data-student-state]');

    card.dataset.statusKey = statusKey;

    if (avatar) {
        avatar.classList.remove(
            'qualification-review-student-avatar--missing',
            'qualification-review-student-avatar--pending',
            'qualification-review-student-avatar--corrected'
        );
        avatar.classList.add(config.avatarClass);
    }

    if (indicator) {
        indicator.classList.remove(
            'qualification-review-student-indicator--missing',
            'qualification-review-student-indicator--pending',
            'qualification-review-student-indicator--corrected'
        );
        indicator.classList.add(config.indicatorClass);
    }

    if (state) {
        state.classList.remove(
            'qualification-review-student-state--missing',
            'qualification-review-student-state--pending',
            'qualification-review-student-state--corrected'
        );
        state.classList.add(config.stateClass);
        state.textContent = config.stateText;
    }
}

function updateReviewSummaryCounts(previousStatus, nextStatus) {
    if (!previousStatus || previousStatus === nextStatus) {
        return;
    }

    const correctedTarget = document.querySelector('[data-review-corrected-count]');
    const pendingTarget = document.querySelector('[data-review-pending-count]');

    if (!(correctedTarget instanceof HTMLElement) || !(pendingTarget instanceof HTMLElement)) {
        return;
    }

    let correctedCount = Number(correctedTarget.textContent || 0);
    let pendingCount = Number(pendingTarget.textContent || 0);
    const pendingStatuses = new Set(['pending', 'reviewing']);

    if (previousStatus !== 'corrected' && nextStatus === 'corrected') {
        correctedCount += 1;
    } else if (previousStatus === 'corrected' && nextStatus !== 'corrected') {
        correctedCount = Math.max(0, correctedCount - 1);
    }

    if (pendingStatuses.has(previousStatus) && !pendingStatuses.has(nextStatus)) {
        pendingCount = Math.max(0, pendingCount - 1);
    } else if (!pendingStatuses.has(previousStatus) && pendingStatuses.has(nextStatus)) {
        pendingCount += 1;
    }

    correctedTarget.textContent = String(correctedCount);
    pendingTarget.textContent = String(pendingCount);
}

function setReviewButtonsSavingState(isSaving) {
    const buttons = Array.from(document.querySelectorAll('button[form="qualificationReviewForm"]'));

    buttons.forEach((button) => {
        button.classList.toggle('is-saving', isSaving);
        button.disabled = isSaving || button.disabled;
    });

    if (!isSaving) {
        syncQualificationReview();
    }
}

function buildSubmitterValue(form) {
    if (!(form instanceof HTMLFormElement)) {
        return 'stay';
    }

    return form.dataset.submitterValue || 'stay';
}

function syncReviewExperience() {
    const overlay = createLoadingOverlay();
    let activeRequestId = 0;

    const replaceReviewContent = (doc, url, shouldPushState) => {
        const nextTopbar = doc.querySelector('[data-review-topbar]');
        const nextMain = doc.querySelector('[data-review-main]');
        const nextRubric = doc.querySelector('[data-review-rubric]');
        const currentTopbar = document.querySelector('[data-review-topbar]');
        const currentMain = document.querySelector('[data-review-main]');
        const currentRubric = document.querySelector('[data-review-rubric]');

        if (
            !nextTopbar || !nextMain || !nextRubric ||
            !currentTopbar || !currentMain || !currentRubric
        ) {
            window.location.href = url;
            return;
        }

        currentTopbar.replaceWith(nextTopbar);
        currentMain.replaceWith(nextMain);
        currentRubric.replaceWith(nextRubric);
        updateActiveStudentCard(url);
        scrollActiveStudentCardIntoView();
        scrollReviewPanelsIntoView();

        if (shouldPushState) {
            window.history.pushState({ url }, '', url);
        }

        syncQualificationReview();
    };

    const loadReview = async (url, shouldPushState, loadingCopy = null) => {
        const requestId = ++activeRequestId;
        const title = loadingCopy?.title || 'Cargando entrega';
        const description = loadingCopy?.description || 'Estamos actualizando la información.';

        setLoadingOverlayState(overlay, title, description);
        overlay.hidden = false;
        document.body.classList.add('qualification-review-loading');

        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }

            const html = await response.text();

            if (requestId !== activeRequestId) {
                return;
            }

            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            replaceReviewContent(doc, url, shouldPushState);
        } catch (error) {
            window.location.href = url;
        } finally {
            if (requestId === activeRequestId) {
                overlay.hidden = true;
                document.body.classList.remove('qualification-review-loading');
            }
        }
    };

    const saveReview = async (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const activeCard = getActiveStudentCard();
        const previousStatus = activeCard?.dataset.statusKey || '';
        const formData = new FormData(form);
        const saveAction = buildSubmitterValue(form);

        formData.set('save_action', saveAction);

        // This form is handled with fetch. Clear any legacy/global loader that
        // may have been activated by a stale bundle or another submit handler.
        window.hideGlobalLoader?.();

        setReviewButtonsSavingState(true);

        if (activeCard) {
            applyStudentCardStatus(activeCard, 'corrected');
            updateReviewSummaryCounts(previousStatus, 'corrected');
        }

        setLoadingOverlayState(
            overlay,
            'Guardando calificacion',
            'Estamos registrando la revision.'
        );
        overlay.hidden = false;
        document.body.classList.add('qualification-review-loading');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            const payload = await response.json();

            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'No se pudo guardar la calificacion.');
            }

            renderReviewFeedback('success', payload.message || 'Calificacion guardada correctamente.');

            const savedDeliveryId = Number(payload.saved_delivery_id || 0);
            const redirectDeliveryId = Number(payload.redirect_delivery_id || 0);
            const shouldLoadNext = saveAction === 'next'
                && redirectDeliveryId > 0
                && redirectDeliveryId !== savedDeliveryId;

            if (shouldLoadNext) {
                await loadReview(payload.redirect_url || window.location.href, true, {
                    title: 'Cargando siguiente entrega',
                    description: 'Estamos preparando la siguiente revision.',
                });
            }
        } catch (error) {
            if (activeCard) {
                applyStudentCardStatus(activeCard, previousStatus || 'pending');
                updateReviewSummaryCounts('corrected', previousStatus || 'pending');
            }

            renderReviewFeedback('error', error.message || 'No se pudo guardar la calificacion.');
        } finally {
            overlay.hidden = true;
            document.body.classList.remove('qualification-review-loading');
            setReviewButtonsSavingState(false);
            window.hideGlobalLoader?.();
        }
    };

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const link = target.closest('a[data-review-navigation]');

        if (link instanceof HTMLAnchorElement && link.href && link.getAttribute('href') !== '#') {
            if (
                event.defaultPrevented ||
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }

            event.preventDefault();
            loadReview(link.href, true);
            return;
        }

        if (!(target instanceof HTMLButtonElement)) {
            return;
        }

        if (target.form?.id !== 'qualificationReviewForm' || target.type !== 'submit') {
            return;
        }

        target.form.dataset.submitterValue = target.value || 'stay';
    });

    document.addEventListener('submit', (event) => {
        const target = event.target;

        if (!(target instanceof HTMLFormElement) || target.id !== 'qualificationReviewForm') {
            return;
        }

        event.preventDefault();
        saveReview(target);
    });

    window.addEventListener('popstate', () => {
        loadReview(window.location.href, false);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    syncQualificationReview();
    syncParticipantSearch();
    syncReviewExperience();
    updateActiveStudentCard(window.location.href);
    scrollActiveStudentCardIntoView();
});
