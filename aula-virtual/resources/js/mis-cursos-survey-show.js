import './global.js';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-survey-form]');
    const button = form?.querySelector('[data-survey-submit]');
    const status = document.getElementById('surveySubmitStatus');

    form?.addEventListener('submit', (event) => {
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            status.textContent = 'Completa las preguntas obligatorias.';
            return;
        }

        if (button) {
            button.disabled = true;
            button.textContent = 'Enviando...';
        }

        status.textContent = 'Estamos guardando tus respuestas. No cierres esta página.';
        form.setAttribute('aria-busy', 'true');
    });
});
