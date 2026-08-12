@extends('layouts.main')

@section('title', 'Aula Virtual - Certificados')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@php
  $statusConfig = [
    'pendiente' => ['label' => 'Sin diploma', 'class' => 'cert-badge cert-badge--pending'],
    'adjuntado' => ['label' => 'Generado', 'class' => 'cert-badge cert-badge--attached'],
    'generado' => ['label' => 'Generado', 'class' => 'cert-badge cert-badge--attached'],
    'enviado' => ['label' => 'Enviado', 'class' => 'cert-badge cert-badge--sent'],
    'requiere_revision' => ['label' => 'Requiere revisión', 'class' => 'cert-badge cert-badge--review'],
  ];
@endphp

@section('content')
<style>
  .cert-page{max-width:var(--shell-max);margin:0 auto;padding:26px var(--shell-pad) 42px}
  .cert-top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:20px}
  .cert-breadcrumb{display:flex;gap:10px;align-items:center;font-size:13px;color:var(--color-muted);margin-bottom:10px}
  .cert-breadcrumb a{color:var(--color-primary);font-weight:700;text-decoration:none}
  .cert-title{margin:0;color:var(--brand-dark);font-size:30px;line-height:1.15;font-weight:800}
  .cert-subtitle{margin-top:6px;color:var(--color-muted);font-size:14px}
  .cert-actions-top{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
  .cert-card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-card);box-shadow:var(--shadow-card)}
  .cert-button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border-radius:10px;border:1px solid var(--color-border);background:#fff;color:var(--brand-dark);font-weight:800;font-size:13px;text-decoration:none;padding:0 16px;cursor:pointer}
  .cert-button--primary{background:var(--color-primary);border-color:var(--color-primary);color:#fff}
  .cert-course{padding:18px 20px;margin-bottom:18px}
  .cert-course h2{margin:0;color:var(--brand-dark);font-size:20px;font-weight:800}
  .cert-course-meta{display:flex;flex-wrap:wrap;gap:8px 22px;margin-top:10px;color:var(--color-muted);font-size:14px}
  .cert-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
  .cert-summary-card{padding:16px 18px}
  .cert-summary-card span{display:block;color:var(--color-muted);font-size:13px;font-weight:700}
  .cert-summary-card strong{display:block;margin-top:8px;color:var(--brand-dark);font-size:28px;line-height:1;font-weight:900}
  .cert-feedback{margin:0 0 16px;border-radius:10px;padding:12px 14px;font-size:14px}
  .cert-feedback--success{background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}
  .cert-feedback--error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
  .cert-toolbar{display:flex;gap:14px;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--color-border)}
  .cert-toolbar h2{margin:0;font-size:19px;color:var(--brand-dark);font-weight:800}
  .cert-filters{display:grid;grid-template-columns:minmax(220px,1fr) 220px;gap:12px;padding:16px 18px;border-bottom:1px solid var(--color-border)}
  .cert-filter-input,.cert-filter-select{height:46px;border:1px solid var(--color-border);border-radius:10px;background:#fff;color:var(--brand-dark);font-size:14px;padding:0 14px}
  .cert-table{width:100%;border-collapse:collapse;font-size:14px}
  .cert-table th{padding:12px 18px;text-align:left;color:var(--color-muted);font-size:12px;text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid var(--color-border)}
  .cert-table td{padding:14px 18px;border-bottom:1px solid #eef2f7;vertical-align:middle}
  .cert-student{display:flex;align-items:center;gap:12px;min-width:0}
  .cert-avatar{width:38px;height:38px;border-radius:999px;background:var(--color-primary-soft);color:var(--color-primary);display:inline-flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex:0 0 auto}
  .cert-name{font-weight:800;color:var(--brand-dark)}
  .cert-email,.cert-muted{color:var(--color-muted);font-size:13px}
  .cert-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:800;border:1px solid transparent}
  .cert-badge--pending{background:#f8fafc;color:#475569;border-color:#dbe3ef}
  .cert-badge--attached{background:#eff6ff;color:var(--color-primary);border-color:#bfdbfe}
  .cert-badge--sent{background:#ecfdf5;color:#047857;border-color:#bbf7d0}
  .cert-badge--review{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
  .cert-row-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
  .cert-mini{height:34px;border-radius:9px;border:1px solid var(--color-border);background:#fff;color:var(--brand-dark);font-size:12px;font-weight:800;padding:0 11px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer}
  .cert-mini--primary{background:var(--color-primary);border-color:var(--color-primary);color:#fff}
  .cert-mini--success{background:#059669;border-color:#059669;color:#fff}
  .cert-hidden{display:none!important}
  .cert-busy-overlay{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.35);backdrop-filter:blur(3px)}
  .cert-busy-modal{width:min(360px,calc(100vw - 32px));border-radius:14px;background:#fff;box-shadow:0 22px 60px rgba(15,23,42,.22);padding:24px;text-align:center}
  .cert-busy-spinner{width:38px;height:38px;margin:0 auto 14px;border:4px solid #dbeafe;border-top-color:var(--color-primary);border-radius:999px;animation:cert-spin .8s linear infinite}
  @keyframes cert-spin{to{transform:rotate(360deg)}}
  @media(max-width:900px){.cert-top{display:block}.cert-actions-top{justify-content:flex-start;margin-top:14px}.cert-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.cert-filters{grid-template-columns:1fr}.cert-table thead{display:none}.cert-table,.cert-table tbody,.cert-table tr,.cert-table td{display:block;width:100%}.cert-table tr{padding:12px 0;border-bottom:1px solid var(--color-border)}.cert-table td{border:0;padding:8px 18px}.cert-row-actions{justify-content:flex-start}}
</style>

<div
  id="certificateDetail"
  class="cert-page"
  data-course-id="{{ $course['id'] ?? 0 }}"
  data-attach-url-template="{{ route('backoffice.certificates.attach', [$course['id'] ?? 0, '__EMAIL__']) }}"
  data-send-url-template="{{ route('backoffice.certificates.send', [$course['id'] ?? 0, '__CERTIFICATE__']) }}"
  data-sync-url="{{ route('backoffice.certificates.sync-sga', $course['id'] ?? 0) }}"
  data-csrf="{{ csrf_token() }}"
>
  <div class="cert-top">
    <div>
      <div class="cert-breadcrumb">
        <a href="{{ route('backoffice.certificates.index') }}">Certificados</a>
        <span>/</span>
        <span>{{ $course['title'] ?? 'Curso' }}</span>
      </div>
      <h1 class="cert-title">Certificados del curso</h1>
      <p class="cert-subtitle">Consulta diplomas generados en SGA, sincroniza certificados y gestiona envíos.</p>
    </div>
    <div class="cert-actions-top">
      <button type="button" id="certificateSyncSga" class="cert-button cert-button--primary">Sincronizar desde SGA</button>
      <a href="{{ route('backoffice.certificates.index') }}" class="cert-button">Volver</a>
    </div>
  </div>

  @if($error)
    <div class="cert-feedback cert-feedback--error">{{ $error }}</div>
  @endif

  @if(isset($summary['sga_available']) && !$summary['sga_available'])
    <div class="cert-feedback cert-feedback--error">
      {{ $summary['sga_message'] ?? 'No se pudo consultar SGA. Puedes seguir usando adjuntos manuales.' }}
    </div>
  @endif

  @if(($summary['sga_unidentified'] ?? 0) > 0)
    <div class="cert-feedback">
      SGA devolvio {{ $summary['sga_detected'] ?? 0 }} diploma(s), pero {{ $summary['sga_unidentified'] }} no se pudieron asociar a un alumno.
      Revisa que el diploma tenga correo o nombre del alumno para sincronizarlo automaticamente.
    </div>
  @endif

  <section class="cert-card cert-course">
    <h2>{{ $course['title'] ?? 'Curso' }}</h2>
    <div class="cert-course-meta">
      @if(!empty($course['teacher']))
        <span>Responsable: {{ $course['teacher'] }}</span>
      @endif
      <span>{{ $course['schedule_label'] ?? $course['schedule'] ?? 'Horario por confirmar' }}</span>
    </div>
  </section>

  <section class="cert-summary" aria-label="Resumen de certificados">
    <div class="cert-card cert-summary-card">
      <span>Alumnos</span>
      <strong id="certificateSummaryTotal">{{ $summary['total'] ?? 0 }}</strong>
    </div>
    <div class="cert-card cert-summary-card">
      <span>Diplomas generados</span>
      <strong id="certificateSummaryGenerated">{{ $summary['generated'] ?? 0 }}</strong>
    </div>
    <div class="cert-card cert-summary-card">
      <span>Pendientes de envío</span>
      <strong id="certificateSummaryPending">{{ $summary['pending'] ?? 0 }}</strong>
    </div>
    <div class="cert-card cert-summary-card">
      <span>Enviados</span>
      <strong id="certificateSummarySent">{{ $summary['sent'] ?? 0 }}</strong>
    </div>
  </section>

  <section class="cert-card">
    <div class="cert-toolbar">
      <h2>Alumnos y diplomas</h2>
      <div id="certificateFeedback" class="cert-hidden cert-feedback"></div>
    </div>

    <div class="cert-filters">
      <label class="sr-only" for="certificateStudentFilter">Buscar alumno</label>
      <input id="certificateStudentFilter" type="search" class="cert-filter-input" placeholder="Buscar por nombre o correo">

      <label class="sr-only" for="certificateStatusFilter">Filtrar estado</label>
      <select id="certificateStatusFilter" class="cert-filter-select">
        <option value="">Todos los estados</option>
        <option value="pendiente">Sin diploma</option>
        <option value="generado">Generado</option>
        <option value="adjuntado">Generado sincronizado</option>
        <option value="enviado">Enviado</option>
        <option value="requiere_revision">Requiere revisión</option>
      </select>
    </div>

    <table class="cert-table">
      <thead>
        <tr>
          <th>Alumno</th>
          <th>Estado</th>
          <th>Diploma</th>
          <th>Envío</th>
          <th style="text-align:right">Acciones</th>
        </tr>
      </thead>
      <tbody id="certificateStudentsBody">
        @forelse($students as $student)
          @php
            $status = $student['status'] ?? 'pendiente';
            $config = $statusConfig[$status] ?? $statusConfig['pendiente'];
            $email = $student['email'] ?? '';
            $fullName = $student['full_name'] ?: trim(($student['names'] ?? '') . ' ' . ($student['last_names'] ?? ''));
            $initials = collect(explode(' ', $fullName))->filter()->take(2)->map(fn($part) => mb_substr($part, 0, 1))->implode('');
            $diploma = $student['diploma'] ?? [];
            $publicLink = $student['public_link'] ?? $diploma['public_url'] ?? null;
            $fileName = $student['file_name'] ?: (($student['diploma_code'] ?? null) ? 'Diploma '.$student['diploma_code'] : null);
            $source = $student['source'] ?? null;
          @endphp

          <tr
            data-certificate-row
            data-email="{{ $email }}"
            data-search="{{ strtolower($fullName . ' ' . $email) }}"
            data-status="{{ $status }}"
            data-certificate-id="{{ $student['certificate_id'] ?? '' }}"
          >
            <td>
              <div class="cert-student">
                <span class="cert-avatar">{{ $initials ?: 'AL' }}</span>
                <div>
                  <div class="cert-name">{{ $fullName ?: 'Alumno' }}</div>
                  <div class="cert-email">{{ $email ?: 'Sin correo' }}</div>
                </div>
              </div>
            </td>
            <td>
              <span data-status-badge class="{{ $config['class'] }}">{{ $config['label'] }}</span>
            </td>
            <td data-file-cell>
              @if($fileName || $source === 'sga_diplomas')
                <div data-file-name class="cert-name">{{ $fileName ?: 'Diploma SGA' }}</div>
                <div class="cert-muted">{{ $source === 'sga_diplomas' ? 'Generado en SGA' : 'Archivo manual' }}</div>
              @else
                <span data-file-name class="cert-muted">Sin diploma</span>
              @endif
            </td>
            <td>
              <span data-sent-at>{{ $student['sent_at'] ?: '-' }}</span>
            </td>
            <td>
              <div class="cert-row-actions">
                @if($publicLink)
                  <a href="{{ $publicLink }}" target="_blank" rel="noopener noreferrer" class="cert-mini">Ver</a>
                  <button type="button" data-copy-action data-link="{{ $publicLink }}" class="cert-mini">Copiar</button>
                @endif
                <button type="button" data-attach-action class="{{ $status === 'enviado' ? 'cert-hidden' : '' }} cert-mini">Adjuntar</button>
                <input data-file-input type="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png" class="cert-hidden">
                <button type="button" data-send-action class="{{ in_array($status, ['adjuntado', 'generado'], true) && !empty($student['certificate_id']) ? '' : 'cert-hidden' }} cert-mini cert-mini--success">Enviar</button>
              </div>
            </td>
          </tr>
        @empty
          <tr data-empty-row>
            <td colspan="5" class="cert-muted" style="text-align:center;padding:32px">No hay alumnos inscritos para este curso.</td>
          </tr>
        @endforelse

        <tr id="certificateNoResultsRow" class="cert-hidden">
          <td colspan="5" class="cert-muted" style="text-align:center;padding:32px">No se encontraron alumnos con esos filtros.</td>
        </tr>
      </tbody>
    </table>
  </section>

  <div id="certificateBusyOverlay" class="cert-hidden cert-busy-overlay" role="alertdialog" aria-modal="true" aria-live="assertive" aria-labelledby="certificateBusyTitle">
    <div class="cert-busy-modal">
      <div class="cert-busy-spinner" aria-hidden="true"></div>
      <div id="certificateBusyTitle" class="cert-name">Procesando</div>
      <div id="certificateBusyText" class="cert-muted">Espera un momento.</div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
@vite('resources/js/backoffice-certificates-show.js')
@endpush
