import './global.js';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-qualification-notes-root]');
    const searchInput = document.getElementById('qualificationNotesSearch');
    const tableShell = document.querySelector('.qualification-notes-table-shell');
    const topScroll = document.querySelector('[data-notes-top-scroll]');
    const topScrollInner = document.querySelector('[data-notes-top-scroll-inner]');
    const rows = Array.from(document.querySelectorAll('[data-student-row]'));

    if (!root) {
        return;
    }

    let syncingScroll = false;

    const syncTopScrollbarWidth = () => {
        const table = tableShell?.querySelector('.qualification-notes-table');

        if (!tableShell || !topScroll || !topScrollInner || !table) {
            return;
        }

        topScrollInner.style.width = `${table.scrollWidth}px`;
        topScroll.hidden = table.scrollWidth <= tableShell.clientWidth;
    };

    topScroll?.addEventListener('scroll', () => {
        if (!tableShell || syncingScroll) {
            return;
        }

        syncingScroll = true;
        tableShell.scrollLeft = topScroll.scrollLeft;
        syncingScroll = false;
    });

    tableShell?.addEventListener('scroll', () => {
        if (!topScroll || syncingScroll) {
            return;
        }

        syncingScroll = true;
        topScroll.scrollLeft = tableShell.scrollLeft;
        syncingScroll = false;
    });

    window.addEventListener('resize', syncTopScrollbarWidth);
    syncTopScrollbarWidth();

    searchInput?.addEventListener('input', () => {
        const query = searchInput.value.trim().toLocaleLowerCase();

        rows.forEach((row) => {
            const haystack = row.dataset.studentName || '';
            row.hidden = query !== '' && !haystack.includes(query);
        });
    });
});
