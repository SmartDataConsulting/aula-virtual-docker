document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('certificateSearchForm');
  const input = document.getElementById('certificateCourseSearch');
  const help = document.getElementById('certificateSearchHelp');
  const cards = [...document.querySelectorAll('.js-live-certificate-card')];
  const noResults = document.getElementById('certificateNoResults');

  if (!form || !input) {
    return;
  }

  const normalize = (value) => value
    .toString()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

  const pluralize = (count) => count === 1 ? 'curso disponible' : 'cursos disponibles';

  const updateLiveResults = () => {
    const query = normalize(input.value);
    let visible = 0;

    cards.forEach((card) => {
      const matches = query === '' || normalize(card.dataset.courseName || '').includes(query);
      card.hidden = !matches;
      if (matches) visible += 1;
    });

    if (help) {
      help.textContent = query === ''
        ? `Mostrando ${visible} ${pluralize(visible)}`
        : `Mostrando ${visible} ${pluralize(visible)} para "${input.value.trim()}"`;
      help.classList.remove('is-error');
    }

    if (noResults) {
      noResults.hidden = visible !== 0;
    }
  };

  input.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') {
      return;
    }

    event.preventDefault();
    const value = input.value.trim();

    if (value.length > 0 && value.length < 4) {
      if (help) {
        help.textContent = 'Ingrese al menos 4 letras para buscar.';
        help.classList.add('is-error');
      }
      return;
    }

    if (help) {
      help.textContent = 'Buscando...';
      help.classList.remove('is-error');
    }

    form.requestSubmit();
  });

  input.addEventListener('input', () => {
    updateLiveResults();
  });

  input.addEventListener('search', () => {
    updateLiveResults();

    if (input.value.trim() === '') {
      form.requestSubmit();
    }
  });

  updateLiveResults();
});
