@extends('layouts.main')

@section('title', 'Aula Virtual - Encuestas')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
@php
  $isAdmin = session(\App\Support\AuthSessionKeys::USER_ROLE) === 'admin';
  $totalCourses = $courses->total();
  $metrics = $surveyMetrics ?? ['courses' => $totalCourses, 'responses' => 0, 'with_responses' => 0];
@endphp

<div class="page-header">
  @if($isAdmin)
    <span class="inline-flex rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
      Panel administrativo
    </span>
  @endif
  <h1>Encuestas</h1>
  <p class="text-sm text-gray-500">
    {{ $isAdmin ? 'Revisa participación y resultados de encuestas por curso.' : 'Selecciona un curso para revisar los resultados de sus encuestas.' }}
  </p>
</div>

<div class="page-shell">

<section class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Resumen de encuestas">
  <article class="backoffice-summary-card"><span>Cursos visibles</span><strong>{{ $metrics['courses'] }}</strong></article>
  <article class="backoffice-summary-card"><span>Respuestas recibidas</span><strong>{{ $metrics['responses'] }}</strong></article>
  <article class="backoffice-summary-card"><span>Cursos con participación</span><strong>{{ $metrics['with_responses'] }}</strong></article>
</section>

<section class="qualification-search-toolbar" aria-label="Búsqueda de cursos">
  <form id="surveySearchForm" method="GET" action="{{ route('backoffice.surveys.index') }}">
    <label for="surveyCourseSearch" class="sr-only">Buscar por curso, edición o responsable</label>
    <div class="qualification-search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <input
        id="surveyCourseSearch"
        name="search"
        type="search"
        value="{{ $search ?? '' }}"
        placeholder="Buscar por curso, edición o responsable"
        autocomplete="off"
      >
    </div>
  </form>
  <p id="surveySearchHelp"
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
  No hay cursos disponibles para revisar encuestas.
</div>
@else
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
  @foreach($courses as $course)
  @php
    $edition = trim((string) ($course['edition'] ?? $course['edicion'] ?? ''));
    $schedule = trim((string) ($course['schedule'] ?? ''));
    $scheduleLabel = trim((string) ($course['schedule_label'] ?? $schedule));
    $teacher = trim((string) ($course['teacher'] ?? ''));
    $studentsCount = (int) ($course['students_count'] ?? 0);
    $surveyResponses = (int) ($course['survey_response_count'] ?? 0);
    $totalSessions = (int) ($course['total_sessions'] ?? 0);
    $sessionsDone = (int) ($course['sessions_done'] ?? 0);
    $expectedResponses = $studentsCount * $sessionsDone;
    $responseRate = $expectedResponses > 0 ? min(100, round(($surveyResponses / $expectedResponses) * 100)) : 0;
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
    class="backoffice-course-card js-filterable-course-card"
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
        <span>Participación estimada</span>
        <strong>{{ $responseRate }}%</strong>
      </p>
      <p class="backoffice-course-card__session-summary">
        <span>Sesiones realizadas</span>
        <strong>{{ $sessionsDone }} de {{ $totalSessions }}</strong>
      </p>

      <div class="backoffice-course-card__metrics">
        <span class="backoffice-course-pill">
          Alumnos <strong>{{ $studentsCount }}</strong>
        </span>
        <span class="backoffice-course-pill {{ $surveyResponses === 0 ? 'backoffice-course-pill--warning' : '' }}">
          Respuestas <strong>{{ $surveyResponses }}</strong>
        </span>
      </div>

      <div class="mt-4">
        @if($surveyResponses > 0)
          <span class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">
            Con resultados para revisar
          </span>
        @else
          <span class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-medium text-slate-600">
            Sin respuestas registradas
          </span>
        @endif
      </div>

      <div class="mt-auto pt-4">
        <a
          href="{{ route('backoffice.surveys.results', $course['id']) }}"
          class="backoffice-course-action"
        >
          Ver resultados
          <span aria-hidden="true">&rsaquo;</span>
        </a>
      </div>
    </div>
  </article>
  @endforeach
</div>

<div id="surveyNoResults" class="qualification-empty mt-6" hidden>
  No encontramos cursos con ese criterio de búsqueda.
</div>

<div class="smart-pagination-wrap">
  {{ $courses->withQueryString()->links() }}
</div>
@endif

</div>
@endsection

@push('scripts')
@vite('resources/js/backoffice-surveys-index.js')
@endpush
