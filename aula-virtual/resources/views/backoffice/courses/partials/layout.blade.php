@extends('layouts.main')

@section('content')
@php
  $role = strtolower((string) session(\App\Support\AuthSessionKeys::USER_ROLE, ''));
  $isAdmin = in_array($role, ['admin', 'administrador'], true);
  $coursesLabel = \App\Support\Navigation::coursesLabel($role);
  $sessionItems = collect($sessions ?? [])->values();
  $selectedIndex = !empty($session?->id)
      ? $sessionItems->search(fn ($item) => (int) $item->id === (int) $session->id)
      : false;
  $sessionPosition = $selectedIndex === false ? 0 : $selectedIndex + 1;
  $courseState = trim((string) ($course->state ?? ''));
@endphp

<div class="course-detail-page"
     data-course-workspace-root
     data-course-id="{{ $course->id }}"
     data-session-id="{{ $session->id ?? '' }}"
     data-show-url-template="{{ route('backoffice.courses.show', [$course->id, '__SESSION__']) }}"
     data-workspace-url-template="{{ route('backoffice.courses.sessions.workspace', [$course->id, '__SESSION__']) }}"
     data-panel-url-template="{{ route('backoffice.courses.sessions.panels.show', [$course->id, '__SESSION__', '__PANEL__']) }}"
     @hasSection('course-side')
     data-community-url="{{ route('backoffice.courses.community.show', $course->id) }}"
     @endif>
  <nav class="course-breadcrumb" aria-label="Migas de pan">
    <a href="{{ route('backoffice.courses') }}">{{ $coursesLabel }}</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ $course->title ?? 'Curso' }}</span>
  </nav>

  <header class="course-context-header">
    <div class="course-context-main">
      <div class="course-context-chips">
        @if(!empty($course->edition))
          <span>Edición {{ $course->edition }}</span>
        @endif
        @if($courseState !== '')
          <span>{{ ucfirst($courseState) }}</span>
        @endif
      </div>
      <h1>{{ $course->title ?? 'Curso' }}</h1>
      @if($isAdmin && !empty($course->teacher_name))
        <p>Responsable: {{ $course->teacher_name }}</p>
      @endif
    </div>

    <button type="button"
            class="course-mobile-sessions-button"
            data-open-session-drawer
            aria-controls="courseSessionSidebar"
            aria-expanded="false">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M5 12h14M5 18h14" /></svg>
      <span>Sesiones</span>
      @if($sessionPosition > 0)
        <strong data-mobile-session-position>{{ $sessionPosition }}/{{ $sessionItems->count() }}</strong>
      @endif
    </button>
  </header>

  <div class="course-session-backdrop" data-session-drawer-backdrop hidden></div>

  <div class="course-detail-shell">
    <aside id="courseSessionSidebar" class="course-detail-sidebar" aria-label="Sesiones del curso">
      @include('backoffice.courses.partials.sidebar', [
        'course' => $course,
        'sessions' => $sessionItems,
        'session' => $session ?? null,
      ])
    </aside>

    <section id="courseWorkspace" class="course-workspace" aria-live="polite" aria-busy="false">
      @yield('course-content')
    </section>
  </div>

  @hasSection('course-side')
    <button type="button"
            class="course-community-launcher"
            data-community-toggle
            aria-controls="courseCommunityDrawer"
            aria-expanded="false">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8h10M7 12h6m-7.5 6.5 2.2-2.2H17a4 4 0 0 0 4-4V8a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v4.3a4 4 0 0 0 2.5 3.7v2.5Z" /></svg>
      <span>Comunidad</span>
      <strong data-community-count>0</strong>
    </button>

    <div class="course-community-backdrop" data-community-backdrop hidden></div>
    <aside id="courseCommunityDrawer"
           class="course-community-drawer"
           aria-label="Comunidad del curso"
           aria-hidden="true"
           inert>
      <div class="course-community-drawer__header">
        <div>
          <strong>Comunidad del curso</strong>
          <span data-community-summary>Comentarios y participantes</span>
        </div>
        <button type="button" data-community-close aria-label="Cerrar comunidad">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="course-community-drawer__content" data-community-content>
        <div class="course-panel-loading" role="status">La comunidad se cargará al abrirla.</div>
      </div>
    </aside>
  @endif
</div>
@endsection
