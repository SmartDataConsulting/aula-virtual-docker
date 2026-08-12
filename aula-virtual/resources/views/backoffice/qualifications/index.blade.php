@extends('layouts.main')

@section('title', 'Aula Virtual - Calificaciones')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
@php
  $isAdmin = session(\App\Support\AuthSessionKeys::USER_ROLE) === 'admin';
  $totalCourses = $courses->total();
@endphp

<div class="page-header">
  @if($isAdmin)
    <span class="inline-flex rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
      Panel administrativo
    </span>
  @endif
  <h1>Calificaciones</h1>
  <p class="text-sm text-gray-500">
    {{ $isAdmin ? 'Supervisa configuracion de notas y cursos pendientes por calificar.' : 'Selecciona un curso para revisar y registrar calificaciones.' }}
  </p>
</div>

<div class="page-shell">

<section class="qualification-search-toolbar" aria-label="Busqueda de cursos">
  <form id="qualificationSearchForm" method="GET" action="{{ route('backoffice.qualifications.index') }}">
    <label for="qualificationCourseSearch" class="sr-only">Buscar por curso o codigo</label>
    <div class="qualification-search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <input
        id="qualificationCourseSearch"
        name="search"
        type="search"
        value="{{ $search ?? '' }}"
        placeholder="Buscar por curso o codigo"
        autocomplete="off"
      >
    </div>
  </form>
  <p id="qualificationSearchHelp"
     class="qualification-search-help"
     data-total-courses="{{ $totalCourses }}">
    Mostrando {{ $totalCourses }} {{ $totalCourses === 1 ? 'curso disponible' : 'cursos disponibles' }}
  </p>
</section>

@if($error)
<div class="mb-6 text-sm text-red-700 bg-red-50 p-3 rounded-lg">
  {{ $error }}
</div>
@endif

@if($courses->isEmpty())
<div class="text-sm text-gray-500">
  No hay cursos disponibles para calificar.
</div>
@else
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
  @foreach($courses as $course)
  @php
    $edition = trim((string) ($course['edition'] ?? $course['edicion'] ?? ''));
    $schedule = trim((string) ($course['schedule'] ?? ''));
    $scheduleLabel = trim((string) ($course['schedule_label'] ?? $schedule));
    $progress = (float) ($course['progress_percent'] ?? 0);
    $examCount = (int) ($course['exam_count'] ?? 0);
    $workCount = (int) ($course['work_count'] ?? 0);
    $teacher = trim((string) ($course['teacher'] ?? ''));
    $studentsCount = (int) ($course['students_count'] ?? 0);
    $totalSessions = (int) ($course['total_sessions'] ?? 0);
    $sessionsDone = (int) ($course['sessions_done'] ?? 0);
    $primaryUrl = $workCount > 0
      ? route('backoffice.qualifications.show', $course['id'])
      : route('backoffice.qualifications.notes', $course['id']);
    $primaryLabel = $workCount > 0 ? 'Calificar' : 'Ver notas';
    $searchText = strtolower(implode(' ', [
      $course['title'] ?? '',
      $course['code'] ?? '',
      $course['codigo'] ?? '',
      $course['id'] ?? '',
      $edition,
      $teacher,
    ]));
  @endphp
  <article
    class="backoffice-course-card qualification-course-card"
    data-course-name="{{ $searchText }}"
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
          Examenes <strong>{{ $examCount }}</strong>
        </span>
        <span class="backoffice-course-pill backoffice-course-pill--warning">
          Trabajos <strong>{{ $workCount }}</strong>
        </span>
      </div>

      <div class="backoffice-course-progress">
        <div class="backoffice-course-progress__head">
          <span>{{ $isAdmin ? 'Avance de sesiones' : 'Avance' }}</span>
          <strong>{{ number_format($progress, 1) }}%</strong>
        </div>
        <div class="backoffice-course-progress__bar">
          <div
            class="backoffice-course-progress__fill"
            style="width: {{ min(100, max(0, $progress)) }}%"
          ></div>
        </div>
      </div>

      @if($isAdmin)
        <div class="mt-3 flex flex-wrap gap-1.5">
          @if(($examCount + $workCount) === 0)
            <span class="rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700">
              Sin evaluaciones
            </span>
          @else
            <span class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">
              Libro de notas disponible
            </span>
          @endif
        </div>
      @endif

      <div class="mt-auto pt-4">
        <a
          href="{{ $primaryUrl }}"
          class="backoffice-course-action qualification-course-btn qualification-course-btn--primary"
        >
          {{ $primaryLabel }}
          <span aria-hidden="true">&rsaquo;</span>
        </a>
      </div>
    </div>
  </article>
  @endforeach
</div>

<div id="qualificationNoResults" class="qualification-empty mt-6" hidden>
  No encontramos cursos con ese criterio de busqueda.
</div>

<div class="smart-pagination-wrap">
  {{ $courses->withQueryString()->links() }}
</div>
@endif

</div>
@endsection

@push('scripts')
@vite('resources/js/backoffice-qualifications-index.js')
@endpush
