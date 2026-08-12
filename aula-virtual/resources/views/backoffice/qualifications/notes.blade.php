@extends('layouts.main')

@section('title', 'Aula Virtual - Ver notas')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
<div class="page-header">
  <a href="{{ route('backoffice.qualifications.index') }}"
     class="inline-flex items-center text-sm text-slate-500 hover:text-indigo-600">
    Volver a calificaciones
  </a>
  <div class="qualification-notes-hero">
    <div>
      <span class="qualification-pill">Libro de notas</span>
      <h1 class="mt-3">{{ $course['name'] ?? 'Curso' }}</h1>
      <p class="qualification-notes-hero-copy">
        Visualiza notas, pendientes y subsanaciones en una sola vista.
      </p>
    </div>
  </div>
</div>

<div class="page-shell qualification-notes-page"
     data-qualification-notes-root>
  @if ($error)
    <div class="qualification-empty">
      {{ $error }}
    </div>
  @else
    <section class="qualification-notes-summary">
      <article class="qualification-notes-stat">
        <span class="qualification-notes-stat-label">Promedio general</span>
        <strong class="qualification-notes-stat-value">
          {{ $summary['overall_average'] !== null ? number_format($summary['overall_average'], 2) : '--' }}
        </strong>
        <small>Escala 0 a 20</small>
      </article>
      <article class="qualification-notes-stat">
        <span class="qualification-notes-stat-label">Aprobados</span>
        <strong class="qualification-notes-stat-value">{{ $summary['approved_students_count'] ?? 0 }}</strong>
        <small>Promedio ponderado mayor o igual a 11</small>
      </article>
      <article class="qualification-notes-stat">
        <span class="qualification-notes-stat-label">Desaprobados</span>
        <strong class="qualification-notes-stat-value">{{ $summary['failed_students_count'] ?? 0 }}</strong>
        <small>Promedio ponderado menor a 11</small>
      </article>
      <article class="qualification-notes-stat">
        <span class="qualification-notes-stat-label">Pendientes</span>
        <strong class="qualification-notes-stat-value">{{ $summary['pending_cells'] ?? 0 }}</strong>
        <small>Evaluaciones por revisar</small>
      </article>
      <article class="qualification-notes-stat">
        <span class="qualification-notes-stat-label">Alumnos con pendientes</span>
        <strong class="qualification-notes-stat-value">{{ $summary['students_with_pending_count'] ?? 0 }}</strong>
        <small>Evaluacion pendiente o trabajo no presentado</small>
      </article>
      <article class="qualification-notes-stat">
        <span class="qualification-notes-stat-label">Nota minima</span>
        <strong class="qualification-notes-stat-value">
          {{ $summary['min_average_score'] !== null ? number_format($summary['min_average_score'], 2) : '--' }}
        </strong>
        <small>Promedio ponderado mas bajo</small>
      </article>
      <article class="qualification-notes-stat">
        <span class="qualification-notes-stat-label">Nota maxima</span>
        <strong class="qualification-notes-stat-value">
          {{ $summary['max_average_score'] !== null ? number_format($summary['max_average_score'], 2) : '--' }}
        </strong>
        <small>Promedio ponderado mas alto</small>
      </article>
      <article class="qualification-notes-stat">
        <span class="qualification-notes-stat-label">Subsanaciones</span>
        <strong class="qualification-notes-stat-value">{{ $summary['subsanations_total'] ?? 0 }}</strong>
        <small>Registros acumulados</small>
      </article>
    </section>

    @if(($warnings ?? collect())->isNotEmpty())
      <div class="qualification-notes-alert" role="status">
        {{ $warnings->first() }}
      </div>
    @endif

    @if(session('qualification_subsanation_success'))
      <div class="qualification-notes-alert qualification-notes-alert--success" role="status">
        {{ session('qualification_subsanation_success') }}
      </div>
    @endif

    <div class="qualification-notes-toolbar">
      <label class="qualification-notes-search">
        <span>Buscar estudiante</span>
        <input type="search"
               id="qualificationNotesSearch"
               placeholder="Nombre o correo"
               autocomplete="off">
      </label>
    </div>

    <div class="qualification-notes-layout">
      <section class="qualification-notes-board">
        <div class="qualification-notes-top-scroll" data-notes-top-scroll>
          <div class="qualification-notes-top-scroll-inner" data-notes-top-scroll-inner></div>
        </div>

        <div class="qualification-notes-table-shell">
          <table class="qualification-notes-table">
            <thead>
              <tr>
                <th class="qualification-notes-student-col">Estudiante</th>
                <th class="qualification-notes-average-col">Nota Final</th>
                @foreach ($evaluations as $evaluation)
                  <th>
                    <div class="qualification-notes-col-head">
                      <strong>{{ $evaluation['name'] ?? 'Evaluacion' }}</strong>
                      <span>{{ $evaluation['short_type'] ?? 'Evaluacion' }}</span>
                    </div>
                  </th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @forelse ($students as $student)
                <tr data-student-row
                    data-student-name="{{ mb_strtolower(($student['name'] ?? '') . ' ' . ($student['email'] ?? '')) }}">
                  <th scope="row" class="qualification-notes-student-cell">
                    <div class="qualification-notes-student">
                      <div class="qualification-review-student-avatar qualification-review-student-avatar--pending qualification-notes-student-avatar" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                          <path d="M19 21V19C19 16.7909 17.2091 15 15 15H9C6.79086 15 5 16.7909 5 19V21"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"/>
                          <circle cx="12" cy="7" r="4"
                                  stroke="currentColor"
                                  stroke-width="2"/>
                        </svg>
                      </div>
                      <div class="qualification-notes-student-copy">
                        <strong class="qualification-notes-student-name">{{ $student['name'] ?? 'Estudiante' }}</strong>
                        <span class="qualification-notes-student-email">{{ $student['email'] ?? 'Sin correo' }}</span>
                      </div>
                    </div>
                  </th>
                  <td class="qualification-notes-average-cell">
                    <strong>{{ $student['average_score'] !== null ? number_format($student['average_score'], 2) : '--' }}</strong>
                  </td>
                  @foreach ($evaluations as $evaluation)
                    @php
                      $cell = $student['cells'][$evaluation['id']] ?? null;
                      $lookupKey = ($student['key'] ?? '') . '::' . ($evaluation['id'] ?? 0);
                      $canSubsanate = ($cell['status_key'] ?? '') === 'missing' && !empty($student['email']);
                      $canUpdateSubsanation = !empty($cell['is_subsanated']) && !empty($student['email']);
                      $subsanationUrl = ($canSubsanate || $canUpdateSubsanation)
                          ? route('backoffice.qualifications.notes.subsanation', [
                              'courseId' => $courseId,
                              'evaluation_id' => $evaluation['evaluation_id'] ?? $evaluation['id'] ?? 0,
                              'course_session_evaluation_id' => $evaluation['id'] ?? 0,
                              'alumno_correo' => $student['email'],
                          ])
                          : null;
                    @endphp
                    <td>
                      @if($canUpdateSubsanation)
                        <a href="{{ $subsanationUrl }}"
                           class="qualification-notes-cell qualification-notes-cell--{{ $cell['tone'] ?? 'missing' }} qualification-notes-cell--link"
                           data-lookup-key="{{ $lookupKey }}">
                          <span class="qualification-notes-cell-score">
                            {{ $cell['display_score'] !== null ? number_format($cell['display_score'], 2) : '--' }}
                          </span>
                          <span class="qualification-notes-cell-label">{{ $cell['label'] ?? 'Sin registro' }}</span>
                        </a>
                      @else
                        <div class="qualification-notes-cell qualification-notes-cell--{{ $cell['tone'] ?? 'missing' }}"
                             data-lookup-key="{{ $lookupKey }}">
                          <span class="qualification-notes-cell-score">
                            {{ $cell['display_score'] !== null ? number_format($cell['display_score'], 2) : '--' }}
                          </span>
                          <span class="qualification-notes-cell-label">{{ $cell['label'] ?? 'Sin registro' }}</span>
                          @if($canSubsanate)
                            <a href="{{ $subsanationUrl }}" class="qualification-notes-cell-action">
                              Subsanar
                            </a>
                          @endif
                        </div>
                      @endif
                    </td>
                  @endforeach
                </tr>
              @empty
                <tr>
                  <td colspan="{{ $evaluations->count() + 2 }}">
                    <div class="qualification-empty">
                      No hay estudiantes para mostrar en este libro de notas.
                    </div>
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </section>
    </div>
  @endif
</div>
@endsection

@push('scripts')
@vite('resources/js/backoffice-qualifications-notes.js')
@endpush
