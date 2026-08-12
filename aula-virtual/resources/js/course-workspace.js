import './session-attendance.js';

const root = document.querySelector('[data-course-workspace-root]');

if (root) {
    let chatModulePromise = null;
    const ensureCommunityModule = () => {
        if (window.initCommunityPanels) return Promise.resolve();
        chatModulePromise ||= import('./chat.js');
        return chatModulePromise;
    };

    const workspace = root.querySelector('#courseWorkspace');
    const sidebar = root.querySelector('#courseSessionSidebar');
    const sessionList = root.querySelector('[data-session-list]');
    const searchInput = root.querySelector('#courseSessionSearch');
    const clearSearch = root.querySelector('[data-clear-session-search]');
    const filterButtons = [...root.querySelectorAll('[data-session-filter]')];
    const noResults = root.querySelector('[data-session-no-results]');
    const workspaceCache = new Map();
    const panelCache = new Map();
    const browserCachePrefix = `course-workspace:v2:${root.dataset.workspaceContext || 'default'}:${root.dataset.courseId}:`;
    let activeFilter = 'all';
    let workspaceController = null;
    let panelController = null;
    let certificatePublicUrl = '';
    const certificateModal = root.querySelector('[data-certificate-modal]');
    const certificatePreviewBody = root.querySelector('[data-certificate-preview-body]');
    const certificateDownload = root.querySelector('[data-certificate-download]');
    const certificateCopy = root.querySelector('[data-certificate-copy]');

    const readBrowserCache = (key) => {
        try {
            const raw = sessionStorage.getItem(browserCachePrefix + key);
            if (!raw) return null;
            const item = JSON.parse(raw);
            if (!item?.expiresAt || item.expiresAt < Date.now()) {
                sessionStorage.removeItem(browserCachePrefix + key);
                return null;
            }
            return item.value;
        } catch {
            return null;
        }
    };

    const writeBrowserCache = (key, value, ttlSeconds = 60) => {
        try {
            sessionStorage.setItem(browserCachePrefix + key, JSON.stringify({
                expiresAt: Date.now() + ttlSeconds * 1000,
                value,
            }));
        } catch {
            // Storage quota or private mode should not break navigation.
        }
    };

    window.invalidateCourseWorkspaceSession = (sessionId = root.dataset.sessionId) => {
        workspaceCache.delete(String(sessionId));
        try {
            sessionStorage.removeItem(browserCachePrefix + `workspace:${sessionId}`);
        } catch {}
    };
    window.invalidateCoursePanel = (panel, sessionId = root.dataset.sessionId) => {
        panelCache.delete(panelKey(sessionId, panel));
        try {
            sessionStorage.removeItem(browserCachePrefix + `panel:${panelKey(sessionId, panel)}`);
        } catch {}
    };

    const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();

    const templateUrl = (template, replacements) => Object.entries(replacements)
        .reduce((url, [key, value]) => url.replace(key, encodeURIComponent(value)), template);

    const updateSessionFilter = () => {
        const query = normalize(searchInput?.value);
        let visible = 0;

        sessionList?.querySelectorAll('[data-session-link]').forEach((link) => {
            const matchesText = query === '' || normalize(link.dataset.sessionSearch).includes(query);
            const states = (link.dataset.sessionState || '').split(' ');
            const matchesState = activeFilter === 'all' || states.includes(activeFilter);
            link.hidden = !(matchesText && matchesState);
            if (!link.hidden) visible += 1;
        });

        if (clearSearch) clearSearch.hidden = query === '';
        if (noResults) noResults.hidden = visible !== 0;
    };

    const setSessionDrawer = (open, moveFocus = true) => {
        const backdrop = root.querySelector('[data-session-drawer-backdrop]');
        const trigger = root.querySelector('[data-open-session-drawer]');
        const close = sidebar?.querySelector('[data-close-session-drawer]');
        const isMobile = window.matchMedia('(max-width: 900px)').matches;

        if (!isMobile) {
            sidebar?.classList.remove('is-open');
            sidebar?.removeAttribute('inert');
            sidebar?.removeAttribute('aria-hidden');
            backdrop?.setAttribute('hidden', '');
            trigger?.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('course-drawer-open');
            return;
        }

        if (!open && sidebar?.contains(document.activeElement) && moveFocus) trigger?.focus();
        sidebar?.classList.toggle('is-open', open);
        sidebar?.toggleAttribute('inert', !open);
        sidebar?.setAttribute('aria-hidden', open ? 'false' : 'true');
        backdrop?.toggleAttribute('hidden', !open);
        trigger?.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.classList.toggle('course-drawer-open', open);
        if (open && moveFocus) requestAnimationFrame(() => close?.focus());
    };

    const panelKey = (sessionId, panel) => `${sessionId}:${panel}`;

    const copyText = async (text) => {
        if (!text) return false;
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            const copied = document.execCommand('copy');
            textarea.remove();
            return copied;
        }
    };

    const renderCertificatePreview = (url, title) => {
        if (!certificatePreviewBody) return;
        const cleanUrl = String(url || '');
        const extension = cleanUrl.split('?')[0].split('#')[0].split('.').pop()?.toLowerCase() || '';
        const imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        certificatePreviewBody.replaceChildren();

        if (imageExtensions.includes(extension)) {
            const image = document.createElement('img');
            image.src = cleanUrl;
            image.alt = title || 'Certificado';
            certificatePreviewBody.appendChild(image);
            return;
        }

        const frame = document.createElement('iframe');
        frame.src = cleanUrl;
        frame.title = title || 'Vista previa del certificado';
        certificatePreviewBody.appendChild(frame);
    };

    const openCertificateModal = (trigger) => {
        const previewUrl = trigger.dataset.previewUrl || trigger.dataset.publicUrl || '';
        const downloadUrl = trigger.dataset.downloadUrl || trigger.dataset.publicUrl || '';
        certificatePublicUrl = trigger.dataset.publicUrl || downloadUrl;

        if (!previewUrl || !certificateModal) {
            if (downloadUrl) window.open(downloadUrl, '_blank', 'noopener,noreferrer');
            return;
        }

        certificateDownload?.setAttribute('href', downloadUrl || previewUrl);
        renderCertificatePreview(previewUrl, trigger.dataset.title || 'Mi certificado');
        certificateModal.hidden = false;
        document.body.classList.add('course-drawer-open');
        requestAnimationFrame(() => certificateModal.querySelector('[data-certificate-close]')?.focus());
    };

    const closeCertificateModal = () => {
        if (!certificateModal || certificateModal.hidden) return;
        certificateModal.hidden = true;
        document.body.classList.remove('course-drawer-open');
        certificatePreviewBody.innerHTML = '<div class="course-panel-loading" role="status"><span></span>Cargando certificado...</div>';
    };

    const setUrlState = (sessionId, panel = 'video', replace = false) => {
        const url = new URL(window.location.href);
        const showUrl = templateUrl(root.dataset.showUrlTemplate, { '__SESSION__': sessionId });
        const resolved = new URL(showUrl, window.location.origin);
        url.pathname = resolved.pathname;
        panel === 'video' ? url.searchParams.delete('tab') : url.searchParams.set('tab', panel);
        window.history[replace ? 'replaceState' : 'pushState']({}, '', url);
    };

    const initializeInjectedPanel = (panel, container = workspace) => {
        if (panel === 'evaluations') window.initSessionEvaluations?.();
        if (panel === 'video') window.initVideoPanel?.();
        if (panel === 'attendance') window.initSessionAttendance?.(container);
    };

    const updatePanelBadge = (panel, count) => {
        const badge = workspace.querySelector(`[data-tab-count="${panel}"]`);
        if (!badge) return;

        badge.textContent = String(count);
        badge.classList.remove('tab-state');
        badge.hidden = count === 0;
    };

    const renderPanelError = (container, message) => {
        container.innerHTML = `
            <div class="course-panel-error" role="alert">
                <strong>No se pudo cargar esta sección.</strong>
                <span>${message || 'Intenta nuevamente.'}</span>
                <button type="button" data-retry-panel>Reintentar</button>
            </div>`;
    };

    const loadPanel = async (panel, force = false) => {
        if (panel === 'video') {
            window.initVideoPanel?.();
            return;
        }

        const sessionId = root.dataset.sessionId;
        const container = workspace?.querySelector(`[data-panel="${panel}"]`);
        if (!sessionId || !container) return;

        const key = panelKey(sessionId, panel);
        if (!force && panelCache.has(key)) {
            const cached = panelCache.get(key);
            container.innerHTML = cached.html;
            container.dataset.panelLoaded = 'true';
            updatePanelBadge(panel, cached.count);
            initializeInjectedPanel(panel, container);
            return;
        }
        if (!force) {
            const cached = readBrowserCache(`panel:${key}`);
            if (cached) {
                panelCache.set(key, cached);
                container.innerHTML = cached.html;
                container.dataset.panelLoaded = 'true';
                updatePanelBadge(panel, cached.count);
                initializeInjectedPanel(panel, container);
                return;
            }
        }

        panelController?.abort();
        panelController = new AbortController();
        container.dataset.panelLoaded = 'loading';
        container.innerHTML = '<div class="course-panel-loading" role="status"><span></span>Cargando sección...</div>';

        const url = templateUrl(root.dataset.panelUrlTemplate, {
            '__SESSION__': sessionId,
            '__PANEL__': panel,
        });

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: panelController.signal,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) throw new Error(data.message || 'Intenta nuevamente.');

            const count = Number(data.meta?.count || 0);
            const cachedPanel = { html: data.html, count };
            panelCache.set(key, cachedPanel);
            writeBrowserCache(`panel:${key}`, cachedPanel, 60);
            container.innerHTML = data.html;
            container.dataset.panelLoaded = 'true';
            updatePanelBadge(panel, count);
            initializeInjectedPanel(panel, container);
        } catch (error) {
            if (error.name === 'AbortError') return;
            container.dataset.panelLoaded = 'false';
            renderPanelError(container, error.message);
        }
    };

    window.reloadCoursePanel = (panel) => loadPanel(panel, true);

    const activatePanel = (panel, updateUrl = true) => {
        const tabs = [...workspace.querySelectorAll('[data-tab]')];
        const panels = [...workspace.querySelectorAll('[data-panel]')];
        if (!tabs.some((tab) => tab.dataset.tab === panel)) return;

        tabs.forEach((tab) => {
            const active = tab.dataset.tab === panel;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach((content) => content.classList.toggle('hidden', content.dataset.panel !== panel));
        if (updateUrl) setUrlState(root.dataset.sessionId, panel, false);
        loadPanel(panel);
    };

    const updateSelectedSession = (sessionId) => {
        sessionList?.querySelectorAll('[data-session-link]').forEach((link) => {
            const selected = String(link.dataset.sessionId) === String(sessionId);
            link.classList.toggle('is-selected', selected);
            link.classList.toggle('session-current', selected);
            link.setAttribute('aria-current', selected ? 'step' : 'false');
            if (selected) link.scrollIntoView({
                block: 'nearest',
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            });
        });
    };

    const renderWorkspace = (sessionId, data, href, pushHistory = true) => {
        const requestedPanel = new URL(window.location.href).searchParams.get('tab') || 'video';
        const preservedPanel = ({ material: 'materials', evaluacion: 'evaluations', encuestas: 'surveys', anuncios: 'announcements', asistencia: 'attendance' })[requestedPanel]
            || requestedPanel;
        workspace.innerHTML = data.html;
        const availablePanels = [...workspace.querySelectorAll('[data-tab]')].map((tab) => tab.dataset.tab);
        const panelToOpen = availablePanels.includes(preservedPanel)
            ? preservedPanel
            : (availablePanels[0] || 'video');
        workspace.setAttribute('aria-busy', 'false');
        root.dataset.sessionId = String(sessionId);
        updateSelectedSession(sessionId);
        const mobilePosition = root.querySelector('[data-mobile-session-position]');
        if (mobilePosition) mobilePosition.textContent = `${data.meta.position}/${data.meta.total}`;
        if (pushHistory) setUrlState(sessionId, panelToOpen);
        workspaceCache.set(String(sessionId), data);
        writeBrowserCache(`workspace:${sessionId}`, data, 60);
        setSessionDrawer(false);
        activatePanel(panelToOpen, false);
        workspace.querySelector('#session-title')?.focus({ preventScroll: true });
    };

    const loadWorkspace = async (sessionId, href, pushHistory = true) => {
        if (!workspace || String(sessionId) === String(root.dataset.sessionId)) {
            setSessionDrawer(false);
            return;
        }

        if (workspaceCache.has(String(sessionId))) {
            renderWorkspace(sessionId, workspaceCache.get(String(sessionId)), href, pushHistory);
            return;
        }
        const cached = readBrowserCache(`workspace:${sessionId}`);
        if (cached) {
            workspaceCache.set(String(sessionId), cached);
            renderWorkspace(sessionId, cached, href, pushHistory);
            return;
        }

        workspaceController?.abort();
        workspaceController = new AbortController();
        workspace.setAttribute('aria-busy', 'true');
        workspace.classList.add('is-loading');

        const url = templateUrl(root.dataset.workspaceUrlTemplate, { '__SESSION__': sessionId });
        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: workspaceController.signal,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) throw new Error(data.message || 'No se pudo cargar la sesión.');
            renderWorkspace(sessionId, data, href, pushHistory);
        } catch (error) {
            if (error.name === 'AbortError') return;
            workspace.setAttribute('aria-busy', 'false');
            window.location.assign(href);
        } finally {
            workspace.classList.remove('is-loading');
        }
    };

    const communityDrawer = root.querySelector('#courseCommunityDrawer');
    const communityBackdrop = root.querySelector('[data-community-backdrop]');
    const communityContent = root.querySelector('[data-community-content]');
    const communityToggle = root.querySelector('[data-community-toggle]');
    const communityClose = root.querySelector('[data-community-close]');
    let communityLoaded = false;

    const setCommunityOpen = async (open, moveFocus = true) => {
        const wasOpen = communityDrawer?.classList.contains('is-open') ?? false;

        if (open && communityDrawer) {
            communityDrawer.inert = false;
            communityDrawer.removeAttribute('inert');
            communityDrawer.classList.add('is-open');
            communityDrawer.setAttribute('aria-hidden', 'false');
        } else if (communityDrawer) {
            if (wasOpen && (moveFocus || communityDrawer.contains(document.activeElement))) {
                communityToggle?.focus();
            }
            communityDrawer.classList.remove('is-open');
            communityDrawer.setAttribute('aria-hidden', 'true');
            communityDrawer.inert = true;
            communityDrawer.setAttribute('inert', '');
        }

        communityBackdrop?.toggleAttribute('hidden', !open);
        communityToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
        localStorage.setItem(`course-community:${root.dataset.courseId}`, open ? 'open' : 'closed');
        document.body.classList.toggle('course-community-open', open);

        if (open && !wasOpen && moveFocus) {
            requestAnimationFrame(() => communityClose?.focus());
        }

        if (!open || communityLoaded || !root.dataset.communityUrl) return;
        communityContent.innerHTML = '<div class="course-panel-loading" role="status"><span></span>Cargando comunidad...</div>';

        try {
            const response = await fetch(root.dataset.communityUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) throw new Error(data.message || 'No se pudo cargar la comunidad.');
            communityContent.innerHTML = data.html;
            const communityCount = root.querySelector('[data-community-count]');
            const comments = Number(data.meta?.comments || 0);
            communityCount?.replaceChildren(String(comments));
            if (communityCount) communityCount.hidden = comments === 0;
            const summary = root.querySelector('[data-community-summary]');
            if (summary) summary.textContent = comments === 1 ? '1 comentario' : `${comments} comentarios`;
            communityLoaded = true;
            await ensureCommunityModule();
            window.initCommunityPanels?.(communityContent);
        } catch (error) {
            renderPanelError(communityContent, error.message);
        }
    };

    root.addEventListener('click', (event) => {
        const filter = event.target.closest('[data-session-filter]');
        if (filter) {
            activeFilter = filter.dataset.sessionFilter;
            filterButtons.forEach((button) => button.classList.toggle('is-active', button === filter));
            updateSessionFilter();
            return;
        }

        if (event.target.closest('[data-clear-session-search]')) {
            searchInput.value = '';
            searchInput.focus();
            updateSessionFilter();
            return;
        }

        const tab = event.target.closest('[data-tab]');
        if (tab && workspace.contains(tab)) {
            activatePanel(tab.dataset.tab);
            return;
        }

        const pendingAction = event.target.closest('[data-open-session-panel]');
        if (pendingAction) {
            activatePanel(pendingAction.dataset.openSessionPanel);
            return;
        }

        const retryPanel = event.target.closest('[data-retry-panel]');
        if (retryPanel) {
            const panel = retryPanel.closest('[data-panel]')?.dataset.panel;
            if (panel) loadPanel(panel, true);
            else if (retryPanel.closest('[data-community-content]')) {
                communityLoaded = false;
                setCommunityOpen(true);
            }
            return;
        }

        const certificatePreview = event.target.closest('[data-certificate-preview]');
        if (certificatePreview) {
            openCertificateModal(certificatePreview);
            return;
        }

        const copyCertificate = event.target.closest('[data-copy-certificate-url], [data-certificate-copy]');
        if (copyCertificate) {
            const value = copyCertificate.dataset.copyCertificateUrl || certificatePublicUrl;
            copyText(value).then((copied) => {
                const original = copyCertificate.textContent;
                copyCertificate.textContent = copied ? 'Enlace copiado' : 'No se pudo copiar';
                window.setTimeout(() => {
                    copyCertificate.textContent = original;
                }, 1800);
            });
            return;
        }

        if (event.target.closest('[data-certificate-close]')) {
            closeCertificateModal();
            return;
        }

        const sessionLink = event.target.closest('[data-session-link], [data-session-nav]');
        if (sessionLink) {
            if (root.dataset.sessionNavigationMode === 'page') {
                return;
            }

            event.preventDefault();
            loadWorkspace(sessionLink.dataset.sessionId, sessionLink.href);
            return;
        }

        if (event.target.closest('[data-open-session-drawer]')) setSessionDrawer(true);
        if (event.target.closest('[data-close-session-drawer]') || event.target.closest('[data-session-drawer-backdrop]')) setSessionDrawer(false);
        if (event.target.closest('[data-community-toggle]')) setCommunityOpen(true);
        if (event.target.closest('[data-community-close]') || event.target.closest('[data-community-backdrop]')) setCommunityOpen(false);
    });

    searchInput?.addEventListener('input', updateSessionFilter);
    window.addEventListener('resize', () => setSessionDrawer(false, false));
    workspace?.addEventListener('keydown', (event) => {
        const tab = event.target.closest('[data-tab]');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

        event.preventDefault();
        const tabs = [...workspace.querySelectorAll('[data-tab]')];
        const current = tabs.indexOf(tab);
        const target = event.key === 'Home'
            ? tabs[0]
            : event.key === 'End'
                ? tabs.at(-1)
                : tabs[(current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
        target?.focus();
        if (target) activatePanel(target.dataset.tab);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Tab' && sidebar?.classList.contains('is-open')) {
            const focusable = [...sidebar.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )].filter((element) => !element.hidden && element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable.at(-1);

            if (first && last && event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (first && last && !event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        if (event.key === 'Tab' && communityDrawer?.classList.contains('is-open')) {
            const focusable = [...communityDrawer.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )].filter((element) => !element.hidden && element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable.at(-1);

            if (first && last && event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (first && last && !event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        if (event.key !== 'Escape') return;
        setSessionDrawer(false);
        setCommunityOpen(false);
        closeCertificateModal();
    });
    window.addEventListener('popstate', () => {
        const match = window.location.pathname.match(/\/(\d+)$/);
        const sessionId = match?.[1];
        if (sessionId && sessionId !== root.dataset.sessionId) {
            loadWorkspace(sessionId, window.location.href, false);
        } else if (sessionId) {
            const panel = new URL(window.location.href).searchParams.get('tab') || 'video';
            activatePanel(panel, false);
        } else if (!sessionId) {
            window.location.reload();
        }
    });

    const requestedPanel = new URL(window.location.href).searchParams.get('tab') || 'video';
    const initialPanel = ({
        material: 'materials',
        evaluacion: 'evaluations',
        encuestas: 'surveys',
        anuncios: 'announcements',
        asistencia: 'attendance',
    })[requestedPanel] || requestedPanel;
    if (root.dataset.invalidatePanel) {
        window.invalidateCoursePanel(root.dataset.invalidatePanel, root.dataset.invalidateSession || root.dataset.sessionId);
        window.invalidateCourseWorkspaceSession(root.dataset.invalidateSession || root.dataset.sessionId);
    }
    activatePanel(initialPanel, false);
    setSessionDrawer(false, false);

    const prefetchWhenIdle = () => {
        const selected = sessionList?.querySelector('[data-session-link].is-selected');
        const next = selected?.nextElementSibling?.matches?.('[data-session-link]')
            ? selected.nextElementSibling
            : null;

        if (!next?.dataset.sessionId || workspaceCache.has(String(next.dataset.sessionId))) {
            return;
        }

        const url = templateUrl(root.dataset.workspaceUrlTemplate, { '__SESSION__': next.dataset.sessionId });
        fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => response.ok ? response.json() : null)
            .then((data) => {
                if (!data?.ok || !data.html) return;
                workspaceCache.set(String(next.dataset.sessionId), data);
                writeBrowserCache(`workspace:${next.dataset.sessionId}`, data, 60);
            })
            .catch(() => {});
    };

    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(prefetchWhenIdle, { timeout: 2500 });
    } else {
        window.setTimeout(prefetchWhenIdle, 1500);
    }
}
