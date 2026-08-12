// Función genérica para mostrar/ocultar campos según el valor de un select
function toggleFieldBySelect(selectId, showId, hideId, showValue = 'archivo') {
    const tipo = document.getElementById(selectId)?.value;
    if (!tipo) return;
    document.getElementById(showId).style.display = tipo === showValue ? 'block' : 'none';
    document.getElementById(hideId).style.display = tipo !== showValue ? 'block' : 'none';
}


// Función para fade y remover mensajes flash
function fadeOutFlash(flashId, timeout = 5000) {
    const flash = document.getElementById(flashId);
    if (!flash) return;
    setTimeout(() => {
        flash.style.opacity = '0';
        setTimeout(() => {
            flash.remove();
        }, 500);
    }, timeout);
}

function showGlobalLoader(message = 'Procesando solicitud...') {

    let overlay = document.getElementById('globalLoading');

    if (overlay) {
        const text = document.getElementById('globalLoadingText');
        if (text) text.textContent = message;
        overlay.setAttribute('aria-label', message);
        overlay.setAttribute('aria-hidden', 'false');
        overlay.removeAttribute('hidden');
        document.body.classList.add('global-loading-open');
        return;
    }

    if (!overlay) {

        overlay = document.createElement('div');
        overlay.id = 'globalLoading';

        overlay.style.position = 'fixed';
        overlay.style.inset = '0';
        overlay.style.background = 'rgba(255,255,255,0.7)';
        overlay.style.backdropFilter = 'blur(4px)';
        overlay.style.display = 'flex';
        overlay.style.alignItems = 'center';
        overlay.style.justifyContent = 'center';
        overlay.style.zIndex = '999999';

        overlay.innerHTML = `
            <div style="
                background:white;
                padding:25px 40px;
                border-radius:16px;
                box-shadow:0 20px 40px rgba(0,0,0,0.15);
                display:flex;
                align-items:center;
                gap:15px;
                font-weight:600;
                font-size:18px;
                color:#1F6AE1;
            ">
                <span class="animate-spin" style="font-size:26px;">⏳</span>
                <span id="globalLoadingText"></span>
            </div>
        `;

        document.body.appendChild(overlay);
    }

    document.getElementById('globalLoadingText').innerText = message;
}

function hideGlobalLoader() {
    const overlay = document.getElementById('globalLoading');
    if (!overlay) return;

    if (overlay.classList.contains('global-loading')) {
        overlay.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('hidden', '');
        document.body.classList.remove('global-loading-open');
        return;
    }

    overlay.remove();
}

// CONFIRM GLOBAL REUTILIZABLE
function confirmAction({
    title = "Confirmar acción",
    message = "¿Estás seguro?",
    confirmText = "Confirmar",
    cancelText = "Cancelar"
} = {}) {

    return new Promise((resolve) => {

        const modal = document.getElementById('appConfirmModal');
        if (!modal) {
            console.error('Confirm modal no encontrado');
            resolve(false);
            return;
        }

        const titleEl = document.getElementById('appConfirmTitle');
        const msgEl = document.getElementById('appConfirmMessage');
        const okBtn = document.getElementById('appConfirmOk');
        const cancelBtn = document.getElementById('appConfirmCancel');

        titleEl.innerText = title;
        msgEl.innerText = message;

        okBtn.innerText = confirmText;
        cancelBtn.innerText = cancelText;

        modal.classList.remove('hidden');
        modal.classList.add('flex');

        const close = (result) => {

            modal.classList.add('hidden');
            modal.classList.remove('flex');

            okBtn.onclick = null;
            cancelBtn.onclick = null;

            resolve(result);
        };

        okBtn.onclick = () => close(true);
        cancelBtn.onclick = () => close(false);

    });

}

async function cargarParametros(maestroId, selectId) {

    const select = document.getElementById(selectId);

    if (!select) return;

    try {

        const response = await fetch(`/parameters/${maestroId}`);

        if (!response.ok) {
            throw new Error('Error cargando parametros');
        }

        const data = await response.json();
        
        select.innerHTML = '<option value="">Seleccione...</option>';

        data.forEach(item => {

            const option = document.createElement('option');
            option.value = item.id_valor;
            option.textContent = item.desc_valor;

            select.appendChild(option);
        });

    } catch (error) {
        console.error('Error cargarParametros', error);
    }
}

function showSuccessModal(message, onOk){

    const modal = document.getElementById("appErrorModal");
    const msg = document.getElementById("appErrorMessage");
    const ok = document.getElementById("appErrorOk");

    const title = modal.querySelector(".text-lg");

    title.innerText = "Éxito";
    title.classList.remove("text-red-600");
    title.classList.add("text-green-600");

    msg.innerText = message;

    modal.classList.remove("hidden");
    modal.classList.add("flex");

    ok.onclick = () => {
        modal.classList.add("hidden");
        modal.classList.remove("flex");

        if(onOk){
            onOk();
        }
    };
}

function invokeNamedHandler(expression) {
    if (!expression) return;

    const match = String(expression).trim().match(/^([A-Za-z_$][\w$]*)\s*(?:\(\s*\))?$/);
    if (!match) {
        console.warn('Handler de cierre no valido:', expression);
        return;
    }

    const fn = window[match[1]];
    if (typeof fn === 'function') {
        fn();
        return;
    }

    console.warn(`No se encontro la funcion global "${match[1]}"`);
}

document.addEventListener('click', function (event) {
    const closeButton = event.target.closest('[data-close-handler]');
    if (!closeButton) return;

    invokeNamedHandler(closeButton.dataset.closeHandler);
});

window.toggleFieldBySelect = toggleFieldBySelect;
window.fadeOutFlash = fadeOutFlash;
window.showGlobalLoader = showGlobalLoader;
window.hideGlobalLoader = hideGlobalLoader;
window.confirmAction = confirmAction;       
window.cargarParametros = cargarParametros;
window.showSuccessModal = showSuccessModal;
window.invokeNamedHandler = invokeNamedHandler;
