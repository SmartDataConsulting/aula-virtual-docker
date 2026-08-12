import './global.js';

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('surveyCourseSearch');
    const form = document.getElementById('surveySearchForm');
    const help = document.getElementById('surveySearchHelp');
    const noResults = document.getElementById('surveyNoResults');
    const cards = [...document.querySelectorAll('.js-filterable-course-card')];

    const normalize = (value) => value.toString().normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    const updatePreview = () => {
        const query = normalize(input?.value || '');
        let visible = 0;

        cards.forEach((card) => {
            const matches = query === '' || normalize(card.dataset.courseName || '').includes(query);
            card.hidden = !matches;
            visible += matches ? 1 : 0;
        });

        if (help) {
            help.textContent = `Mostrando ${visible} ${visible === 1 ? 'curso disponible' : 'cursos disponibles'}`;
        }
        if (noResults) {
            noResults.hidden = visible > 0;
        }
    };

    input?.addEventListener('input', updatePreview);
    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            window.showGlobalLoader?.('Buscando cursos...');
            form?.requestSubmit();
        }
    });
    input?.addEventListener('search', () => {
        updatePreview();
        if (input.value.trim() === '') form?.requestSubmit();
    });

    updatePreview();
});
