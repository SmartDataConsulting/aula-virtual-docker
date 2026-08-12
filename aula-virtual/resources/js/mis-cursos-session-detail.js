document.addEventListener('DOMContentLoaded', () => {
    const content = document.getElementById('course-session-content');
    const sessionList = document.getElementById('session-list');

    if (!content) {
        return;
    }

    const showPanelLoader = (message) => {
        content.innerHTML = `
            <div class="flex min-h-[300px] flex-col items-center justify-center text-center">
                <div class="mb-4 h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-blue-600"></div>
                <div class="text-sm font-semibold text-slate-700">
                    ${message}
                </div>
            </div>
        `;
    };

    document.addEventListener('click', async (event) => {
        const link = event.target.closest('[data-panel-loading-message]');

        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const href = link.getAttribute('href');

        if (!href || link.target === '_blank') {
            return;
        }

        event.preventDefault();
        showPanelLoader(link.dataset.panelLoadingMessage || 'Cargando');

        try {
            const response = await fetch(href, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Panel-Request': 'true',
                },
            });

            if (!response.ok) {
                throw new Error('No se pudo cargar el panel');
            }

            const data = await response.json();

            if (!data.html) {
                throw new Error('Respuesta sin contenido');
            }

            content.innerHTML = data.html;
            window.initMisCursosNotes?.(content);
            window.history.pushState({}, '', href);
        } catch (error) {
            window.location.href = href;
        }
    });

    sessionList?.addEventListener('click', async (event) => {
        const link = event.target.closest('[data-session-link]');

        if (!link || link.getAttribute('aria-disabled') === 'true') {
            return;
        }

        const url = link.dataset.sessionUrl;

        if (!url) {
            return;
        }

        event.preventDefault();

        showPanelLoader('Cargando informacion de la Sesión.');

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('No se pudo cargar la sesion');
            }

            const data = await response.json();

            if (!data.html) {
                throw new Error('Respuesta sin contenido');
            }

            content.innerHTML = data.html;

            sessionList.querySelectorAll('[data-session-link]').forEach((item) => {
                item.classList.remove('session-current');
            });
            link.classList.add('session-current');

            window.history.pushState({}, '', link.href);
        } catch (error) {
            window.location.href = link.href;
        }
    });
});
