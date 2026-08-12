@if(empty($session?->id))
  <div class="course-empty-state">
    <div class="course-empty-icon" aria-hidden="true">!</div>
    <div><div class="course-empty-title">No hay una sesión disponible</div><p class="course-empty-text">Aún no se publicaron sesiones para este curso.</p></div>
  </div>
@else
@php
  $items = collect($sessions ?? [])->values();
  $index = $items->search(fn ($item) => (int) $item->id === (int) $session->id);
  $position = $index === false ? 1 : $index + 1;
  $previous = $index !== false && $index > 0 ? $items[$index - 1] : null;
  $next = $index !== false && $index < $items->count() - 1 ? $items[$index + 1] : null;
  $number = $session->number ?? $position;
  $lifecycle = \App\Support\SessionPresentation::lifecycle($session);
  $materialsCount = collect($session->materials ?? [])->count();
  $evaluationsCount = collect($session->evaluaciones ?? [])->count();
  $hasEvaluations = $evaluationsCount > 0;
  $surveys = collect($session->surveys ?? []);
  $surveysCount = $surveys->count();
  $announcementsCount = (int) ($session->announcements_count ?? (!empty($anuncioSesionNoLeido['existen']) ? 1 : 0));
  $requestedTab = request()->query('tab', 'video');
  $aliases = ['material' => 'materials', 'evaluacion' => 'evaluations', 'encuestas' => 'surveys', 'anuncios' => 'announcements', 'asistencia' => 'attendance'];
  $requestedTab = $aliases[$requestedTab] ?? $requestedTab;
  $tabs = [
      'video' => ['Video', null],
      'materials' => ['Materiales', $materialsCount],
      'surveys' => ['Encuestas', $surveysCount],
      'announcements' => ['Anuncios', $announcementsCount],
      'attendance' => ['Mi asistencia', null],
  ];
  if ($hasEvaluations) {
      $tabs = array_slice($tabs, 0, 2, true)
          + ['evaluations' => ['Evaluaciones', $evaluationsCount]]
          + array_slice($tabs, 2, null, true);
  }
  $activeTab = array_key_exists($requestedTab, $tabs) ? $requestedTab : 'video';
  $evaluations = collect($session->evaluaciones ?? []);
  $evaluationState = function ($evaluation): array {
      $item = is_array($evaluation) ? $evaluation : (array) $evaluation;
      $status = strtolower(trim((string) ($item['rendicion_estado'] ?? $item['entrega_estado'] ?? $item['estado'] ?? $item['status'] ?? $item['status_key'] ?? '')));
      $hasDelivery = !empty($item['has_delivery']) || (int) ($item['delivery_id'] ?? 0) > 0 || !empty($item['finalizada']);
      $finishedStatuses = ['finalizado', 'finalizada', 'entregado', 'entregada', 'calificado', 'calificada', 'corregido', 'corregida', 'corrected', 'graded', 'evaluado', 'evaluada', 'evaluated', 'reviewing'];
      $startedStatuses = ['en_progreso', 'borrador', 'draft', 'started'];
      $finished = in_array($status, $finishedStatuses, true) || $hasDelivery || is_numeric($item['score'] ?? $item['puntaje_total'] ?? $item['puntaje_obtenido'] ?? $item['nota_final'] ?? $item['nota'] ?? null);
      $started = in_array($status, $startedStatuses, true) || $hasDelivery || (int) ($item['rendicion_id'] ?? 0) > 0;

      return compact('finished', 'started');
  };
  $startedEvaluation = $evaluations->first(fn ($evaluation) => !$evaluationState($evaluation)['finished'] && $evaluationState($evaluation)['started']);
  $pendingEvaluation = $evaluations->first(fn ($evaluation) => !$evaluationState($evaluation)['finished']);
  $pendingSurvey = $surveys->first(fn ($survey) => ($survey->status ?? null) === 'pending');
  $meeting = is_array($session->meeting ?? null) ? (object) $session->meeting : ($session->meeting ?? (object) []);
  $meetingCanJoin = $lifecycle === 'in_progress' && (bool) ($meeting->can_join ?? false) && !empty($meeting->join_url);
  $videoReady = ($session->video_status ?? null) === 'ready' && !empty($session->video_drive_file_id);
  $nextAction = $meetingCanJoin ? ['zoom', 'Ingresar a la clase', 'La sala de Zoom está disponible ahora.']
      : ($startedEvaluation ? ['evaluations', 'Continuar evaluación', 'Retoma la actividad desde tu último avance.']
      : ($pendingEvaluation ? ['evaluations', 'Realizar evaluación', 'Completa la actividad pendiente de esta sesión.']
      : ($pendingSurvey ? ['surveys', 'Responder encuesta', 'Tu opinión nos ayuda a mejorar el curso.']
      : ($videoReady ? ['video', 'Ver grabación', 'Repasa la clase cuando lo necesites.']
      : ($materialsCount > 0 ? ['materials', 'Revisar materiales', 'Consulta los recursos compartidos por tu docente.'] : null)))));
  $isSessionUpToDate = !$nextAction && !in_array($lifecycle, ['upcoming', 'cancelled'], true);
@endphp

<div class="session-workspace student-session-workspace" data-session-workspace data-session-id="{{ $session->id }}">
  <header class="session-overview">
    <div>
      <div class="session-kicker">Sesión {{ $position }} de {{ $items->count() }}</div>
      <h2 id="session-title" tabindex="-1">Sesión {{ $number }}</h2>
      <div class="session-meta-row">
        <span class="session-meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M8 2v4M16 2v4M3 10h18"/></svg></span>
        <span>{{ \App\Support\SessionPresentation::dateTimeLabel($session) ?: 'Horario por confirmar' }}</span>
      </div>
    </div>
    <div class="session-status-stack" aria-label="Estado de la sesión">
      <span class="session-state-label is-{{ $lifecycle }}">{{ \App\Support\SessionPresentation::stateLabel($session) }}</span>
    </div>
  </header>

  <x-session-meeting :session="$session" :privileged="false" />

  @if($nextAction)
    <section class="student-next-action" aria-labelledby="studentNextActionTitle">
      <div><span>TU SIGUIENTE PASO</span><strong id="studentNextActionTitle">{{ $nextAction[1] }}</strong><small>{{ $nextAction[2] }}</small></div>
      @if($nextAction[0] === 'zoom')
        <button type="submit" form="sessionZoomJoinForm-{{ $session->id }}">{{ $nextAction[1] }}</button>
      @else
        <button type="button" data-open-session-panel="{{ $nextAction[0] }}">{{ $nextAction[1] }}</button>
      @endif
    </section>
  @elseif($lifecycle === 'upcoming')
    <div class="session-ready-state is-upcoming"><span aria-hidden="true">i</span><div><strong>Próxima sesión</strong><small>Podrás consultar el contenido publicado antes de la clase.</small></div></div>
  @elseif($lifecycle === 'cancelled')
    <div class="session-ready-state is-cancelled"><span aria-hidden="true">i</span><div><strong>Sesión cancelada</strong><small>No tienes actividades pendientes en esta sesión.</small></div></div>
  @elseif($isSessionUpToDate)
    <div class="session-ready-state"><span aria-hidden="true">✓</span><div><strong>Sesión al día</strong><small>{{ $next ? 'Puedes continuar con la sesión '.($next->number ?? $position + 1).'.' : 'Has revisado todas las actividades disponibles.' }}</small></div></div>
  @endif

  <div class="session-tabs-card">
    <div class="session-tabs" role="tablist" aria-label="Contenido de la sesión">
      @foreach($tabs as $panelKey => [$panelLabel, $panelBadge])
        <button type="button" id="tab-button-{{ $panelKey }}" data-tab="{{ $panelKey }}"
                class="tab-link {{ $activeTab === $panelKey ? 'is-active' : '' }}" role="tab"
                tabindex="{{ $activeTab === $panelKey ? '0' : '-1' }}"
                aria-selected="{{ $activeTab === $panelKey ? 'true' : 'false' }}" aria-controls="tab-{{ $panelKey }}">
          {{ $panelLabel }}
          @if($panelBadge !== null)<span data-tab-count="{{ $panelKey }}" @if((int)$panelBadge === 0) hidden @endif>{{ $panelBadge }}</span>@endif
        </button>
      @endforeach
    </div>

    <div class="tab-content {{ $activeTab !== 'video' ? 'hidden' : '' }}" id="tab-video" role="tabpanel"
         aria-labelledby="tab-button-video" data-panel="video" data-panel-loaded="true">
      @include('mis-cursos.partials.panels.video')
    </div>
    @foreach(collect(['materials', 'evaluations', 'surveys', 'announcements', 'attendance'])->filter(fn ($lazyPanel) => array_key_exists($lazyPanel, $tabs)) as $lazyPanel)
      <div class="tab-content {{ $activeTab !== $lazyPanel ? 'hidden' : '' }}" id="tab-{{ $lazyPanel }}" role="tabpanel"
           aria-labelledby="tab-button-{{ $lazyPanel }}" data-panel="{{ $lazyPanel }}" data-panel-loaded="false">
        <div class="course-panel-loading" role="status"><span></span>Cargando sección...</div>
      </div>
    @endforeach
  </div>

  <nav class="session-sequence-nav" aria-label="Navegación entre sesiones">
    @if($previous)
      <a href="{{ route('mis-cursos.show', [$course->id, $previous->id]) }}{{ $activeTab !== 'video' ? '?tab='.$activeTab : '' }}" data-session-nav data-session-id="{{ $previous->id }}" rel="prev"><span aria-hidden="true">&lsaquo;</span><span class="session-nav-label">Anterior · Sesión {{ $previous->number ?? $position - 1 }}</span></a>
    @else<span aria-disabled="true"><span aria-hidden="true">&lsaquo;</span><span class="session-nav-label">Anterior</span></span>@endif
    <strong>{{ $position }} de {{ $items->count() }}</strong>
    @if($next)
      <a href="{{ route('mis-cursos.show', [$course->id, $next->id]) }}{{ $activeTab !== 'video' ? '?tab='.$activeTab : '' }}" data-session-nav data-session-id="{{ $next->id }}" rel="next"><span class="session-nav-label">Siguiente · Sesión {{ $next->number ?? $position + 1 }}</span><span aria-hidden="true">&rsaquo;</span></a>
    @else<span aria-disabled="true"><span class="session-nav-label">Siguiente</span><span aria-hidden="true">&rsaquo;</span></span>@endif
  </nav>
</div>
@endif
