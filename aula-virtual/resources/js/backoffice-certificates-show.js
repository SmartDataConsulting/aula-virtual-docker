document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('certificateDetail');

  if (!root) {
    return;
  }

  const studentFilter = document.getElementById('certificateStudentFilter');
  const statusFilter = document.getElementById('certificateStatusFilter');
  const feedback = document.getElementById('certificateFeedback');
  const noResultsRow = document.getElementById('certificateNoResultsRow');
  const summaryTotal = document.getElementById('certificateSummaryTotal');
  const summaryGenerated = document.getElementById('certificateSummaryGenerated');
  const summarySent = document.getElementById('certificateSummarySent');
  const summaryPending = document.getElementById('certificateSummaryPending');
  const syncSgaButton = document.getElementById('certificateSyncSga');
  const busyOverlay = document.getElementById('certificateBusyOverlay');
  const busyTitle = document.getElementById('certificateBusyTitle');
  const busyText = document.getElementById('certificateBusyText');
  const token = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
  let isProcessing = false;
  let bodyOverflowBeforeBusy = '';

  const labels = {
    pendiente: 'Sin diploma',
    adjuntado: 'Generado',
    generado: 'Generado',
    enviado: 'Enviado',
    requiere_revision: 'Requiere revisión',
  };

  const badgeClasses = {
    pendiente: 'cert-badge cert-badge--pending',
    adjuntado: 'cert-badge cert-badge--attached',
    generado: 'cert-badge cert-badge--attached',
    enviado: 'cert-badge cert-badge--sent',
    requiere_revision: 'cert-badge cert-badge--review',
  };

  const toggleHidden = (element, shouldHide) => {
    element?.classList.toggle('hidden', shouldHide);
    element?.classList.toggle('cert-hidden', shouldHide);
  };

  const rows = () => Array.from(document.querySelectorAll('[data-certificate-row]'));

  const setGlobalBusy = (isBusy, title = 'Procesando certificado', text = 'Espera un momento.') => {
    if (isBusy) {
      if (busyTitle) {
        busyTitle.textContent = title;
      }

      if (busyText) {
        busyText.textContent = text;
      }
    }

    toggleHidden(busyOverlay, !isBusy);
    root.setAttribute('aria-busy', isBusy ? 'true' : 'false');

    if (isBusy) {
      bodyOverflowBeforeBusy = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      return;
    }

    document.body.style.overflow = bodyOverflowBeforeBusy;
  };

  const showFeedback = (type, message) => {
    if (!feedback) {
      return;
    }

    feedback.textContent = message;
    feedback.className = type === 'success'
      ? 'cert-feedback cert-feedback--success'
      : 'cert-feedback cert-feedback--error';

    window.setTimeout(() => {
      toggleHidden(feedback, true);
    }, 4000);
  };

  const readJson = async (response) => {
    const text = await response.text();

    if (!text) {
      return {};
    }

    try {
      return JSON.parse(text);
    } catch (error) {
      return {};
    }
  };

  const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const renderFileCell = (row, fileName) => {
    const fileCell = row.querySelector('[data-file-cell]');

    if (!fileCell) {
      return;
    }

    if (!fileName) {
      fileCell.innerHTML = '<span data-file-name class="cert-muted">Sin diploma</span>';
      return;
    }

    fileCell.innerHTML = `
      <div data-file-name class="cert-name">${escapeHtml(fileName)}</div>
      <div class="cert-muted">Archivo manual</div>
    `;
  };

  const applyFilters = () => {
    const allRows = rows();
    const query = (studentFilter?.value || '').trim().toLowerCase();
    const status = statusFilter?.value || '';
    let visible = 0;

    allRows.forEach((row) => {
      const matchesQuery = !query || (row.dataset.search || '').includes(query);
      const matchesStatus = !status || row.dataset.status === status;
      const isVisible = matchesQuery && matchesStatus;

      toggleHidden(row, !isVisible);

      if (isVisible) {
        visible += 1;
      }
    });

    toggleHidden(noResultsRow, allRows.length === 0 || visible !== 0);
  };

  const updateSummary = () => {
    const allRows = rows();
    const total = allRows.length;
    const sent = allRows.filter((row) => row.dataset.status === 'enviado').length;
    const generated = allRows.filter((row) => ['adjuntado', 'generado', 'enviado'].includes(row.dataset.status || '')).length;
    const pending = Math.max(0, generated - sent);

    if (summaryTotal) {
      summaryTotal.textContent = String(total);
    }

    if (summaryGenerated) {
      summaryGenerated.textContent = String(generated);
    }

    if (summarySent) {
      summarySent.textContent = String(sent);
    }

    if (summaryPending) {
      summaryPending.textContent = String(pending);
    }
  };

  const setButtonState = (row, isBusy) => {
    row.querySelectorAll('button, input').forEach((element) => {
      element.disabled = isBusy;
    });
  };

  const applyCertificateToRow = (row, certificate) => {
    const status = certificate.status || 'pendiente';
    const certificateId = certificate.certificate_id || certificate.id || '';
    const fileName = certificate.file_name || certificate.archivo_nombre || '';
    const sentAt = certificate.sent_at || certificate.fecha_envia || '';

    row.dataset.status = status;
    row.dataset.certificateId = certificateId ? String(certificateId) : '';

    const badge = row.querySelector('[data-status-badge]');
    if (badge) {
      badge.className = badgeClasses[status] || badgeClasses.pendiente;
      badge.textContent = labels[status] || labels.pendiente;
    }

    renderFileCell(row, fileName);

    const sentAtElement = row.querySelector('[data-sent-at]');
    if (sentAtElement) {
      sentAtElement.textContent = sentAt || '-';
    }

    const attachAction = row.querySelector('[data-attach-action]');
    toggleHidden(attachAction, status === 'enviado');

    const sendAction = row.querySelector('[data-send-action]');
    toggleHidden(sendAction, !['adjuntado', 'generado'].includes(status) || !certificateId);

    updateSummary();
    applyFilters();
  };

  const syncSga = async () => {
    if (isProcessing || !root.dataset.syncUrl) {
      return;
    }

    isProcessing = true;
    setGlobalBusy(true, 'Sincronizando diplomas', 'Consultando SGA y actualizando certificados.');

    try {
      const response = await fetch(root.dataset.syncUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify({}),
      });
      const payload = await readJson(response);

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'No se pudo sincronizar con SGA.');
      }

      showFeedback('success', payload.message || 'Diplomas sincronizados desde SGA.');
      window.setTimeout(() => window.location.reload(), 900);
    } catch (error) {
      showFeedback('error', error.message || 'No se pudo sincronizar con SGA.');
    } finally {
      setGlobalBusy(false);
      isProcessing = false;
    }
  };

  const copyLink = async (link) => {
    if (!link) {
      return;
    }

    try {
      await navigator.clipboard.writeText(link);
      showFeedback('success', 'Enlace copiado.');
    } catch (error) {
      showFeedback('error', 'No se pudo copiar el enlace.');
    }
  };

  const attachCertificate = async (row, file) => {
    if (!file || isProcessing) {
      return;
    }

    const validTypes = ['image/jpeg', 'image/png'];
    const validExtensions = ['.jpg', '.jpeg', '.png'];
    const lowerName = file.name.toLowerCase();
    const hasValidExtension = validExtensions.some((extension) => lowerName.endsWith(extension));

    if (!validTypes.includes(file.type) && !hasValidExtension) {
      showFeedback('error', 'El certificado debe ser una imagen JPG o PNG.');
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      showFeedback('error', 'El certificado no debe superar los 10 MB.');
      return;
    }

    const email = row.dataset.email || '';
    const url = (root.dataset.attachUrlTemplate || '').replace('__EMAIL__', encodeURIComponent(email));
    const formData = new FormData();

    formData.append('certificado', file);

    isProcessing = true;
    setGlobalBusy(
      true,
      'Adjuntando certificado',
      'Espera un momento mientras se carga la imagen.'
    );
    setButtonState(row, true);

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': token,
        },
        body: formData,
      });
      const payload = await readJson(response);

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'No se pudo adjuntar el certificado.');
      }

      applyCertificateToRow(row, payload.certificate || {});
      showFeedback('success', payload.message || 'Certificado adjuntado correctamente.');
    } catch (error) {
      showFeedback('error', error.message || 'No se pudo adjuntar el certificado.');
    } finally {
      setButtonState(row, false);
      setGlobalBusy(false);
      isProcessing = false;
      const input = row.querySelector('[data-file-input]');
      if (input) {
        input.value = '';
      }
    }
  };

  const sendCertificate = async (row) => {
    if (isProcessing) {
      return;
    }

    const certificateId = row.dataset.certificateId || '';

    if (!certificateId) {
      showFeedback('error', 'Primero debes adjuntar un certificado.');
      return;
    }

    const url = (root.dataset.sendUrlTemplate || '').replace('__CERTIFICATE__', encodeURIComponent(certificateId));

    isProcessing = true;
    setGlobalBusy(
      true,
      'Enviando certificado',
      'Espera un momento mientras se envía el correo al alumno.'
    );
    setButtonState(row, true);

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify({}),
      });
      const payload = await readJson(response);

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'No se pudo enviar el certificado.');
      }

      applyCertificateToRow(row, payload.certificate || {});
      showFeedback('success', payload.message || 'Certificado enviado correctamente.');
    } catch (error) {
      showFeedback('error', error.message || 'No se pudo enviar el certificado.');
    } finally {
      setButtonState(row, false);
      setGlobalBusy(false);
      isProcessing = false;
    }
  };

  root.addEventListener('click', (event) => {
    const attachButton = event.target.closest('[data-attach-action]');
    const sendButton = event.target.closest('[data-send-action]');
    const copyButton = event.target.closest('[data-copy-action]');

    if (isProcessing) {
      return;
    }

    if (attachButton) {
      const row = attachButton.closest('[data-certificate-row]');
      row?.querySelector('[data-file-input]')?.click();
      return;
    }

    if (sendButton) {
      const row = sendButton.closest('[data-certificate-row]');
      if (row) {
        sendCertificate(row);
      }
      return;
    }

    if (copyButton) {
      copyLink(copyButton.dataset.link || '');
    }
  });

  root.addEventListener('change', (event) => {
    const input = event.target.closest('[data-file-input]');

    if (!input) {
      return;
    }

    const row = input.closest('[data-certificate-row]');
    const file = input.files?.[0] || null;

    if (row && file) {
      attachCertificate(row, file);
    }
  });

  studentFilter?.addEventListener('input', applyFilters);
  statusFilter?.addEventListener('change', applyFilters);
  syncSgaButton?.addEventListener('click', syncSga);

  updateSummary();
  applyFilters();
});
