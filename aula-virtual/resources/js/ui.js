function closeAllMenus() {
    document.querySelectorAll('.menu').forEach(m => {
        m.classList.add('hidden');
    });
}

function toggleMenu(btn){
    const wrapper = btn.closest('.menu-wrapper');
    const menu = wrapper.querySelector('.menu');

    closeAllMenus();
    menu.classList.remove('hidden');
}

document.addEventListener("DOMContentLoaded", function () {

    const tabs = document.querySelectorAll(".tab-link");
    const contents = document.querySelectorAll(".tab-content");

    if (!tabs.length) return;

    tabs.forEach(tab => {
        tab.addEventListener("click", function () {

            const target = this.dataset.tab;

            tabs.forEach(t => {
                t.classList.remove("text-indigo-600", "border-b-2", "border-indigo-600");
                t.classList.add("text-slate-500");
                t.classList.remove("is-active");
                t.setAttribute("aria-selected", "false");
            });

            // Ocultar todos los contenidos
            contents.forEach(c => c.classList.add("hidden"));

            this.classList.add("text-indigo-600", "border-b-2", "border-indigo-600");
            this.classList.remove("text-slate-500");
            this.classList.add("is-active");
            this.setAttribute("aria-selected", "true");

            // Mostrar contenido correspondiente
            const active = document.getElementById("tab-" + target);
            if (active) {
                active.classList.remove("hidden");
            }

        });
    });
    // 👇 ACTIVAR TAB DESDE BACKEND (cuando hay error)
    const initialTab = document.getElementById('initial-tab')?.value;

    if (initialTab) {

        const targetTab = document.querySelector(`.tab-link[data-tab="${initialTab}"]`);
        
        if (targetTab) {
            targetTab.click(); // Simula click y activa correctamente estilos + contenido
        }
    }
});

// Función genérica para abrir/cerrar modales
function openModal(modalId) {
    document.getElementById(modalId)?.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId)?.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
}

// 👇 EXPORTAR AL GLOBAL
window.toggleMenu = toggleMenu;
window.closeAllMenus = closeAllMenus;
window.openModal = openModal;
window.closeModal = closeModal;
