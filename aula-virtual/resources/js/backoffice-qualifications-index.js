import './global.js';

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('qualificationCourseSearch');
    const form = document.getElementById('qualificationSearchForm');
    const help = document.getElementById('qualificationSearchHelp');
    const noResults = document.getElementById('qualificationNoResults');
    const paginationLinks = document.querySelectorAll('.pagination a, nav[role="navigation"] a');
    const courseCards = [...document.querySelectorAll('.qualification-course-card, .js-filterable-course-card')];
    const courseLinks = document.querySelectorAll('.qualification-course-card a, .js-filterable-course-card a');

    const normalize = (value) => value
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();

    const pluralize = (count) => count === 1 ? 'curso disponible' : 'cursos disponibles';

    const updateVisibleCourses = () => {
        if (!input || !courseCards.length) {
            return;
        }

        const query = normalize(input.value);
        let visible = 0;

        courseCards.forEach((card) => {
            const haystack = normalize(card.dataset.courseName || '');
            const matches = query === '' || haystack.includes(query);

            card.hidden = !matches;

            if (matches) {
                visible += 1;
            }
        });

        if (help) {
            help.textContent = query === ''
                ? `Mostrando ${visible} ${pluralize(visible)}`
                : `Mostrando ${visible} ${pluralize(visible)} para "${input.value.trim()}"`;
            help.classList.remove('text-red-600');
            help.classList.add('text-gray-500');
        }

        if (noResults) {
            noResults.hidden = visible > 0;
        }
    };

    paginationLinks.forEach((link) => {
        link.addEventListener('click', () => {
            if (typeof window.showGlobalLoader === 'function') {
                window.showGlobalLoader('Cargando...');
            }
        });
    });

    courseLinks.forEach((link) => {
        link.addEventListener('click', () => {
            if (typeof window.showGlobalLoader === 'function') {
                window.showGlobalLoader('Cargando...');
            }
        });
    });

    if (!input || !form) {
        return;
    }

    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        const value = input.value.trim();

        if (help) {
            help.textContent = 'Buscando...';
            help.classList.remove('text-red-600');
            help.classList.add('text-gray-500');
        }

        form.requestSubmit();
    });

    input.addEventListener('input', updateVisibleCourses);
    input.addEventListener('search', () => {
        updateVisibleCourses();

        if (input.value.trim() === '') {
            form.requestSubmit();
        }
    });

    updateVisibleCourses();
});
