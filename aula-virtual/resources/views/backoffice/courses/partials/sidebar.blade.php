@php
  use App\Support\SessionPresentation;

  $items = collect($sessions ?? [])->values();
  $attentionCount = $items->filter(fn ($item) => SessionPresentation::requiresAttention($item))->count();
  $completeCount = $items->filter(fn ($item) => SessionPresentation::isComplete($item))->count();
  $upcomingCount = $items->filter(fn ($item) => SessionPresentation::isUpcoming($item))->count();
  $completePercent = $items->isEmpty() ? 0 : round(($completeCount / $items->count()) * 100, 1);
@endphp

<div class="course-sidebar-card">
  <div class="course-sidebar-mobile-head">
    <strong>Sesiones del curso</strong>
    <button type="button" data-close-session-drawer aria-label="Cerrar sesiones">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>

  <div class="course-progress-block">
    <div class="course-progress-row">
      <span>Sesiones completas</span>
      <strong>{{ $completeCount }} de {{ $items->count() }}</strong>
    </div>
    <div class="progress-line" role="progressbar"
         aria-label="Sesiones completas"
         aria-valuemin="0" aria-valuemax="100"
         aria-valuenow="{{ $completePercent }}">
      <i style="width: {{ $completePercent }}%;"></i>
    </div>
  </div>

  <a href="{{ route('backoffice.courses.announcements.index', [
      'course' => $course->id,
      'session' => $session->id ?? null,
  ]) }}" class="course-announcement-link">
    <span class="course-announcement-main">
      <span class="course-announcement-icon" aria-hidden="true">!</span>
      Anuncios generales
    </span>
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
      <button type="button" data-session-filter="attention">Requieren atención <span>{{ $attentionCount }}</span></button>
      <button type="button" data-session-filter="complete">Completas <span>{{ $completeCount }}</span></button>
      <button type="button" data-session-filter="upcoming">Próximas <span>{{ $upcomingCount }}</span></button>
    </div>
  </div>

  <div class="course-session-list" id="session-list" data-session-list>
    @forelse($items as $item)
      @php
        $isSelected = !empty($session?->id) && (int) $session->id === (int) $item->id;
        $number = $item->number ?? $loop->iteration;
        $title = trim((string) ($item->title ?? ''));
        if ($title === '' || in_array(mb_strtolower($title), ['sesión', 'sesion'], true)) {
            $title = 'Sesión '.$number;
        }
        $lifecycle = SessionPresentation::lifecycle($item);
        $tasks = SessionPresentation::missingTasks($item);
        $isComplete = SessionPresentation::isComplete($item);
        $needsAttention = SessionPresentation::requiresAttention($item);
        $isUpcoming = $lifecycle === 'upcoming';
        $filterState = $isUpcoming ? 'upcoming' : ($needsAttention ? 'attention' : ($isComplete ? 'complete' : 'other'));
        $searchText = mb_strtolower($title.' '.SessionPresentation::dateTimeLabel($item));
        $primaryTask = $tasks[0] ?? null;
        $additionalTasks = max(0, count($tasks) - 1);
      @endphp

      <a href="{{ route('backoffice.courses.show', [$course->id, $item->id]) }}"
         class="session-item {{ $isSelected ? 'is-selected session-current' : '' }}"
         data-session-link
         data-session-id="{{ $item->id }}"
         data-session-state="{{ $filterState }}"
         data-session-search="{{ $searchText }}"
         aria-current="{{ $isSelected ? 'step' : 'false' }}">
        <span class="session-step">{{ $number }}</span>
        <span class="session-item-body">
          <span class="session-item-heading">
            <span class="session-item-title">{{ $title }}</span>
            <span class="session-item-meta">{{ SessionPresentation::dateTimeLabel($item) ?: 'Horario por confirmar' }}</span>
          </span>
          <span class="session-item-flags">
            @if($lifecycle === 'cancelled')
              <span class="session-flag">Cancelada</span>
            @elseif($isComplete)
              <span class="session-flag session-flag-success">{{ $isUpcoming ? 'Sesión preparada' : 'Sesión completa' }}</span>
            @elseif($isUpcoming)
              <span class="session-flag">Preparación pendiente</span>
              @if(count($tasks) > 1)<span class="session-flag-count">{{ count($tasks) }} tareas</span>@endif
            @elseif($primaryTask)
              <span class="session-flag session-flag-warning">{{ $primaryTask['label'] }}</span>
              @if($additionalTasks > 0)<span class="session-flag-count">+{{ $additionalTasks }}</span>@endif
            @endif
          </span>
        </span>
      </a>
    @empty
      <div class="course-session-empty">No hay sesiones disponibles.</div>
    @endforelse

    <div class="course-session-empty" data-session-no-results hidden>
      No encontramos sesiones con esos criterios.
    </div>
  </div>
</div>
