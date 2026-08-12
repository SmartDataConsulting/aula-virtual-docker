@extends('layouts.main')

@section('title', 'Asistencia | Aula Virtual')
@section('meta-description', 'Supervisa la asistencia académica por curso y revisa las sesiones que requieren atención.')

@section('content')
@php
  $statusLabels = [
    'attention' => 'Requiere atención',
    'up_to_date' => 'Al día',
    'no_records' => 'Sin registros',
    'unavailable' => 'Resumen no disponible',
  ];
@endphp

<div class="page-header attendance-catalog-heading">
  <div>
    <span class="backoffice-eyebrow">Panel {{ $isAdmin ? 'administrativo' : 'docente' }}</span>
    <h1>Asistencia</h1>
    <p>Selecciona un curso para revisar sesiones, ingresos confirmados y pendientes.</p>
  </div>
  <div class="backoffice-metrics" aria-label="Resumen general de asistencia">
    <div><span>Cursos supervisados</span><strong>{{ $metrics['courses'] }}</strong></div>
    <div><span>Sesiones conciliadas</span><strong>{{ $metrics['reconciled'] }}</strong></div>
    <div><span>Sesiones pendientes</span><strong>{{ $metrics['pending'] }}</strong></div>
    <div><span>Por identificar</span><strong>{{ $metrics['unresolved'] }}</strong></div>
  </div>
</div>

<div class="page-shell attendance-catalog">
  @if($error)
    <div class="attendance-flash is-error" role="alert">{{ $error }}</div>
  @elseif($summaryError)
    <div class="attendance-flash is-error" role="alert">
      Los cursos están disponibles, pero sus resúmenes de asistencia no pudieron actualizarse.
    </div>
  @endif

  <section class="qualification-search-toolbar" aria-label="Búsqueda de cursos">
    <form method="GET" action="{{ route('backoffice.attendance.index') }}" role="search">
      @if($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@endif
      <label for="attendanceCourseSearch" class="sr-only">Buscar por curso, edición o responsable</label>
      <div class="qualification-search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <input id="attendanceCourseSearch" name="search" type="search" value="{{ $search }}"
               placeholder="Buscar por curso, edición o responsable" autocomplete="off">
      </div>
    </form>
    <p class="qualification-search-help">
      Mostrando {{ $courses->total() }} {{ $courses->total() === 1 ? 'curso disponible' : 'cursos disponibles' }}
    </p>
  </section>

  <nav class="attendance-segmented" aria-label="Filtrar cursos por estado">
    @foreach(['all' => 'Todos', 'attention' => 'Requieren atención', 'up_to_date' => 'Al día', 'no_records' => 'Sin registros'] as $value => $label)
      <a href="{{ route('backoffice.attendance.index', array_filter(['status' => $value === 'all' ? null : $value, 'search' => $search ?: null])) }}"
         @class(['is-active' => $status === $value]) @if($status === $value) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
  </nav>

  @if($courses->isEmpty())
    <div class="attendance-catalog-empty">
      <strong>No encontramos cursos con esos criterios.</strong>
      <p>Prueba con otro nombre o cambia el filtro de estado.</p>
      <a href="{{ route('backoffice.attendance.index') }}">Limpiar filtros</a>
    </div>
  @else
    <div class="attendance-course-grid">
      @foreach($courses as $course)
        @php
          $state = $course['attendance_status'] ?? 'unavailable';
          $lastSync = null;
          if (!empty($course['last_sync_at'])) {
            try { $lastSync = \Carbon\Carbon::parse($course['last_sync_at'])->format('d/m/Y H:i'); } catch (\Throwable) {}
          }
        @endphp
        <article class="backoffice-course-card attendance-course-card">
          <div class="backoffice-course-card__body">
            <div class="backoffice-course-card__chips">
              @if(!empty($course['edition']))<span class="backoffice-course-chip">Edición {{ $course['edition'] }}</span>@endif
              <span class="attendance-course-state is-{{ $state }}">{{ $statusLabels[$state] ?? 'Sin registros' }}</span>
            </div>

            <h2 class="backoffice-course-card__title">{{ $course['title'] ?? 'Curso' }}</h2>
            @if($isAdmin && !empty($course['teacher']))
              <p class="backoffice-course-card__schedule">Responsable: {{ $course['teacher'] }}</p>
            @endif
            <p class="backoffice-course-card__schedule" title="{{ $course['schedule'] ?? '' }}">
              {{ $course['schedule_label'] ?: 'Horario por confirmar' }}
            </p>

            @if(($course['summary_available'] ?? true))
              <p class="backoffice-course-card__session-summary">
                <span>Sesiones conciliadas</span>
                <strong>{{ $course['sessions_reconciled'] }} de {{ $course['sessions_finished'] }}</strong>
              </p>
              <div class="backoffice-course-card__metrics">
                <span class="backoffice-course-pill {{ $course['sessions_pending'] > 0 ? 'backoffice-course-pill--warning' : '' }}">
                  Pendientes <strong>{{ $course['sessions_pending'] }}</strong>
                </span>
                <span class="backoffice-course-pill {{ $course['unresolved_count'] > 0 ? 'backoffice-course-pill--warning' : '' }}">
                  Por identificar <strong>{{ $course['unresolved_count'] }}</strong>
                </span>
              </div>
              <p class="attendance-course-card__updated">
                {{ $lastSync ? 'Última conciliación: '.$lastSync : 'Todavía no se ha conciliado una sesión.' }}
              </p>
            @else
              <div class="attendance-course-card__unavailable">Resumen no disponible. Puedes abrir el curso e intentarlo nuevamente.</div>
            @endif

            <div class="mt-auto pt-4">
              <a href="{{ route('backoffice.attendance.show', $course['id']) }}" class="backoffice-course-action">
                Ver asistencia <span aria-hidden="true">&rsaquo;</span>
              </a>
            </div>
          </div>
        </article>
      @endforeach
    </div>

    <div class="smart-pagination-wrap">{{ $courses->withQueryString()->links() }}</div>
  @endif
</div>
@endsection
