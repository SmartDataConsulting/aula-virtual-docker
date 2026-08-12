import './global.js';

document.addEventListener('DOMContentLoaded', () => {
    const tabs = [...document.querySelectorAll('[data-survey-tab]')];
    const panels = [...document.querySelectorAll('[data-survey-tab-panel]')];

    if (!tabs.length || !panels.length) return;

    const activate = (name, { updateUrl = true, focusTab = false } = {}) => {
        const selected = tabs.find((tab) => tab.dataset.surveyTab === name) || tabs[0];

        tabs.forEach((tab) => {
            const active = tab === selected;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });

        panels.forEach((panel) => {
            panel.hidden = panel.dataset.surveyTabPanel !== selected.dataset.surveyTab;
        });

        document.querySelectorAll('input[name="view"]').forEach((input) => {
            input.value = selected.dataset.surveyTab;
        });

        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', selected.dataset.surveyTab);
            window.history.replaceState({}, '', url);
        }

        if (focusTab) selected.focus();
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.surveyTab));
        tab.addEventListener('keydown', (event) => {
            let nextIndex = null;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = tabs.length - 1;
            if (nextIndex === null) return;
            event.preventDefault();
            activate(tabs[nextIndex].dataset.surveyTab, { focusTab: true });
        });
    });

    document.querySelectorAll('[data-open-survey-tab]').forEach((control) => {
        control.addEventListener('click', () => activate(control.dataset.openSurveyTab, { focusTab: true }));
    });

    const requested = new URL(window.location.href).searchParams.get('view');
    activate(requested, { updateUrl: false });
});
