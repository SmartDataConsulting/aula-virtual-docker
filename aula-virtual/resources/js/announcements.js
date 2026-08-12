document.addEventListener("DOMContentLoaded", function () {

  const items = document.querySelectorAll(".js-announcement-content");

  items.forEach(el => {

    const actions = el.closest(".announcement-card")
                      .querySelector(".js-announcement-actions");

    if (!actions) return;

    const style = window.getComputedStyle(el);

    const lineClamp = parseInt(style.webkitLineClamp || "0");

    if (!lineClamp) return;

    const lineHeight = parseFloat(style.lineHeight);

    const maxHeight = lineHeight * lineClamp;

    if (el.scrollHeight <= maxHeight + 2) {
      actions.style.display = "none";
    }

  });

});

document.addEventListener("click", function (event) {
  const markAllLink = event.target.closest("[data-mark-read-all-type]");
  if (markAllLink) {
    marcarTodosAnunciosLeidos(
      markAllLink.dataset.markReadAllType,
      markAllLink.dataset.markReadAllId
    );
    return;
  }

  const detailLink = event.target.closest("[data-toggle-announcement-expand]");
  if (detailLink) {
    event.preventDefault();
    toggleAnuncioExpand(detailLink);
    marcarAnuncioLeido(detailLink.dataset.markAnnouncementReadId);
    return;
  }

  const openCreateButton = event.target.closest("[data-open-create-announcement-modal]");
  if (openCreateButton) {
    event.preventDefault();
    openCreateAnnouncementModal();
    return;
  }

  const menuToggleButton = event.target.closest("[data-toggle-announcement-menu]");
  if (menuToggleButton) {
    event.preventDefault();
    event.stopPropagation();
    toggleMenu(menuToggleButton);
    return;
  }

  const editButton = event.target.closest("[data-edit-announcement-id]");
  if (editButton) {
    event.preventDefault();
    event.stopPropagation();
    openEditAnnouncementModal(editButton.dataset.editAnnouncementId);
    return;
  }

  const deleteButton = event.target.closest("[data-delete-announcement-id]");
  if (deleteButton) {
    event.preventDefault();
    event.stopPropagation();
    confirmDeleteAnnouncement(deleteButton.dataset.deleteAnnouncementId);
  }
});

function getAnnouncementsContext() {
  const context = document.getElementById("announcementsContext");

  return {
    readUrlTemplate: context?.dataset.readUrlTemplate || null,
    userEmail: context?.dataset.userEmail || "",
  };
}

/* =========================
   MODALES
========================= */

function openCreateAnnouncementModal() {
    closeAllMenus();
    openModal('createAnnouncementModal');
}

function closeCreateAnnouncementModal() {
    closeModal('createAnnouncementModal');
}

function openEditAnnouncementModal(id) {
    closeAllMenus();

    const el = document.querySelector('[data-id="'+id+'"]');
    if (!el) return; // seguridad defensiva

    const titleInput = document.getElementById('edit_annuncio_title');
    const contentInput = document.getElementById('edit_annuncio_content');
    const typeSelect = document.getElementById('edit_annuncio_type');
    const form = document.getElementById('editAnnouncementForm');

    if (!titleInput || !contentInput || !typeSelect || !form) return;

    titleInput.value = el.dataset.titulo || '';
    contentInput.value = el.dataset.contenido || '';
    typeSelect.value = el.dataset.tipo || 'general';
    form.action = el.dataset.updateUrl;

    openModal('editAnnouncementModal');
}

function closeEditAnnouncementModal() {
    closeModal('editAnnouncementModal');
}


/* =========================
   DELETE
========================= */

async function confirmDeleteAnnouncement(id) {
    closeAllMenus();

    const confirmed = await confirmAction({
      title: 'Eliminar anuncio',
      message: '¿Deseas eliminar este anuncio? Esta acción no se puede deshacer.',
      confirmText: 'Eliminar',
    });

    if (!confirmed) return;

    const form = document.getElementById('delete-announcement-' + id);
    if (!form) return;

    showGlobalLoader('Eliminando anuncio...');
    form.requestSubmit();
}


/* =========================
   SUBMIT LOADER
========================= */

document.addEventListener('DOMContentLoaded', function () {

    const createForm = document.querySelector('#createAnnouncementForm');
    const editForm = document.querySelector('#editAnnouncementForm');

    createForm?.addEventListener('submit', function() {
        showGlobalLoader('Guardando anuncio...');
    });

    editForm?.addEventListener('submit', function() {
        showGlobalLoader('Actualizando anuncio...');
    });

});


function toggleAnuncioExpand(btn) {

  const card = btn.closest(".announcement-card");
  if (!card) return;

  const contenido = card.querySelector(".js-announcement-content");
  if (!contenido) return;

  contenido.classList.toggle("expandido");

}

function marcarAnuncioLeido(anuncioId) {
  const { readUrlTemplate } = getAnnouncementsContext();

  if (!readUrlTemplate) {
    console.error('No se encontro la ruta para marcar anuncios como leidos.');
    return;
  }

  const token = document
    .querySelector('meta[name="csrf-token"]')
    .getAttribute('content');

  const url = readUrlTemplate.replace(':id', anuncioId);

  fetch(url, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'X-CSRF-TOKEN': token
    }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      console.log('Marcado como leído');
    } else {
      console.error('Error backend:', data);
    }
  })
  .catch(err => {
    console.error('Fetch error:', err);
  });
}

function marcarTodosAnunciosLeidos(tipo, id) {
  const { userEmail } = getAnnouncementsContext();

  const token = document
    .querySelector('meta[name="csrf-token"]')
    .getAttribute('content');

  fetch(`/mis-cursos/anuncios/${tipo}/${id}/leer-todos`, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token
    },
    body: JSON.stringify({
      correo: userEmail
    })
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) {
      console.error('Error backend:', data);
    }
  })
  .catch(err => {
    console.error('Fetch error:', err);
  });
}






/* =========================
   EXPORT GLOBAL
========================= */

window.openCreateAnnouncementModal = openCreateAnnouncementModal;
window.closeCreateAnnouncementModal = closeCreateAnnouncementModal;
window.openEditAnnouncementModal = openEditAnnouncementModal;
window.closeEditAnnouncementModal = closeEditAnnouncementModal;
window.confirmDeleteAnnouncement = confirmDeleteAnnouncement;
window.toggleAnuncioExpand = toggleAnuncioExpand;
window.marcarAnuncioLeido = marcarAnuncioLeido;
window.marcarTodosAnunciosLeidos = marcarTodosAnunciosLeidos;
