@extends('layouts.main')

@section('title', 'Asistencia de '.$course->title.' | Aula Virtual')
@section('meta-description', 'Revisa las sesiones y el detalle de asistencia del curso seleccionado.')

@section('content')
@php
  $sessionLabels = [
    'reconciled' => 'Conciliada', 'pending' => 'Pendiente', 'no_records' => 'Sin registros',
    'upcoming' => 'Próxima', 'not_applicable' => 'No aplica',
  ];
  $teacherLabels = [
    'presente' => 'Presente', 'tardanza' => 'Tardanza', 'falta' => 'Falta',
    'justificada' => 'Justificada', 'no_aplica' => 'No aplica', 'pendiente' => 'Pendiente',
  ];
@endphp

<div class="page-shell attendance-detail-page">
  <nav class="attendance-breadcrumb" aria-label="Ruta de navegación">
    <a href="{{ route('backoffice.attendance.index') }}">Asistencia</a><span aria-hidden="true">&rsaquo;</span><span>{{ $course->title }}</span>
  </nav>

  <header class="attendance-detail-header">
    <div>
      <div class="backoffice-course-card__chips">
        @if(!empty($course->edition))<span class="backoffice-course-chip">Edición {{ $course->edition }}</span>@endif
        <span class="backoffice-course-chip backoffice-course-chip--muted">{{ $course->tab === 'completados' ? 'Finalizado' : ($course->tab === 'programados' ? 'Programado' : 'En curso') }}</span>
      </div>
      <h1>{{ $course->title }}</h1>
      @if($isAdmin && !empty($course->teacher))<p>Responsable: {{ $course->teacher }}</p>@endif
      <p title="{{ $course->schedule ?? '' }}">{{ $course->schedule_label ?: 'Horario por confirmar' }}</p>
    </div>
    <div class="backoffice-metrics" aria-label="Resumen del curso">
      <div><span>Sesiones</span><strong>{{ $summary['sessions_total'] ?? 0 }}</strong></div>
      <div><span>Conciliadas</span><strong>{{ $summary['sessions_reconciled'] ?? 0 }}</strong></div>
      <div><span>Pendientes</span><strong>{{ $summary['sessions_pending'] ?? 0 }}</strong></div>
      <div><span>Por identificar</span><strong>{{ $summary['unresolved_count'] ?? 0 }}</strong></div>
    </div>
  </header>

  @if($error)<div class="attendance-flash is-error" role="alert">{{ $error }}</div>@endif

  @if($selectedSession)
    @php
      $position = $sessions->search(fn ($item) => $item->id === $selectedSession->id);
      $previous = $position !== false && $position > 0 ? $sessions[$position - 1] : null;
      $next = $position !== false && $position < $sessions->count() - 1 ? $sessions[$position + 1] : null;
    @endphp
    <div class="attendance-session-context">
      <a href="{{ route('backoffice.attendance.show', ['course' => $course->id, 'session_status' => $sessionStatus]) }}">&lsaquo; Volver a las sesiones</a>
      <div>
        @if($previous)<a href="{{ route('backoffice.attendance.show', ['course' => $course->id, 'session' => $previous->id]) }}">&lsaquo; Sesión anterior</a>@endif
        <strong>Sesión {{ $selectedSession->number }} de {{ $sessions->count() }}</strong>
        @if($next)<a href="{{ route('backoffice.attendance.show', ['course' => $course->id, 'session' => $next->id]) }}">Siguiente sesión &rsaquo;</a>@endif
      </div>
    </div>

    @if($sessionError)
      <div class="attendance-catalog-empty"><strong>No se pudo cargar esta sesión.</strong><p>Intenta nuevamente sin perder el contexto del curso.</p><a href="{{ request()->fullUrl() }}">Reintentar</a></div>
    @elseif($attendanceData)
      @include('backoffice.courses.partials.session-attendance', [
        'session' => $selectedSession,
        'attendance' => $attendanceData,
        'refreshUrl' => route('backoffice.courses.sessions.panels.show', [$course->id, $selectedSession->id, 'attendance']).'?standalone=1',
        'showFullAttendanceLink' => false,
        'attendanceExportUrl' => route('backoffice.attendance.course.export', [$course->id, 'session_id' => $selectedSession->id]),
        'workspaceUrl' => route('backoffice.courses.show', [$course->id, $selectedSession->id]).'?tab=attendance',
      ])
    @endif
  @else
    <nav class="attendance-segmented" aria-label="Filtrar sesiones por estado">
      @foreach(['all' => 'Todas', 'pending' => 'Pendientes', 'reconciled' => 'Conciliadas', 'upcoming' => 'Próximas'] as $value => $label)
        <a href="{{ route('backoffice.attendance.show', ['course' => $course->id, 'session_status' => $value === 'all' ? null : $value]) }}"
           @class(['is-active' => $sessionStatus === $value]) @if($sessionStatus === $value) aria-current="page" @endif>{{ $label }}</a>
      @endforeach
    </nav>

    @if($visibleSessions->isEmpty())
      <div class="attendance-catalog-empty"><strong>No hay sesiones en este estado.</strong><p>Selecciona otro filtro para continuar.</p></div>
    @else
      <div class="attendance-session-grid">
        @foreach($visibleSessions as $session)
          <article class="attendance-session-card">
            <div class="attendance-session-card__head">
              <span>Sesión {{ $session->number }}</span>
              <span class="attendance-course-state is-{{ $session->status }}">{{ $sessionLabels[$session->status] ?? 'Sin registros' }}</span>
            </div>
            <h2>{{ \App\Support\SessionPresentation::dateTimeLabel($session) ?: 'Horario por confirmar' }}</h2>
            <div class="attendance-session-card__metrics">
              <div><span>Alumnos</span><strong>{{ $session->students_count }}</strong></div>
              <div><span>Asistieron</span><strong>{{ $session->present_count }}</strong></div>
              <div><span>Pendientes</span><strong>{{ $session->pending_count }}</strong></div>
            </div>
            <p>Docente: <strong>{{ $teacherLabels[$session->teacher_status] ?? 'Pendiente' }}</strong></p>
            @if($session->unresolved_count > 0)<p class="is-warning">{{ $session->unresolved_count }} por identificar</p>@endif
            <a href="{{ route('backoffice.attendance.show', ['course' => $course->id, 'session' => $session->id, 'session_status' => $sessionStatus]) }}" class="backoffice-course-action">
              Revisar sesión <span aria-hidden="true">&rsaquo;</span>
            </a>
          </article>
        @endforeach
      </div>
    @endif
  @endif
</div>
@endsection

@push('scripts')
@vite('resources/js/session-attendance.js')
@endpush
