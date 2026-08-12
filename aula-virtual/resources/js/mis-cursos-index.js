document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.tab-card');
    const contents = document.querySelectorAll('.tab-content');
    const tabInput = document.querySelector('[data-student-course-tab-input]');
    const countLabel = document.querySelector('[data-student-course-count]');

    if (!tabs.length || !contents.length) {
        return;
    }

    function setTab(tab) {
        let activeCount = 0;

        tabs.forEach((item) => {
            const isActive = item.dataset.tab === tab;

            item.classList.toggle('tab-active', isActive);
            item.classList.toggle('bg-indigo-50', isActive);
            item.classList.toggle('is-active', isActive);
            item.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        contents.forEach((content) => {
            const isActive = content.dataset.content === tab;
            content.classList.toggle('hidden', !isActive);

            if (isActive) {
                activeCount = content.querySelectorAll('.student-course-card').length;
            }
        });

        if (tabInput) {
            tabInput.value = tab;
        }

        if (countLabel) {
            countLabel.textContent = String(activeCount);
        }

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState(null, '', url.toString());
    }

    const params = new URLSearchParams(window.location.search);
    const initial = params.get('tab') || 'activos';

    setTab(initial);

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => setTab(tab.dataset.tab));
    });
});
