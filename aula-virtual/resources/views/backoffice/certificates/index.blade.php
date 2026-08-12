@extends('layouts.main')

@section('title', 'Aula Virtual - Certificados')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
@php
  $isAdmin = ($role ?? null) === 'admin';
  $totalCourses = $courses->total();
@endphp

<div class="page-header">
  @if($isAdmin)
    <span class="inline-flex rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
      Panel administrativo
    </span>
  @endif
  <h1>Certificados</h1>
  <p class="text-sm text-gray-500">
    {{ $isAdmin ? 'Supervisa diplomas generados, pendientes y envios por curso.' : 'Selecciona un curso para gestionar la emision y envio de certificados.' }}
  </p>
</div>

<div class="page-shell">

@if($isAdmin)
<section class="qualification-search-toolbar" aria-label="Busqueda de cursos">
  <form id="certificateSearchForm" method="GET" action="{{ route('backoffice.certificates.index') }}">
    <label for="certificateCourseSearch" class="sr-only">Buscar por curso, edicion o responsable</label>
    <div class="qualification-search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <input
        id="certificateCourseSearch"
        name="search"
        type="search"
        value="{{ $search ?? '' }}"
        placeholder="Buscar por curso, edicion o responsable"
        autocomplete="off"
      >
    </div>
  </form>
  <p id="certificateSearchHelp" class="qualification-search-help">
    Mostrando {{ $totalCourses }} {{ $totalCourses === 1 ? 'curso disponible' : 'cursos disponibles' }}
  </p>
</section>
@endif

@if($error)
<div class="mb-6 text-sm text-red-700 bg-red-50 p-3 rounded-lg">
  {{ $error }}
</div>
@endif

@if($courses->isEmpty())
<div class="text-sm text-gray-500">
  No hay cursos disponibles para gestionar certificados.
</div>
@else
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" data-certificate-grid>
  @foreach($courses as $course)
  @php
    $teacher = trim((string) ($course['teacher'] ?? ''));
    $schedule = trim((string) ($course['schedule'] ?? ''));
    $scheduleLabel = trim((string) ($course['schedule_label'] ?? $schedule));
    $studentsCount = (int) ($course['students_count'] ?? 0);
    $totalCertificates = (int) ($course['certificates_total'] ?? 0);
    $sentCertificates = (int) ($course['certificates_sent'] ?? 0);
    $pendingCertificates = (int) ($course['certificates_pending'] ?? 0);
    $attachedCertificates = (int) ($course['certificates_attached'] ?? 0);
    $edition = trim((string) ($course['edition'] ?? ''));
    $totalSessions = (int) ($course['total_sessions'] ?? 0);
    $sessionsDone = (int) ($course['sessions_done'] ?? 0);
  @endphp
  <article
    class="backoffice-course-card certificate-course-card js-live-certificate-card"
    data-course-name="{{ strtolower(($course['title'] ?? '') . ' ' . $edition . ' ' . $teacher) }}"
  >
    <div class="backoffice-course-card__body">
      <div class="backoffice-course-card__chips">
        @if($edition !== '')
          <span class="backoffice-course-chip">Edicion {{ $edition }}</span>
        @endif
        <span class="backoffice-course-chip backoffice-course-chip--muted">
          {{ $totalSessions === 0 ? 'Sin sesiones' : ($sessionsDone >= $totalSessions ? 'Sesiones completas' : 'En desarrollo') }}
        </span>
      </div>

      <h2 class="backoffice-course-card__title">
        {{ $course['title'] ?? 'Curso' }}
      </h2>

      @if($isAdmin && $teacher !== '')
        <p class="backoffice-course-card__schedule" title="{{ $teacher }}">
          Responsable: {{ $teacher }}
        </p>
      @endif

      <p class="backoffice-course-card__schedule" title="{{ $schedule }}">
        {{ $scheduleLabel !== '' ? $scheduleLabel : 'Horario por confirmar' }}
      </p>

      <p class="backoffice-course-card__session-summary">
        <span>Sesiones realizadas</span>
        <strong>{{ $sessionsDone }} de {{ $totalSessions }}</strong>
      </p>

      <div class="backoffice-course-card__metrics">
        @if($isAdmin)
          <span class="backoffice-course-pill">
            Alumnos <strong>{{ $studentsCount }}</strong>
          </span>
        @endif
        <span class="backoffice-course-pill">
          Alumnos <strong>{{ $totalCertificates ?: $studentsCount }}</strong>
        </span>
        <span class="backoffice-course-pill">
          Generados <strong>{{ $attachedCertificates }}</strong>
        </span>
        <span class="backoffice-course-pill backoffice-course-pill--warning">
          Pendientes de envio <strong>{{ $pendingCertificates }}</strong>
        </span>
        <span class="backoffice-course-pill">
          Enviados <strong>{{ $sentCertificates }}</strong>
        </span>
      </div>

      <div class="backoffice-course-progress">
        <div class="backoffice-course-progress__head">
          <span>Avance de sesiones</span>
          <strong>{{ $course['progress_percent'] ?? 0 }}%</strong>
        </div>
        <div class="backoffice-course-progress__bar">
          <div
            class="backoffice-course-progress__fill"
            style="width:{{ min(100, max(0, (float) ($course['progress_percent'] ?? 0))) }}%"
          ></div>
        </div>
        <div class="mt-2 text-xs text-gray-500">
          {{ $course['progress_label'] ?? '0 de 0' }} sesiones realizadas
        </div>
      </div>

      <div class="mt-auto pt-4">
        <a
          href="{{ route('backoffice.certificates.show', $course['id']) }}"
          class="backoffice-course-action"
        >
          Gestionar certificados
          <span aria-hidden="true">&rsaquo;</span>
        </a>
      </div>
    </div>
  </article>
  @endforeach
</div>

<div id="certificateNoResults" class="qualification-empty mt-6" hidden>
  No encontramos cursos con ese criterio de busqueda.
</div>

<div class="smart-pagination-wrap">
  {{ $courses->withQueryString()->links() }}
</div>
@endif

</div>
@endsection

@push('scripts')
@vite('resources/js/backoffice-certificates-index.js')
@endpush
