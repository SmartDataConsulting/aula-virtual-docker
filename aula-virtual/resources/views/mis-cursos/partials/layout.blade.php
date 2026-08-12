@extends('layouts.main')

@section('content')
@php
  $sessionItems = collect($sessions ?? [])->values();
  $selectedIndex = !empty($session?->id)
      ? $sessionItems->search(fn ($item) => (int) $item->id === (int) $session->id)
      : false;
  $sessionPosition = $selectedIndex === false ? 0 : $selectedIndex + 1;
  $courseState = trim((string) ($course->state ?? $course->status ?? ''));
@endphp

<div class="course-detail-page student-course-workspace"
     data-course-workspace-root
     data-workspace-context="student"
     data-course-id="{{ $course->id }}"
     data-session-id="{{ $session->id ?? '' }}"
     data-session-navigation-mode="{{ $sessionNavigationMode ?? 'ajax' }}"
     data-show-url-template="{{ route('mis-cursos.show', [$course->id, '__SESSION__']) }}"
     data-workspace-url-template="{{ route('mis-cursos.sessions.workspace', [$course->id, '__SESSION__']) }}"
     data-panel-url-template="{{ route('mis-cursos.sessions.panels.show', [$course->id, '__SESSION__', '__PANEL__']) }}"
     @if(session('invalidate_course_panel')) data-invalidate-panel="{{ session('invalidate_course_panel') }}" @endif
     @if(session('invalidate_course_session')) data-invalidate-session="{{ session('invalidate_course_session') }}" @endif
     data-community-url="{{ route('mis-cursos.community.show', $course->id) }}">
  <nav class="course-breadcrumb" aria-label="Migas de pan">
    <a href="{{ route('mis-cursos.index') }}">Mis cursos</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ $course->title ?? 'Curso' }}</span>
  </nav>

  <header class="course-context-header">
    <div class="course-context-main">
      <div class="course-context-chips">
        @if(!empty($course->edition))<span>Edición {{ $course->edition }}</span>@endif
        @if($courseState !== '')<span>{{ \Illuminate\Support\Str::headline($courseState) }}</span>@endif
      </div>
      <h1>{{ $course->title ?? 'Curso' }}</h1>
      @if(!empty($course->teacher_name))<p>Docente: {{ $course->teacher_name }}</p>@endif
    </div>

    <button type="button" class="course-mobile-sessions-button" data-open-session-drawer
            aria-controls="courseSessionSidebar" aria-expanded="false">
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
      @include('mis-cursos.partials.sidebar', compact('course', 'session') + [
          'sessions' => $sessionItems,
          'sidebarDefaultTab' => $sidebarDefaultTab ?? 'video',
      ])
    </aside>

    <section id="courseWorkspace" class="course-workspace" aria-live="polite" aria-busy="false">
      @yield('course-content')
    </section>
  </div>

  <button type="button" class="course-community-launcher" data-community-toggle
          aria-controls="courseCommunityDrawer" aria-expanded="false">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8h10M7 12h6m-7.5 6.5 2.2-2.2H17a4 4 0 0 0 4-4V8a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v4.3a4 4 0 0 0 2.5 3.7v2.5Z" /></svg>
    <span>Comunidad</span><strong data-community-count hidden>0</strong>
  </button>

  <div class="course-community-backdrop" data-community-backdrop hidden></div>
  <aside id="courseCommunityDrawer" class="course-community-drawer"
         aria-label="Comunidad del curso" aria-hidden="true" inert>
    <div class="course-community-drawer__header">
      <div><strong>Comunidad del curso</strong><span data-community-summary>Comentarios y participantes</span></div>
      <button type="button" data-community-close aria-label="Cerrar comunidad"><span aria-hidden="true">&times;</span></button>
    </div>
    <div class="course-community-drawer__content" data-community-content>
      <div class="course-panel-loading" role="status">La comunidad se cargará al abrirla.</div>
    </div>
  </aside>
  <div class="student-certificate-modal" data-certificate-modal hidden>
    <div class="student-certificate-modal__backdrop" data-certificate-close></div>
    <section class="student-certificate-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="studentCertificateModalTitle">
      <header>
        <div>
          <strong id="studentCertificateModalTitle">Mi certificado</strong>
          <span>Revisa el documento antes de descargarlo.</span>
        </div>
        <button type="button" data-certificate-close aria-label="Cerrar certificado">&times;</button>
      </header>
      <div class="student-certificate-modal__body" data-certificate-preview-body>
        <div class="course-panel-loading" role="status"><span></span>Cargando certificado...</div>
      </div>
      <footer>
        <button type="button" data-certificate-copy>Copiar enlace</button>
        <a href="#" target="_blank" rel="noopener noreferrer" download data-certificate-download>Descargar</a>
      </footer>
    </section>
  </div>
</div>
@endsection
