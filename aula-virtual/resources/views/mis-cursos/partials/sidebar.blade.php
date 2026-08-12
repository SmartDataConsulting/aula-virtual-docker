@php
  $items = collect($sessions ?? [])->values();
  $studentState = function ($item): array {
      $lifecycle = \App\Support\SessionPresentation::lifecycle($item);
      $surveyPending = collect($item->surveys ?? [])->contains(fn ($survey) => ($survey->status ?? null) === 'pending');
      $evaluationPending = (bool) ($item->evaluation_pending ?? false);
      $pending = in_array($lifecycle, ['in_progress', 'finished'], true) && ($surveyPending || $evaluationPending);
      $complete = $lifecycle === 'finished' && !$pending;
      return compact('lifecycle', 'pending', 'complete');
  };
  $pendingCount = $items->filter(fn ($item) => $studentState($item)['pending'])->count();
  $completeCount = $items->filter(fn ($item) => $studentState($item)['complete'])->count();
  $upcomingCount = $items->filter(fn ($item) => $studentState($item)['lifecycle'] === 'upcoming')->count();
  $completePercent = $items->isEmpty() ? 0 : round(($completeCount / $items->count()) * 100, 1);
  $requestedTab = request()->query('tab', $sidebarDefaultTab ?? 'video');
  $tabAliases = ['material' => 'materials', 'evaluacion' => 'evaluations', 'encuestas' => 'surveys', 'anuncios' => 'announcements', 'asistencia' => 'attendance'];
  $requestedTab = $tabAliases[$requestedTab] ?? $requestedTab;
  $sessionQuery = $requestedTab !== 'video' ? '?tab='.urlencode($requestedTab) : '';
@endphp

<div class="course-sidebar-card">
  <div class="course-sidebar-mobile-head">
    <strong>Sesiones del curso</strong>
    <button type="button" data-close-session-drawer aria-label="Cerrar sesiones"><span aria-hidden="true">&times;</span></button>
  </div>

  <div class="course-progress-block">
    <div class="course-progress-row"><span>Tu avance</span><strong>{{ $completeCount }} de {{ $items->count() }} sesiones</strong></div>
    <div class="progress-line" role="progressbar" aria-label="Sesiones completadas"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $completePercent }}">
      <i style="width: {{ $completePercent }}%;"></i>
    </div>
  </div>

  @if(isset($studentCertificate) || !empty($studentCertificateError))
    @include('mis-cursos.partials.certificate-card', [
      'certificate' => $studentCertificate ?? null,
      'error' => $studentCertificateError ?? '',
    ])
  @endif

  <a href="{{ route('mis-cursos.announcements.index', [$course->id, $session->id ?? null]) }}"
     class="course-announcement-link" data-panel-loading-message="Cargando anuncios">
    <span class="course-announcement-main"><span class="course-announcement-icon" aria-hidden="true">!</span>Anuncios generales</span>
    <span class="course-announcement-action">Ver</span>
  </a>

  <div class="course-session-tools">
    <label for="courseSessionSearch" class="sr-only">Buscar sesión</label>
    <div class="course-session-search">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" /></svg>
      <input id="courseSessionSearch" type="search" placeholder="Buscar sesión o fecha" autocomplete="off">
      <button type="button" data-clear-session-search aria-label="Limpiar búsqueda" hidden>&times;</button>
    </div>
    <div class="course-session-filters" role="group" aria-label="Filtrar sesiones">
      <button type="button" class="is-active" data-session-filter="all">Todas <span>{{ $items->count() }}</span></button>
      <button type="button" data-session-filter="attention">Por completar <span>{{ $pendingCount }}</span></button>
      <button type="button" data-session-filter="complete">Completadas <span>{{ $completeCount }}</span></button>
      <button type="button" data-session-filter="upcoming">Próximas <span>{{ $upcomingCount }}</span></button>
    </div>
  </div>

  <div class="course-session-list" id="session-list" data-session-list>
    @forelse($items as $item)
      @php
        $state = $studentState($item);
        $isSelected = !empty($session?->id) && (int) $session->id === (int) $item->id;
        $number = $item->number ?? $loop->iteration;
        $title = 'Sesión '.$number;
        $filterState = $state['lifecycle'] === 'upcoming' ? 'upcoming' : ($state['pending'] ? 'attention' : ($state['complete'] ? 'complete' : 'other'));
        $label = match ($state['lifecycle']) {
            'cancelled' => 'Cancelada',
            'in_progress' => 'En curso',
            'upcoming' => 'Próxima',
            default => $state['pending'] ? 'Por completar' : ($state['complete'] ? 'Completada' : 'Disponible'),
        };
        $searchText = mb_strtolower($title.' '.\App\Support\SessionPresentation::dateTimeLabel($item));
      @endphp
      <a href="{{ route('mis-cursos.show', [$course->id, $item->id]) }}{{ $sessionQuery }}"
         class="session-item {{ $isSelected ? 'is-selected session-current' : '' }}"
         data-session-link data-session-id="{{ $item->id }}" data-session-state="{{ $filterState }}"
         data-session-search="{{ $searchText }}" aria-current="{{ $isSelected ? 'step' : 'false' }}">
        <span class="session-step">{{ $number }}</span>
        <span class="session-item-body">
          <span class="session-item-heading"><span class="session-item-title">{{ $title }}</span><span class="session-item-meta">{{ \App\Support\SessionPresentation::dateTimeLabel($item) ?: 'Horario por confirmar' }}</span></span>
          <span class="session-item-flags"><span class="session-flag is-{{ $state['lifecycle'] }} {{ $state['pending'] ? 'session-flag-warning' : ($state['complete'] ? 'session-flag-success' : '') }}">{{ $label }}</span></span>
        </span>
      </a>
    @empty
      <div class="course-session-empty">No hay sesiones disponibles.</div>
    @endforelse
    <div class="course-session-empty" data-session-no-results hidden>No encontramos sesiones con esos criterios.</div>
  </div>
</div>
