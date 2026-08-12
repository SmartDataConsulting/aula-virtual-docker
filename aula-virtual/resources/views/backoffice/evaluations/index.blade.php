@extends('layouts.main')

@section('title', 'Aula Virtual - Evaluaciones')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
@php
  $isAdmin = session(\App\Support\AuthSessionKeys::USER_ROLE) === 'admin';
  $totalCursos = $cursos->count();
@endphp

<div class="page-header">
  @if($isAdmin)
    <span class="inline-flex rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
      Panel administrativo
    </span>
  @endif
  <h1>Evaluaciones</h1>
  <p class="text-sm text-gray-500">
    {{ $isAdmin ? 'Supervisa configuracion, publicacion y responsables por curso.' : 'Selecciona un curso para crear, editar y publicar evaluaciones.' }}
  </p>
</div>

<div class="page-shell">
  <section class="qualification-search-toolbar" aria-label="Busqueda de cursos">
    <form id="evaluationsSearchForm" role="search" data-no-global-loader>
      <label for="courseSearchEvaluaciones" class="sr-only">Buscar por curso o codigo</label>
      <div class="qualification-search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <input
          id="courseSearchEvaluaciones"
          type="search"
          placeholder="Buscar por curso o codigo"
          autocomplete="off"
        >
      </div>
    </form>
    <p id="evaluationsSearchHint" class="qualification-search-help">
      Mostrando {{ $totalCursos }} {{ $totalCursos === 1 ? 'curso disponible' : 'cursos disponibles' }}
    </p>
  </section>

  @if($error)
    <div class="mb-6 rounded-lg bg-red-50 p-3 text-sm text-red-700">
      {{ $error }}
    </div>
  @endif

  @if($cursos->isEmpty())
    <div class="text-sm text-gray-500">
      No hay cursos disponibles para gestionar evaluaciones.
    </div>
  @else
    <div id="evaluacionesGrid" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
      @foreach($cursos as $curso)
        @php
          $evaluaciones = (int) ($curso['nro_evaluaciones'] ?? 0);
          $edicion = trim((string) ($curso['edicion'] ?? ''));
          $nombre = trim((string) ($curso['nombre'] ?? 'Curso'));
          $horario = trim((string) ($curso['horario'] ?? ''));
          $horarioLabel = trim((string) ($curso['schedule_label'] ?? $horario));
          $teacher = trim((string) ($curso['docente'] ?? ''));
          $studentsCount = (int) ($curso['alumnos_inscritos'] ?? 0);
          $publishedCount = (int) ($curso['evaluaciones_publicadas'] ?? 0);
          $draftCount = (int) ($curso['evaluaciones_borrador'] ?? 0);
        @endphp

        <article
          class="backoffice-course-card evaluacion-course-card"
          data-course-name="{{ strtolower($nombre . ' ' . $edicion . ' ' . $teacher . ' ' . ($curso['curso_id'] ?? '')) }}"
        >
          <div class="backoffice-course-card__body">
            <div class="backoffice-course-card__chips">
              @if($edicion !== '')
                <span class="backoffice-course-chip">Edicion {{ $edicion }}</span>
              @endif
              <span class="backoffice-course-chip backoffice-course-chip--muted">
                {{ $evaluaciones === 0 ? 'Sin evaluaciones' : 'Con evaluaciones' }}
              </span>
            </div>

            <h2 class="backoffice-course-card__title">{{ $nombre }}</h2>

            @if($isAdmin && $teacher !== '')
              <p class="backoffice-course-card__schedule" title="{{ $teacher }}">
                Responsable: {{ $teacher }}
              </p>
            @endif

            <p class="backoffice-course-card__schedule" title="{{ $horario }}">
              {{ $horarioLabel !== '' ? $horarioLabel : 'Horario por confirmar' }}
            </p>

            <div class="backoffice-course-card__metrics">
              <span class="backoffice-course-pill">
                Evaluaciones <strong>{{ $evaluaciones }}</strong>
              </span>
              @if($isAdmin)
                <span class="backoffice-course-pill">
                  Alumnos <strong>{{ $studentsCount }}</strong>
                </span>
                <span class="backoffice-course-pill">
                  Publicadas <strong>{{ $publishedCount }}</strong>
                </span>
                <span class="backoffice-course-pill backoffice-course-pill--warning">
                  Borradores <strong>{{ $draftCount }}</strong>
                </span>
              @endif
            </div>

            <div class="mt-auto pt-4">
              <a
                href="{{ route('backoffice.evaluations.show', $curso['curso_id']) }}"
                class="backoffice-course-action"
                aria-label="Gestionar evaluaciones de {{ $nombre }}"
              >
                Gestionar evaluaciones
                <span aria-hidden="true">&rsaquo;</span>
              </a>
            </div>
          </div>
        </article>
      @endforeach
    </div>

    <div id="evaluationsNoResults" class="qualification-empty mt-6" hidden>
      No encontramos cursos con ese criterio de busqueda.
    </div>
  @endif
</div>
@endsection

@push('scripts')
@vite('resources/js/backoffice-evaluations-index.js')
@endpush
