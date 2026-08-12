@if(empty($session?->id))
  <div class="course-empty-state">
    <div class="course-empty-icon" aria-hidden="true">!</div>
    <div>
      <div class="course-empty-title">No hay una sesión disponible</div>
      <p class="course-empty-text">{{ $error ?? 'No se encontraron sesiones para este curso.' }}</p>
      <div class="course-empty-actions">
        <a href="{{ route('backoffice.courses') }}" class="btn-secondary">Volver a {{ $coursesLabel ?? 'Mis Cursos' }}</a>
        <a href="{{ request()->fullUrl() }}" class="btn-primary">Reintentar</a>
      </div>
    </div>
  </div>
@else
@php
  $items = collect($sessions ?? [])->values();
  $index = $items->search(fn ($item) => (int) $item->id === (int) $session->id);
  $position = $index === false ? 1 : $index + 1;
  $previous = $index !== false && $index > 0 ? $items[$index - 1] : null;
  $next = $index !== false && $index < $items->count() - 1 ? $items[$index + 1] : null;
  $number = $session->number ?? $position;
  $title = trim((string) ($session->title ?? ''));
  if ($title === '' || in_array(mb_strtolower($title), ['sesión', 'sesion'], true)) {
      $title = 'Sesión '.$number;
  }
  $lifecycle = \App\Support\SessionPresentation::lifecycle($session);
  $tasks = \App\Support\SessionPresentation::missingTasks($session);
  $materialsCount = (int) ($session->materials_count ?? 0);
  $announcementsCount = (int) ($session->announcements_count ?? 0);
  $evaluationsCount = count($session->evaluaciones ?? []);
  $requestedTab = old('active_tab', request()->query('tab', 'video'));
  $activeTab = in_array($requestedTab, ['video', 'materials', 'evaluations', 'announcements', 'attendance'], true)
      ? $requestedTab
      : match ($requestedTab) {
          'material' => 'materials',
          'evaluacion' => 'evaluations',
          'anuncios' => 'announcements',
          default => 'video',
      };
  $tabs = [
      'video' => ['Video', null],
      'materials' => ['Materiales', $materialsCount],
      'evaluations' => ['Evaluación', $evaluationsCount > 0 ? $evaluationsCount : null],
      'announcements' => ['Anuncios', $announcementsCount],
      'attendance' => ['Asistencia', null],
  ];
@endphp

<div class="session-workspace" data-session-workspace data-session-id="{{ $session->id }}">
  <header class="session-overview">
    <div>
      <div class="session-kicker">Sesión {{ $position }} de {{ $items->count() }}</div>
      <h2 id="session-title" tabindex="-1">{{ $title }}</h2>
      <div class="session-meta-row">
        <span class="session-meta-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
        </span>
        <span>{{ \App\Support\SessionPresentation::dateTimeLabel($session) ?: 'Horario por confirmar' }}</span>
      </div>
    </div>

    <div class="session-status-stack" aria-label="Estado de la sesión">
      <span class="session-state-label is-{{ $lifecycle }}">{{ \App\Support\SessionPresentation::stateLabel($session) }}</span>
    </div>
  </header>

  <x-session-meeting :session="$session" :privileged="true" />

  @if($lifecycle !== 'cancelled')
    @if($tasks !== [])
      <section class="session-task-summary {{ $lifecycle === 'upcoming' ? 'is-preparation' : 'is-attention' }}"
               aria-labelledby="sessionTaskTitle">
        <div class="session-task-summary__intro">
          <span id="sessionTaskTitle">{{ $lifecycle === 'upcoming' ? 'Preparación de la sesión' : 'Tareas de esta sesión' }}</span>
          <small>{{ $lifecycle === 'upcoming' ? 'Déjala lista antes de la fecha programada.' : count($tasks).' '.(count($tasks) === 1 ? 'tarea requiere' : 'tareas requieren').' atención.' }}</small>
        </div>
        <div class="session-task-list">
          @foreach($tasks as $task)
            <button type="button" data-open-session-panel="{{ $task['panel'] }}">
              <span aria-hidden="true"></span>
              <strong>{{ $task['label'] }}</strong>
              <small>Revisar</small>
            </button>
          @endforeach
        </div>
      </section>
    @else
      <div class="session-ready-state" role="status">
        <span aria-hidden="true">&#10003;</span>
        <div><strong>Sesión preparada</strong><small>Video, materiales y evaluación están configurados.</small></div>
      </div>
    @endif
  @endif

  <div class="session-tabs-card">
    <div class="session-tabs" role="tablist" aria-label="Gestión de la sesión">
      @foreach($tabs as $panelKey => [$panelLabel, $panelBadge])
        <button type="button"
                id="tab-button-{{ $panelKey }}"
                data-tab="{{ $panelKey }}"
                class="tab-link {{ $activeTab === $panelKey ? 'is-active' : '' }}"
                role="tab"
                tabindex="{{ $activeTab === $panelKey ? '0' : '-1' }}"
                aria-selected="{{ $activeTab === $panelKey ? 'true' : 'false' }}"
                aria-controls="tab-{{ $panelKey }}">
          {{ $panelLabel }}
          @if($panelBadge !== null)
            <span class="{{ is_numeric($panelBadge) ? '' : 'tab-state' }}"
                  data-tab-count="{{ $panelKey }}"
                  @if(is_numeric($panelBadge) && (int) $panelBadge === 0) hidden @endif>{{ $panelBadge }}</span>
          @endif
        </button>
      @endforeach
    </div>

    <div class="tab-content {{ $activeTab !== 'video' ? 'hidden' : '' }}"
         id="tab-video" role="tabpanel" aria-labelledby="tab-button-video"
         data-panel="video" data-panel-loaded="true">
      @include('backoffice.courses.partials.session-video')
    </div>

    @foreach(['materials', 'evaluations', 'announcements', 'attendance'] as $lazyPanel)
      <div class="tab-content {{ $activeTab !== $lazyPanel ? 'hidden' : '' }}"
           id="tab-{{ $lazyPanel }}" role="tabpanel" aria-labelledby="tab-button-{{ $lazyPanel }}"
           data-panel="{{ $lazyPanel }}" data-panel-loaded="false">
        <div class="course-panel-loading" role="status">
          <span></span>
          Cargando sección...
        </div>
      </div>
    @endforeach
  </div>

  <nav class="session-sequence-nav" aria-label="Navegación entre sesiones">
    @if($previous)
      <a href="{{ route('backoffice.courses.show', [$course->id, $previous->id]) }}"
         data-session-nav data-session-id="{{ $previous->id }}" rel="prev">
        <span aria-hidden="true">&lsaquo;</span> Sesión {{ $previous->number ?? $position - 1 }}
      </a>
    @else
      <span aria-disabled="true"><span aria-hidden="true">&lsaquo;</span> Anterior</span>
    @endif

    <strong>{{ $position }} de {{ $items->count() }}</strong>

    @if($next)
      <a href="{{ route('backoffice.courses.show', [$course->id, $next->id]) }}"
         data-session-nav data-session-id="{{ $next->id }}" rel="next">
        Sesión {{ $next->number ?? $position + 1 }} <span aria-hidden="true">&rsaquo;</span>
      </a>
    @else
      <span aria-disabled="true">Siguiente <span aria-hidden="true">&rsaquo;</span></span>
    @endif
  </nav>
</div>
@endif
