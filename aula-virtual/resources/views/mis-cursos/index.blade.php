{{-- Vista de listado de cursos del alumno --}}
@extends('layouts.main')

@section('title','Aula Virtual - Mis cursos')
@section('body-class','bg-gray-50 min-h-screen text-gray-800')

@section('content')
  @php
    $activeTab = $activeTab ?? request('tab', 'activos');
    $activeTab = in_array($activeTab, ['activos', 'completados', 'sugeridos'], true) ? $activeTab : 'activos';
    $search = $search ?? request('search', '');

    $tabConfig = [
      'activos' => [
        'label' => 'En progreso',
        'title' => 'Cursos en progreso',
        'items' => $groups['activos'] ?? collect(),
        'empty' => 'No tienes cursos en progreso por ahora.',
        'empty_detail' => 'Cuando inicies un curso, aparecera aqui con tu avance y siguiente paso.',
      ],
      'completados' => [
        'label' => 'Completados',
        'title' => 'Cursos completados',
        'items' => $groups['completados'] ?? collect(),
        'empty' => 'Aun no tienes cursos completados.',
        'empty_detail' => 'Los cursos terminados quedaran disponibles para consulta.',
      ],
      'sugeridos' => [
        'label' => 'Sugeridos',
        'title' => 'Cursos sugeridos',
        'items' => $groups['sugeridos'] ?? collect(),
        'empty' => 'Aun no tenemos sugerencias para ti.',
        'empty_detail' => 'Cuando haya nuevos cursos relacionados con tu avance apareceran aqui.',
      ],
    ];

    $totalActive = (int) ($counts['activos'] ?? 0);
    $totalCompleted = (int) ($counts['completados'] ?? 0);
    $totalSuggested = (int) ($counts['sugeridos'] ?? 0);
    $totalPending = (int) ($counts['pendientes'] ?? 0);
    $activeItems = $tabConfig[$activeTab]['items'] ?? collect();
  @endphp

  <div class="student-courses-page">
    <div class="page-header student-courses-header">
      <div>
        <span class="student-courses-eyebrow">Aula virtual</span>
        <h1>Mis Cursos</h1>
        <p>Continua tus clases, revisa tu avance y accede rapidamente al contenido.</p>
      </div>

      <div class="student-courses-summary" aria-label="Resumen de cursos">
        <article>
          <span>En progreso</span>
          <strong>{{ $totalActive }}</strong>
        </article>
        <article>
          <span>Completados</span>
          <strong>{{ $totalCompleted }}</strong>
        </article>
        <article>
          <span>Pendientes</span>
          <strong>{{ $totalPending }}</strong>
        </article>
        <article>
          <span>Sugeridos</span>
          <strong>{{ $totalSuggested }}</strong>
        </article>
      </div>
    </div>

    <div class="page-shell student-courses-shell">
      @if(!empty($error))
        <div class="student-courses-alert">
          {{ $error }}
        </div>
      @endif

      <form class="student-courses-search" method="GET" action="{{ route('mis-cursos.index') }}" data-student-course-search>
        <input type="hidden" name="tab" value="{{ $activeTab }}" data-student-course-tab-input>
        <label class="student-courses-search__field">
          <span class="sr-only">Buscar curso</span>
          <span class="student-courses-search__icon" aria-hidden="true"></span>
          <input
            type="search"
            name="search"
            value="{{ $search }}"
            placeholder="Buscar por curso, edicion o docente"
            autocomplete="off"
          >
        </label>
        @if(trim((string) $search) !== '')
          <a class="student-courses-search__clear" href="{{ route('mis-cursos.index', ['tab' => $activeTab]) }}">Limpiar</a>
        @endif
        <p>Mostrando <strong data-student-course-count>{{ $activeItems->count() }}</strong> cursos disponibles</p>
      </form>

      <section class="student-courses-tabs" role="tablist" aria-label="Filtros de cursos">
        @foreach($tabConfig as $tabKey => $tabData)
          @php
            $count = (int) ($counts[$tabKey] ?? 0);
            $isActive = $tabKey === $activeTab;
          @endphp
          <button
            type="button"
            class="tab-card student-course-tab {{ $isActive ? 'is-active tab-active' : '' }}"
            data-tab="{{ $tabKey }}"
            role="tab"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            aria-controls="student-courses-panel-{{ $tabKey }}"
          >
            <span>{{ $tabData['label'] }}</span>
            <strong>{{ $count }}</strong>
          </button>
        @endforeach
      </section>

      <section>
        @foreach($tabConfig as $tabKey => $tabData)
          <div
            id="student-courses-panel-{{ $tabKey }}"
            class="tab-content student-courses-panel {{ $tabKey !== $activeTab ? 'hidden' : '' }}"
            data-content="{{ $tabKey }}"
            role="tabpanel"
          >
            <div class="student-courses-section-head">
              <h2>{{ $tabData['title'] }}</h2>
              <span>{{ $tabData['items']->count() }} {{ $tabData['items']->count() === 1 ? 'curso' : 'cursos' }}</span>
            </div>

            @if($tabData['items']->isEmpty())
              <div class="student-courses-empty">
                <strong>{{ $tabData['empty'] }}</strong>
                <span>{{ $tabData['empty_detail'] }}</span>
              </div>
            @else
              <div class="student-courses-grid">
                @foreach($tabData['items'] as $course)
                  @php
                    $totalSessions = (int) ($course['total_sessions'] ?? 0);
                    $doneSessions = (int) ($course['sessions_done'] ?? 0);
                    $progressPercent = (float) ($course['progress_percent'] ?? 0);
                    $edition = trim((string) ($course['edition'] ?? ''));
                    $isSuggestion = (bool) ($course['is_suggestion'] ?? false);
                    $isCompleted = $tabKey === 'completados' || $progressPercent >= 100;
                    $stateLabel = $isSuggestion ? 'Programado' : ($isCompleted ? 'Completado' : 'En progreso');
                    $stateClass = $isSuggestion ? 'is-suggested' : ($isCompleted ? 'is-completed' : 'is-active');
                    $schedule = $course['schedule_label'] ?? $course['schedule'] ?? 'Horario por confirmar';
                    $ctaLabel = $course['cta_label'] ?? ($isCompleted ? 'Ver curso' : 'Continuar curso');
                    $courseTitle = $course['title'] ?? 'Curso';
                    $suggestionHref = 'mailto:soporte@sdc.pe?subject=' . rawurlencode('Informacion sobre ' . $courseTitle);
                    $courseHref = $isSuggestion ? $suggestionHref : route('mis-cursos.show', ['course' => $course['id']]);
                  @endphp

                  <article class="student-course-card">
                    <div class="student-course-body">
                      <div class="student-course-tags">
                        @if($edition !== '')
                          <span class="student-course-chip">Edicion {{ $edition }}</span>
                        @endif
                        <span class="student-course-state {{ $stateClass }}">{{ $isSuggestion ? 'Sugerido para ti' : $stateLabel }}</span>
                      </div>

                      <h3>{{ $courseTitle }}</h3>

                      @if(!empty($course['teacher']) && !$isSuggestion)
                        <p class="student-course-teacher">Docente: {{ $course['teacher'] }}</p>
                      @elseif(!empty($course['teacher']))
                        <p class="student-course-teacher">Responsable: {{ $course['teacher'] }}</p>
                      @endif

                      <p class="student-course-schedule" title="{{ $course['schedule'] ?? $schedule }}">{{ $schedule }}</p>

                      @if($isSuggestion)
                        <div class="student-course-next">
                          <span>{{ $course['next_step_label'] ?? 'Curso recomendado' }}</span>
                          <strong>{{ $course['suggestion_reason'] ?? 'Tambien te puede interesar' }}</strong>
                        </div>
                      @else
                        <div class="student-course-progress">
                          <div class="student-course-progress-row">
                            <span>Avance</span>
                            <strong>{{ number_format($progressPercent, 1) }}%</strong>
                          </div>
                          <div class="student-course-progress-track" aria-label="Avance del curso">
                            <span style="width: {{ min(100, max(0, $progressPercent)) }}%"></span>
                          </div>
                          <div class="student-course-progress-detail">
                            <span>{{ $doneSessions }} de {{ $totalSessions }} sesiones</span>
                            <span>{{ $totalSessions }} sesiones</span>
                          </div>
                        </div>

                        <div class="student-course-next">
                          <span>Siguiente paso</span>
                          <strong>{{ $course['next_step_label'] ?? 'Continuar curso' }}</strong>
                          <small>{{ $course['next_step_description'] ?? 'Retoma el contenido disponible.' }}</small>
                        </div>
                      @endif

                      <a href="{{ $courseHref }}" class="student-course-action {{ $isSuggestion ? 'is-secondary' : '' }}">
                        {{ $ctaLabel }}
                        <span aria-hidden="true">&rsaquo;</span>
                      </a>
                    </div>
                  </article>
                @endforeach
              </div>
            @endif
          </div>
        @endforeach
      </section>
    </div>
  </div>
@endsection

@push('scripts')
  @vite('resources/js/mis-cursos-index.js')
@endpush
