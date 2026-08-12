{{-- Vista compacta de cursos del profesor / administrador --}}
@extends('layouts.main')

@section('title','Aula Virtual - Gestion de cursos')
@section('body-class','bg-gray-50 min-h-screen text-gray-800')

@section('content')
@php
  $activeTab = $activeTab ?? 'activos';
  $isAdmin = session(\App\Support\AuthSessionKeys::USER_ROLE) === 'admin';
  $tabConfig = [
    'activos' => [
      'label' => 'Activos',
      'title' => 'Cursos activos',
      'empty' => 'No hay cursos activos asignados.',
    ],
    'programados' => [
      'label' => 'Programados',
      'title' => 'Cursos programados',
      'empty' => 'No hay cursos programados asignados.',
    ],
    'finalizados' => [
      'label' => 'Finalizados',
      'title' => 'Cursos finalizados',
      'empty' => 'No hay cursos finalizados asignados.',
    ],
  ];

  $activeGroup = $groups['activos'] ?? collect();
  $programmedGroup = $groups['programados'] ?? collect();
  $activeCourses = collect(method_exists($activeGroup, 'items') ? $activeGroup->items() : $activeGroup);
  $programmedCourses = collect(method_exists($programmedGroup, 'items') ? $programmedGroup->items() : $programmedGroup);
  $pendingMaterials = $activeCourses->sum(function ($course) {
      return (int) ($course['sesiones_hoy_sin_material'] ?? 0)
          + (int) ($course['sesiones_pasadas_sin_material'] ?? 0);
  });
  $missingEvaluations = $activeCourses->filter(fn ($course) => (int) ($course['total_evaluaciones'] ?? 0) === 0)->count();
@endphp

<div class="page-header">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <span class="inline-flex rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
        {{ $isAdmin ? 'Panel administrativo' : 'Panel docente' }}
      </span>
      <h1 class="mt-2">{{ $isAdmin ? 'Gestion de cursos' : 'Mis Cursos' }}</h1>
      <p class="text-sm text-slate-500">
        {{ $isAdmin ? 'Supervisa responsables, avances y pendientes operativos de todos los cursos.' : 'Gestiona avances, materiales y evaluaciones de tus cursos asignados.' }}
      </p>
    </div>

    <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:min-w-[620px]">
      <div class="rounded-md border border-slate-200 bg-white px-3 py-2">
        <dt class="text-xs font-medium text-slate-500">Cursos activos</dt>
        <dd class="mt-1 text-xl font-bold text-slate-950">{{ $counts['activos'] ?? 0 }}</dd>
      </div>
      <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
        <dt class="text-xs font-medium text-amber-700">Materiales pendientes</dt>
        <dd class="mt-1 text-xl font-bold text-amber-900">{{ $pendingMaterials }}</dd>
      </div>
      <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2">
        <dt class="text-xs font-medium text-rose-700">Sin plan evaluable</dt>
        <dd class="mt-1 text-xl font-bold text-rose-900">{{ $missingEvaluations }}</dd>
      </div>
      <div class="rounded-md border border-slate-200 bg-white px-3 py-2">
        <dt class="text-xs font-medium text-slate-500">Proximos</dt>
        <dd class="mt-1 text-xl font-bold text-slate-950">{{ $programmedCourses->count() }}</dd>
      </div>
    </dl>
  </div>
</div>

<div class="page-shell">
  @if(!empty($error))
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ $error }}
    </div>
  @endif

  @if($isAdmin)
    <section class="qualification-search-toolbar" aria-label="Busqueda de cursos">
      <form id="courseSearchForm" method="GET" action="{{ route('backoffice.courses') }}">
        <input type="hidden" id="courseSearchTab" name="tab" value="{{ $activeTab }}">
        <label for="courseSearch" class="sr-only">Buscar por curso, edicion o responsable</label>
        <div class="qualification-search-box">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <input
            id="courseSearch"
            name="search"
            type="search"
            value="{{ $search ?? '' }}"
            placeholder="Buscar por curso, edicion o responsable"
            autocomplete="off"
          >
        </div>
      </form>
      <p
        id="courseSearchHelp"
        class="qualification-search-help"
        data-count="{{ $counts[$activeTab] ?? 0 }}"
      >
        Mostrando {{ $counts[$activeTab] ?? 0 }} {{ ($counts[$activeTab] ?? 0) === 1 ? 'curso disponible' : 'cursos disponibles' }}
      </p>
    </section>
  @endif

  <div class="mb-5 rounded-md border border-slate-200 bg-white p-1" role="tablist" aria-label="Estados de cursos">
    <div class="grid grid-cols-3 gap-1">
      @foreach($tabConfig as $tabKey => $tab)
        @php $isActive = $activeTab === $tabKey; @endphp
        <button
          type="button"
          class="tab-card rounded px-3 py-2 text-center text-sm font-semibold transition-colors {{ $isActive ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50' }}"
          data-tab="{{ $tabKey }}"
          data-count="{{ $counts[$tabKey] ?? 0 }}"
          role="tab"
          aria-selected="{{ $isActive ? 'true' : 'false' }}"
          aria-controls="panel-{{ $tabKey }}"
          id="tab-{{ $tabKey }}"
        >
          {{ $tab['label'] }}
          <span class="{{ $isActive ? 'text-indigo-100' : 'text-slate-400' }}">
            {{ $counts[$tabKey] ?? 0 }}
          </span>
        </button>
      @endforeach
    </div>
  </div>

  <section>
    @foreach($tabConfig as $tabKey => $tab)
      @php
        $items = $groups[$tabKey] ?? collect();
        $visibleItems = collect(method_exists($items, 'items') ? $items->items() : $items);
      @endphp

      <div
        id="panel-{{ $tabKey }}"
        class="tab-content {{ $activeTab !== $tabKey ? 'hidden' : '' }}"
        data-content="{{ $tabKey }}"
        role="tabpanel"
        aria-labelledby="tab-{{ $tabKey }}"
      >
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold text-slate-950">{{ $tab['title'] }}</h2>
          <span
            class="text-sm text-slate-500"
            data-course-panel-count
            data-total="{{ $visibleItems->count() }}"
          >
            {{ $counts[$tabKey] ?? 0 }} curso{{ ($counts[$tabKey] ?? 0) === 1 ? '' : 's' }}
          </span>
        </div>

        @if($visibleItems->isEmpty())
          <div class="rounded-md border border-dashed border-slate-300 bg-white px-5 py-8 text-center">
            <h3 class="text-sm font-semibold text-slate-900">{{ $tab['empty'] }}</h3>
            <p class="mt-1 text-sm text-slate-500">Cuando tengas cursos en este estado apareceran aqui.</p>
          </div>
        @else
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3" data-course-grid>
            @foreach($visibleItems as $course)
              @php
                $totalSessions = (int) ($course['total_sessions'] ?? 0);
                $sessionsDone = (int) ($course['sessions_done'] ?? 0);
                $progress = (float) ($course['progress_percent'] ?? 0);
                $todayMissing = (int) ($course['sesiones_hoy_sin_material'] ?? 0);
                $pastMissing = (int) ($course['sesiones_pasadas_sin_material'] ?? 0);
                $evaluationCount = (int) ($course['total_evaluaciones'] ?? 0);
                $needsAttention = $todayMissing > 0 || $pastMissing > 0 || $evaluationCount === 0;
                $statusLabel = $tabKey === 'activos' ? 'En curso' : ($tabKey === 'programados' ? 'Programado' : 'Finalizado');
                $schedule = trim((string) ($course['schedule'] ?? ''));
                $scheduleLabel = trim((string) ($course['schedule_label'] ?? $schedule));
                $teacher = trim((string) ($course['teacher'] ?? ''));
                $studentsCount = (int) ($course['students_count'] ?? 0);
              @endphp

              <article
                class="flex min-h-[272px] flex-col rounded-md border border-slate-200 bg-white js-live-course-card"
                data-course-name="{{ strtolower(($course['title'] ?? '') . ' ' . ($course['edition'] ?? '') . ' ' . ($course['teacher'] ?? '')) }}"
              >
                <div class="flex flex-1 flex-col p-4">
                  <div class="mb-3 flex flex-wrap items-center gap-2">
                    @if(!empty($course['edition']))
                      <span class="rounded bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700">
                        Edicion {{ $course['edition'] }}
                      </span>
                    @endif
                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                      {{ $statusLabel }}
                    </span>
                  </div>

                  <h3 class="line-clamp-2 text-base font-semibold leading-snug text-slate-950">
                    {{ $course['title'] }}
                  </h3>

                  @if($isAdmin && $teacher !== '')
                    <p class="mt-2 truncate text-xs font-semibold text-slate-500" title="{{ $teacher }}">
                      Responsable: {{ $teacher }}
                    </p>
                  @endif

                  @if($scheduleLabel !== '')
                    <p class="mt-2 truncate text-xs text-slate-500" title="{{ $schedule }}">
                      {{ $scheduleLabel }}
                    </p>
                  @endif

                  <div class="mt-4">
                    <div class="mb-1.5 flex items-center justify-between text-sm">
                      <span class="font-medium text-slate-600">Avance</span>
                      <span class="font-semibold text-indigo-700">{{ number_format($progress, 1) }}%</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                      <div class="h-full rounded-full bg-indigo-600" style="width: {{ min(100, max(0, $progress)) }}%"></div>
                    </div>
                    <div class="mt-1.5 flex items-center justify-between text-xs text-slate-500">
                      <span>{{ $sessionsDone }} de {{ $totalSessions }}</span>
                      <span>{{ $totalSessions }} sesiones</span>
                    </div>
                  </div>

                  <div class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <div class="rounded bg-slate-50 px-2.5 py-2">
                      <div class="text-xs text-slate-500">{{ $isAdmin ? 'Alumnos' : 'Realizadas' }}</div>
                      <div class="font-semibold text-slate-900">{{ $isAdmin ? $studentsCount : $sessionsDone }}</div>
                    </div>
                    <div class="rounded bg-slate-50 px-2.5 py-2">
                      <div class="text-xs text-slate-500">{{ $isAdmin ? 'Sesiones' : 'Evaluaciones' }}</div>
                      <div class="font-semibold text-slate-900">{{ $isAdmin ? ($sessionsDone . '/' . $totalSessions) : $evaluationCount }}</div>
                    </div>
                  </div>

                  @if($isAdmin)
                    <div class="mt-2 rounded bg-slate-50 px-2.5 py-2 text-sm">
                      <div class="text-xs text-slate-500">Evaluaciones configuradas</div>
                      <div class="font-semibold text-slate-900">{{ $evaluationCount }}</div>
                    </div>
                  @endif

                  @if($needsAttention)
                    <div class="mt-4">
                      <div class="mb-1 text-xs font-semibold uppercase text-slate-500">Acciones pendientes</div>
                      <div class="flex flex-wrap gap-1.5">
                        @if($todayMissing > 0)
                          <span class="rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">
                            Material de hoy
                          </span>
                        @endif
                        @if($pastMissing > 0)
                          <span class="rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700">
                            {{ $pastMissing }} sin material
                          </span>
                        @endif
                        @if($evaluationCount === 0)
                          <span class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-medium text-slate-600">
                            Sin plan evaluable
                          </span>
                        @endif
                      </div>
                    </div>
                  @else
                    <div class="mt-4 rounded border border-emerald-200 bg-emerald-50 px-2.5 py-2 text-xs font-medium text-emerald-700">
                      Sin pendientes criticos.
                    </div>
                  @endif

                  <div class="mt-auto pt-4">
                    <a
                      href="{{ route('backoffice.courses.show', $course['id']) }}"
                      class="inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-3 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                    >
                      {{ $needsAttention ? 'Resolver pendientes' : 'Gestionar curso' }}
                      <span class="ml-2" aria-hidden="true">&rsaquo;</span>
                    </a>
                  </div>
                </div>
              </article>
            @endforeach
          </div>

          <div class="qualification-empty mt-6" data-course-no-results hidden>
            No encontramos cursos con ese criterio de busqueda en esta seccion.
          </div>

          <div class="smart-pagination-wrap">
            {{ $items->appends([
                'search' => $search ?? null,
                'tab' => $tabKey,
              ])->links() }}
          </div>
        @endif
      </div>
    @endforeach
  </section>
</div>

@endsection

@push('scripts')
@vite('resources/js/backoffice-courses-index.js')
@endpush
