@php
  $certificate = is_array($certificate ?? null) ? $certificate : null;
  $error = trim((string) ($error ?? ''));
  $status = (string) ($certificate['status'] ?? ($error !== '' ? 'error' : 'no_disponible'));
  $label = (string) ($certificate['label'] ?? ($error !== '' ? 'No disponible' : 'No disponible'));
  $message = (string) ($certificate['message'] ?? ($error !== '' ? $error : 'El certificado estara disponible cuando completes el curso.'));
  $publicUrl = trim((string) ($certificate['public_url'] ?? ''));
  $previewUrl = trim((string) ($certificate['preview_url'] ?? $publicUrl));
  $downloadUrl = trim((string) ($certificate['download_url'] ?? $publicUrl));
  $canOpen = in_array($status, ['disponible', 'enviado'], true) && $publicUrl !== '';
  $statusClass = match ($status) {
      'disponible', 'enviado' => 'is-ready',
      'en_preparacion' => 'is-pending',
      'requiere_revision', 'error' => 'is-review',
      default => 'is-muted',
  };
@endphp

<section class="student-certificate-card {{ $statusClass }}" aria-labelledby="studentCertificateTitle">
  <div class="student-certificate-card__head">
    <span class="student-certificate-card__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M7 3h10a2 2 0 0 1 2 2v14l-3-1.5L13 19l-3-1.5L7 19V5a2 2 0 0 1 2-2Z"/><path d="M10 8h4M10 12h4"/></svg>
    </span>
    <div>
      <strong id="studentCertificateTitle">Mi certificado</strong>
      <span>{{ $label }}</span>
    </div>
  </div>

  <p>{{ $message }}</p>

  @if(!empty($certificate['code']))
    <div class="student-certificate-card__meta">Codigo: <strong>{{ $certificate['code'] }}</strong></div>
  @endif

  @if(!empty($certificate['sent_at']))
    <div class="student-certificate-card__meta">Enviado: <strong>{{ $certificate['sent_at'] }}</strong></div>
  @endif

  @if($canOpen)
    <div class="student-certificate-card__actions">
      <button
        type="button"
        data-certificate-preview
        data-preview-url="{{ $previewUrl }}"
        data-download-url="{{ $downloadUrl }}"
        data-public-url="{{ $publicUrl }}"
        data-title="Mi certificado">
        Ver certificado
      </button>
      <a href="{{ $downloadUrl }}" target="_blank" rel="noopener noreferrer" download>Descargar</a>
    </div>
    <button type="button" class="student-certificate-card__copy" data-copy-certificate-url="{{ $publicUrl }}">
      Copiar enlace
    </button>
  @endif
</section>
