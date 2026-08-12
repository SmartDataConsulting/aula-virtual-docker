document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('courseSearchEvaluaciones');
    const form = document.getElementById('evaluationsSearchForm');
    const cards = [...document.querySelectorAll('.evaluacion-course-card')];
    const noResults = document.getElementById('evaluationsNoResults');
    const visibleCount = document.getElementById('evaluationsVisibleCount');
    const searchHint = document.getElementById('evaluationsSearchHint');

    if (!input || cards.length === 0) {
        return;
    }

    const total = cards.length;

    const updateVisibleState = () => {
        const value = input.value.trim().toLowerCase();
        let visible = 0;

        cards.forEach((card) => {
            const name = card.dataset.courseName || '';
            const matches = !value || name.includes(value);

            card.hidden = !matches;

            if (matches) {
                visible += 1;
            }
        });

        if (visibleCount) {
            visibleCount.textContent = String(visible);
        }

        if (searchHint) {
            searchHint.textContent = value
                ? `${visible} de ${total} cursos encontrados`
                : `Mostrando ${total} cursos disponibles`;
        }

        if (noResults) {
            noResults.hidden = visible !== 0;
        }
    };

    input.addEventListener('input', updateVisibleState);
    input.addEventListener('search', updateVisibleState);
    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        updateVisibleState();
    });
});
