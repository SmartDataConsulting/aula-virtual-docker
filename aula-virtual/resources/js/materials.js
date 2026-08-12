function toggleMaterialFields(tipoSelectId, archivoId, urlId) {
    const tipo = document.getElementById(tipoSelectId).value;
    const archivo = document.getElementById(archivoId);
    const url = document.getElementById(urlId);

    if (tipo === 'archivo') {
        archivo.classList.remove('hidden');
        url.classList.add('hidden');
    } else {
        archivo.classList.add('hidden');
        url.classList.remove('hidden');
    }
}

document.addEventListener('change', function (e) {

    if (e.target.id === 'createTipo') {
        toggleMaterialFields('createTipo', 'archivoWrapper', 'urlWrapper');
    }

    if (e.target.id === 'edit_tipo') {
        toggleMaterialFields('edit_tipo', 'editArchivoWrapper', 'editUrlWrapper');
    }

    if (e.target.id === 'archivoInput') {
        updateSelectedMaterialFile(e.target);
    }
});

document.addEventListener('click', function (event) {
    const openCreateButton = event.target.closest('[data-open-create-material-modal]');
    if (openCreateButton) {
        event.preventDefault();
        openCreateMaterialModal();
        return;
    }

    const openPreviewButton = event.target.closest('[data-open-material-preview]');
    if (openPreviewButton) {
        event.preventDefault();
        openMaterialPreviewModal(openPreviewButton);
        return;
    }

    const closePreviewButton = event.target.closest('[data-close-material-preview]');
    if (closePreviewButton) {
        event.preventDefault();
        closeMaterialPreviewModal();
        return;
    }

    const menuToggleButton = event.target.closest('[data-toggle-material-menu]');
    if (menuToggleButton) {
        event.preventDefault();
        event.stopPropagation();
        toggleMenu(menuToggleButton);
        return;
    }

    const editButton = event.target.closest('[data-edit-material-id]');
    if (editButton) {
        event.preventDefault();
        event.stopPropagation();
        openEditMaterialModal(editButton.dataset.editMaterialId);
        return;
    }

    const deleteButton = event.target.closest('[data-delete-material-id]');
    if (deleteButton) {
        event.preventDefault();
        event.stopPropagation();
        confirmDeleteMaterial(deleteButton.dataset.deleteMaterialId);
    }
});

function updateSelectedMaterialFile(input) {
    const file = input.files[0];
    const fileName = document.getElementById('archivoNombre');
    const maxBytes = 30 * 1024 * 1024;

    if (!fileName) return;

    if (file && file.size > maxBytes) {
        input.value = '';
        fileName.innerText = 'El archivo supera el limite de 30 MB.';
        fileName.classList.add('text-red-600');
        fileName.classList.remove('text-gray-500');
        return;
    }

    fileName.innerText = file?.name || 'Ningun archivo seleccionado';
    fileName.classList.remove('text-red-600');
    fileName.classList.add('text-gray-500');
}

function openCreateMaterialModal(){
    closeAllMenus();
    openModal('createMaterialModal');
    toggleMaterialFields('createTipo', 'archivoWrapper', 'urlWrapper');
}

function closeCreateMaterialModal(){
    closeModal('createMaterialModal');
}


function openEditMaterialModal(id){
    closeAllMenus();

    const el = document.querySelector('[data-id="'+id+'"]');

    document.getElementById('edit_titulo').value = el.dataset.titulo || '';
    document.getElementById('edit_descripcion').value = el.dataset.descripcion || '';
    document.getElementById('edit_tipo').value = el.dataset.tipo || 'archivo';
    document.getElementById('edit_url').value = el.dataset.url || '';

    document.getElementById('editMaterialForm').action = el.dataset.updateUrl;

    toggleMaterialFields('edit_tipo', 'editArchivoWrapper', 'editUrlWrapper');

    openModal('editMaterialModal');
}

function closeEditMaterialModal(){
    closeModal('editMaterialModal');
}

/* =========================
   DELETE
========================= */

async function confirmDeleteMaterial(id){
    closeAllMenus();

    const confirmed = await confirmAction({
        title: 'Eliminar material',
        message: '¿Deseas eliminar este material? Esta acción no se puede deshacer.',
        confirmText: 'Eliminar',
    });

    if (!confirmed) return;
    showGlobalLoader('Eliminando material...');
    document.getElementById('delete-material-'+id)?.requestSubmit();
}

document.querySelector('#createMaterialModal form')
?.addEventListener('submit', function() {
    showGlobalLoader('Guardando material...');
});

document.querySelector('#editMaterialForm')
?.addEventListener('submit', function() {
    showGlobalLoader('Actualizando material...');
});

document.addEventListener('DOMContentLoaded', function () {
    fadeOutFlash('flash-success', 5000);
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeMaterialPreviewModal();
    }
});

function openMaterialPreviewModal(button) {
    const modal = document.getElementById('materialPreviewModal');
    const title = document.getElementById('materialPreviewTitle');
    const image = document.getElementById('materialPreviewImage');
    const frame = document.getElementById('materialPreviewFrame');
    const unsupported = document.getElementById('materialPreviewUnsupported');
    const loading = document.getElementById('materialPreviewLoading');
    const download = document.getElementById('materialPreviewDownload');

    if (!modal || !title || !image || !frame || !unsupported || !loading || !download) {
        return;
    }

    const previewUrl = button.dataset.previewUrl || '#';
    const downloadUrl = button.dataset.downloadUrl || previewUrl;
    const materialTitle = button.dataset.materialTitle || 'Vista previa';
    const materialType = (button.dataset.materialType || '').toLowerCase();

    closeAllMenus();
    title.textContent = materialTitle;
    download.href = downloadUrl;

    image.classList.add('hidden');
    frame.classList.add('hidden');
    unsupported.classList.add('hidden');
    loading.classList.remove('hidden');
    image.removeAttribute('src');
    frame.removeAttribute('src');

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');

    frame.onload = function () {
        loading.classList.add('hidden');
    };
    frame.onerror = function () {
        loading.classList.add('hidden');
        unsupported.classList.remove('hidden');
    };
    frame.src = previewUrl;
    frame.classList.remove('hidden');
}

function closeMaterialPreviewModal() {
    const modal = document.getElementById('materialPreviewModal');

    if (!modal || modal.classList.contains('hidden')) {
        return;
    }

    const image = document.getElementById('materialPreviewImage');
    const frame = document.getElementById('materialPreviewFrame');

    image?.removeAttribute('src');
    frame?.removeAttribute('src');

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}



// Exportar funciones al scope global
window.openCreateMaterialModal = openCreateMaterialModal;
window.closeCreateMaterialModal = closeCreateMaterialModal;
window.openEditMaterialModal = openEditMaterialModal;
window.closeEditMaterialModal = closeEditMaterialModal;
window.confirmDeleteMaterial = confirmDeleteMaterial;
window.closeMaterialPreviewModal = closeMaterialPreviewModal;
