import './global.js';

document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.tab-card');
    const contents = document.querySelectorAll('.tab-content');
    const search = document.getElementById('courseSearch');
    const searchForm = document.getElementById('courseSearchForm');
    const searchHelp = document.getElementById('courseSearchHelp');
    const searchTab = document.getElementById('courseSearchTab');
    const pluralizeCourses = (count) => count === 1 ? 'curso disponible' : 'cursos disponibles';
    const normalize = (value) => value
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();

    const updateLiveResults = () => {
        if (!search) return;

        const query = normalize(search.value);
        let activeVisible = 0;

        contents.forEach((content) => {
            const cards = [...content.querySelectorAll('.js-live-course-card')];
            let visible = 0;

            cards.forEach((card) => {
                const matches = query === '' || normalize(card.dataset.courseName || '').includes(query);
                card.hidden = !matches;
                if (matches) visible += 1;
            });

            content.querySelector('[data-course-no-results]')?.toggleAttribute('hidden', visible !== 0 || cards.length === 0);

            const panelCount = content.querySelector('[data-course-panel-count]');
            if (panelCount) {
                const total = Number(panelCount.dataset.total || cards.length);
                const count = query === '' ? total : visible;
                panelCount.textContent = `${count} ${count === 1 ? 'curso' : 'cursos'}`;
            }

            if (!content.classList.contains('hidden')) {
                activeVisible = query === ''
                    ? Number(content.querySelector('[data-course-panel-count]')?.dataset.total || cards.length)
                    : visible;
            }
        });

        if (searchHelp) {
            searchHelp.textContent = query === ''
                ? `Mostrando ${activeVisible} ${pluralizeCourses(activeVisible)}`
                : `Mostrando ${activeVisible} ${pluralizeCourses(activeVisible)} para "${search.value.trim()}"`;
            searchHelp.classList.remove('is-error');
        }
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const selected = tab.dataset.tab;
            const url = new URL(window.location.href);

            tabs.forEach((item) => {
                const isActive = item.dataset.tab === selected;
                const count = item.querySelector('span');

                item.setAttribute('aria-selected', isActive ? 'true' : 'false');
                item.classList.toggle('bg-indigo-600', isActive);
                item.classList.toggle('text-white', isActive);
                item.classList.toggle('text-slate-600', !isActive);
                item.classList.toggle('hover:bg-slate-50', !isActive);

                if (count) {
                    count.classList.toggle('text-indigo-100', isActive);
                    count.classList.toggle('text-slate-400', !isActive);
                }
            });

            contents.forEach((content) => {
                const isActive = content.dataset.content === selected;
                content.classList.toggle('hidden', !isActive);
            });

            if (searchTab) {
                searchTab.value = selected;
            }

            if (searchHelp && !search?.value.trim()) {
                const count = Number(tab.dataset.count || 0);
                searchHelp.textContent = `Mostrando ${count} ${pluralizeCourses(count)}`;
                searchHelp.dataset.count = String(count);
            }

            url.searchParams.set('tab', selected);
            window.history.replaceState({}, '', url);
            updateLiveResults();
        });
    });

    if (!search || !searchForm) {
        return;
    }

    search.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        const value = search.value.trim();

        if (value.length === 0) {
            searchForm.requestSubmit();
            return;
        }

        if (value.length < 4) {
            if (searchHelp) {
                searchHelp.textContent = 'Ingrese al menos 4 letras para buscar.';
                searchHelp.classList.add('is-error');
            }
            return;
        }

        if (searchHelp) {
            searchHelp.textContent = 'Buscando...';
            searchHelp.classList.remove('is-error');
        }

        searchForm.requestSubmit();
    });

    search.addEventListener('input', updateLiveResults);
    search.addEventListener('search', () => {
        updateLiveResults();

        if (search.value.trim() === '') {
            searchForm.requestSubmit();
        }
    });

    updateLiveResults();
});
