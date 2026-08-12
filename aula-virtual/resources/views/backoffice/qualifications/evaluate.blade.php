@extends('layouts.main')

@section('title', 'Aula Virtual - Revisar entregas')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
@php
  $evaluationName = $evaluation['nombre'] ?? $evaluation['name'] ?? 'Trabajo';
  $correctedCount = (int) ($summary['corrected_count'] ?? 0);
  $pendingCount = (int) ($summary['pending_count'] ?? 0);
  $totalParticipants = (int) ($summary['total'] ?? 0);
  $selectedDeliveryId = (int) ($selectedDeliveryId ?? 0);
  $hasSelection = $selectedDeliveryId > 0 && !empty($selectedParticipant);
  $selectedName = $hasSelection
      ? ($selectedParticipant['name'] ?? 'Participante')
      : 'Selecciona una entrega';

  $selectedSubmissionDate = null;
  $selectedSubmissionLabel = 'Sin entrega registrada';
  if ($hasSelection) {
      $selectedSubmissionDate = $selectedParticipant['submitted_at'] ?? null;
      $hasDelivery = !empty($selectedParticipant['has_delivery']) || (int) ($selectedParticipant['delivery_id'] ?? 0) > 0;
      $hasScore = is_numeric($selectedParticipant['score'] ?? $selectedParticipant['puntaje_total'] ?? $selectedParticipant['nota_final'] ?? $selectedParticipant['nota'] ?? null);
      $selectedSubmissionLabel = $selectedSubmissionDate
          ? 'Entrega registrada'
          : (($selectedParticipant['status_key'] ?? '') === 'draft'
              ? 'Borrador sin enviar'
              : ($hasDelivery
                  ? 'Entrega registrada'
                  : ($hasScore ? 'Entrega registrada sin adjuntos' : 'Sin entrega registrada')));
  }

  $attachments = $hasSelection
      ? collect($review['attachments'] ?? [])->values()
      : collect();

  $rubricCriteria = $hasSelection
      ? collect($review['rubric']['criteria'] ?? [])->values()
      : collect();

  $feedback = $hasSelection
      ? trim((string) ($review['delivery']['feedback'] ?? ''))
      : '';

  $studentComment = $hasSelection
      ? trim((string) ($review['delivery']['student_comment'] ?? ''))
      : '';

  $totalScore = $hasSelection
      ? ($review['totals']['score'] ?? $selectedParticipant['score'] ?? null)
      : null;

  $maxScore = $hasSelection
      ? ($review['totals']['max_score'] ?? $selectedParticipant['max_score'] ?? $criteria->sum('max_score'))
      : $criteria->sum('max_score');

  $canGrade = $hasSelection && $rubricCriteria->isNotEmpty();
  $nextDeliveryId = (int) ($nextParticipant['delivery_id'] ?? 0);
  $hasNextDelivery = $nextDeliveryId > 0 && $nextDeliveryId !== $selectedDeliveryId;

  $levelLabels = [
      1 => 'No cumple',
      2 => 'Basico',
      3 => 'A medias',
      4 => 'Cumple',
      5 => 'Destacado',
  ];

  if ($hasSelection && $rubricCriteria->isEmpty()) {
      $rubricCriteria = $criteria->map(function (array $criterion, int $index) {
          return [
              'id' => $index + 1,
              'name' => $criterion['label'] ?? 'Criterio',
              'description' => '',
              'max_score' => (float) ($criterion['max_score'] ?? 0),
              'score' => null,
              'level' => null,
              'comment' => '',
          ];
      })->values();
  }

  $buildEvaluateUrl = function (?int $deliveryId = null) use ($courseId, $evaluationId) {
      $query = array_filter([
          'entregaId' => $deliveryId ?: null,
      ], fn ($value) => $value !== null && $value !== '');

      $baseUrl = route('backoffice.qualifications.evaluate', [$courseId, $evaluationId]);

      return empty($query) ? $baseUrl : $baseUrl . '?' . http_build_query($query);
  };

  $formatDate = function (?string $value, string $fallback = 'Sin fecha') {
      if (!$value) {
          return $fallback;
      }

      try {
          return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
      } catch (\Throwable $e) {
          return $fallback;
      }
  };

  $formatDateTime = function (?string $value, string $fallback = 'Sin fecha') {
      if (!$value) {
          return $fallback;
      }

      try {
          return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i');
      } catch (\Throwable $e) {
          return $fallback;
      }
  };

  $formatScore = function ($value) {
      if ($value === null || $value === '') {
          return '--';
      }

      $formatted = number_format((float) $value, 2, '.', '');

      return rtrim(rtrim($formatted, '0'), '.');
  };

  $formatFileSize = function (?int $bytes) {
      if (!$bytes || $bytes <= 0) {
          return 'Archivo';
      }

      $units = ['B', 'KB', 'MB', 'GB'];
      $size = (float) $bytes;
      $unitIndex = 0;

      while ($size >= 1024 && $unitIndex < count($units) - 1) {
          $size /= 1024;
          $unitIndex++;
      }

      $precision = $unitIndex === 0 ? 0 : 1;

      return number_format($size, $precision, '.', '') . ' ' . $units[$unitIndex];
  };

  $statusClass = function (string $statusKey) {
      return match ($statusKey) {
          'corrected' => 'qualification-review-student-status qualification-review-student-status--corrected',
          'reviewing' => 'qualification-review-student-status qualification-review-student-status--reviewing',
          'missing' => 'qualification-review-student-status qualification-review-student-status--missing',
          default => 'qualification-review-student-status qualification-review-student-status--pending',
      };
  };

  $statusDotClass = function (string $statusKey) {
      return match ($statusKey) {
          'corrected' => 'qualification-review-student-dot qualification-review-student-dot--corrected',
          'reviewing' => 'qualification-review-student-dot qualification-review-student-dot--reviewing',
          'missing' => 'qualification-review-student-dot qualification-review-student-dot--missing',
          default => 'qualification-review-student-dot qualification-review-student-dot--pending',
      };
  };

  $computeWeightedScore = function (?int $level, float $criterionMaxScore) {
      if ($level === null || $level < 1 || $level > 5 || $criterionMaxScore <= 0) {
          return null;
      }

      return round(($criterionMaxScore * ($level - 1)) / 4, 2);
  };

  $pageProgress = $totalParticipants > 0
      ? $correctedCount . ' de ' . $totalParticipants . ' corregidos'
      : 'Sin participantes';
@endphp

<div class="page-header">
  <a href="{{ route('backoffice.qualifications.show', $courseId) }}"
     class="inline-flex items-center text-sm text-slate-500 hover:text-indigo-600">
    Volver a evaluaciones
  </a>
  <div class="mt-3 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
    <div>
      <h1>{{ $evaluationName }}</h1>
      <p class="text-sm text-slate-500">
        Progreso: {{ $pageProgress }}
      </p>
    </div>

    <div class="qualification-review-topbar" data-review-topbar>
      <div class="qualification-review-final-score">
        <span>Nota final</span>
        <strong data-review-total-score>{{ $formatScore($totalScore) }}/{{ $formatScore($maxScore) }}</strong>
      </div>

      @if($canGrade)
      <button type="submit"
              form="qualificationReviewForm"
              name="save_action"
              value="stay"
              class="qualification-review-secondary-btn">
        Guardar
      </button>

      @if($hasNextDelivery)
        <button type="submit"
                form="qualificationReviewForm"
                name="save_action"
                value="next"
                class="qualification-review-primary-btn">
          Guardar y siguiente
        </button>
      @endif
      @else
      <button type="button" class="qualification-review-primary-btn qualification-review-primary-btn--disabled" disabled>
        Selecciona una entrega
      </button>
      @endif
    </div>
  </div>
</div>

<div class="page-shell">
  <div data-review-feedback>
  @if($error)
  <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
    {{ $error }}
  </div>
  @endif

  @if(session('qualification_review_success'))
  <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
    {{ session('qualification_review_success') }}
  </div>
  @endif

  @if(session('qualification_review_error'))
  <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
    {{ session('qualification_review_error') }}
  </div>
  @endif
  </div>

  <section class="qualification-review-layout">
    <aside class="qualification-review-sidebar">
      <div class="qualification-review-sidebar-head">
        <div>
          <h2>Estudiantes</h2>
        </div>

        <div class="qualification-review-stats">
          <article class="qualification-review-stat qualification-review-stat--corrected">
            <span>Corregidos</span>
            <strong data-review-corrected-count>{{ $correctedCount }}</strong>
          </article>
          <article class="qualification-review-stat qualification-review-stat--pending">
            <span>Pendientes</span>
            <strong data-review-pending-count>{{ $pendingCount }}</strong>
          </article>
        </div>

        <div class="qualification-review-search" data-student-search>
          <input
            name="search"
            type="search"
            value="{{ $search }}"
            placeholder="Buscar estudiante..."
            class="qualification-review-search-input"
            autocomplete="off"
            data-student-search-input>
          <a href="{{ $buildEvaluateUrl($selectedDeliveryId ?: null) }}"
             class="qualification-review-refresh-btn"
             data-review-navigation>
            Refrescar
          </a>
        </div>
      </div>

      <div class="qualification-review-student-list" data-student-list>
       @forelse($participants as $participant)
          @php
            $participantDeliveryId = (int) ($participant['delivery_id'] ?? 0);
            $statusKey = $participant['status_key'] ?? '';
            $isActive = $participantDeliveryId > 0 && $participantDeliveryId === $selectedDeliveryId;
            $hasDelivery = $participantDeliveryId > 0 || !empty($participant['has_delivery']);
            $isCorrected = $statusKey === 'corrected';
            $isReviewing = $statusKey === 'reviewing';
 
            if ($isCorrected) {
                $studentState = 'Calificado';
                $studentStateClass = 'qualification-review-student-state--corrected';
                $indicatorClass = 'qualification-review-student-indicator--corrected';
                $avatarClass = 'qualification-review-student-avatar--corrected';
            } elseif ($isReviewing) {
                $studentState = 'En revisión';
                $studentStateClass = 'qualification-review-student-state--reviewing';
                $indicatorClass = 'qualification-review-student-indicator--reviewing';
                $avatarClass = 'qualification-review-student-avatar--reviewing';
            } elseif (!$hasDelivery) {
                $studentState = 'Sin entrega';
                $studentStateClass = 'qualification-review-student-state--missing';
                $indicatorClass = 'qualification-review-student-indicator--missing';
                $avatarClass = 'qualification-review-student-avatar--missing';
            } else {
                $studentState = 'Pendiente';
                $studentStateClass = 'qualification-review-student-state--pending';
                $indicatorClass = 'qualification-review-student-indicator--pending';
                $avatarClass = 'qualification-review-student-avatar--pending';
            }
          @endphp

          <a href="{{ $hasDelivery ? $buildEvaluateUrl($participantDeliveryId) : '#' }}"
            class="qualification-review-student-card
                    {{ $isActive ? 'qualification-review-student-card--active' : '' }}
                    {{ !$hasDelivery ? 'qualification-review-student-card--disabled' : '' }}"
            data-student-card
            data-review-navigation
            data-delivery-id="{{ $participantDeliveryId ?: '' }}"
            data-status-key="{{ $participant['status_key'] ?? ($hasDelivery ? 'pending' : 'missing') }}"
            data-student-name="{{ mb_strtolower((string) ($participant['name'] ?? 'Participante')) }}">

              <div class="qualification-review-student-avatar {{ $avatarClass }}" data-student-avatar>
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

              <div class="qualification-review-student-body">
                <div class="qualification-review-student-topline">
                  <strong class="qualification-review-student-name">
                    {{ $participant['name'] ?? 'Participante' }}
                  </strong>

                  <span class="qualification-review-student-indicator {{ $indicatorClass }}" data-student-indicator></span>
                </div>

                <div class="qualification-review-student-meta-simple">
                  <span class="qualification-review-student-date">
                    {{ $hasDelivery ? $formatDate($participant['submitted_at'] ?? null, 'Sin fecha') : 'Sin fecha' }}
                  </span>

                  <span class="qualification-review-student-state {{ $studentStateClass }}" data-student-state>
                    {{ $studentState }}
                  </span>
                </div>
              </div>
          </a>
        @empty
          <div class="qualification-review-empty" data-student-list-empty>
            No se encontraron participantes para mostrar.
          </div>
        @endforelse

        @if($participants->isNotEmpty())
          <div class="qualification-review-empty" data-student-search-empty hidden>
            No se encontraron estudiantes con ese nombre.
          </div>
        @endif
      </div>
      
      <div class="qualification-review-sidebar-foot" data-student-count data-total-students="{{ $participants->count() }}">
        {{ $participants->count() }} estudiantes
      </div>
    </aside>

    <div class="qualification-review-main" data-review-main>
      <section class="qualification-review-panel qualification-review-panel--header">
        @if($hasSelection)
        <div class="qualification-review-selected">
          <div class="qualification-review-selected-avatar">
            {{ $selectedParticipant['initials'] ?? 'AV' }}
          </div>

          <div class="qualification-review-selected-body">
            <h2>{{ $selectedName }}</h2>
            <p>{{ $formatDateTime($selectedSubmissionDate, $selectedSubmissionLabel) }}</p>
          </div>
        </div>
        @else
        <div class="qualification-review-selected">
          <div class="qualification-review-selected-avatar">
            --
          </div>

          <div class="qualification-review-selected-body">
            <h2>Selecciona una entrega</h2>
            <p>Elige un estudiante del panel izquierdo para comenzar.</p>
          </div>
        </div>
        @endif

        <div class="qualification-review-nav">
          @if($hasSelection && $previousParticipant && ($previousParticipant['delivery_id'] ?? 0) > 0)
          <a href="{{ $buildEvaluateUrl((int) $previousParticipant['delivery_id']) }}"
             class="qualification-review-icon-btn"
             data-review-navigation
             aria-label="Anterior">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
              <path d="M11.75 5.5L7.25 10L11.75 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
          @else
          <span class="qualification-review-icon-btn qualification-review-icon-btn--disabled">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
              <path d="M11.75 5.5L7.25 10L11.75 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
          @endif

          @if($hasSelection && $nextParticipant && ($nextParticipant['delivery_id'] ?? 0) > 0)
          <a href="{{ $buildEvaluateUrl((int) $nextParticipant['delivery_id']) }}"
             class="qualification-review-icon-btn"
             data-review-navigation
             aria-label="Siguiente">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
              <path d="M8.25 5.5L12.75 10L8.25 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
          @else
          <span class="qualification-review-icon-btn qualification-review-icon-btn--disabled">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
              <path d="M8.25 5.5L12.75 10L8.25 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
          @endif
        </div>
      </section>

      @if($reviewError)
      <div class="qualification-review-inline-alert">
        {{ $reviewError }}
      </div>
      @endif

      <section class="qualification-review-panel">
        @if(!$hasSelection)
        <div class="qualification-review-empty qualification-review-empty--large">
          Selecciona una entrega para visualizar archivos.
        </div>
        @elseif($attachments->isEmpty())
        <div class="qualification-review-empty qualification-review-empty--large">
          Esta entrega no tiene archivos adjuntos disponibles.
        </div>
        @else
        <div class="qualification-review-files">
          @foreach($attachments as $attachment)
          <article class="qualification-review-file-card">
            <div class="qualification-review-file-main">
              <div class="qualification-review-file-icon">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M8 3.75H13.5L18.25 8.5V19.25C18.25 19.94 17.69 20.5 17 20.5H8C7.31 20.5 6.75 19.94 6.75 19.25V5C6.75 4.31 7.31 3.75 8 3.75Z" stroke="currentColor" stroke-width="1.6"/>
                  <path d="M13.25 3.75V8.25H17.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </div>

              <div class="qualification-review-file-text">
                <strong>{{ $attachment['name'] ?? 'Archivo' }}</strong>
                <span>{{ $formatFileSize($attachment['size_bytes'] ?? null) }} @if(!empty($attachment['extension'])) &middot; {{ strtoupper($attachment['extension']) }} @endif</span>
              </div>
            </div>

            @if(!empty($attachment['id']))
            <a href="{{ route('backoffice.qualifications.attachments.download', [$courseId, $evaluationId, $attachment['id']]) }}"
               target="_blank"
               rel="noopener noreferrer"
               class="qualification-review-download-btn">
              Descargar
            </a>
            @elseif(!empty($attachment['download_url']))
            <a href="{{ $attachment['download_url'] }}"
               target="_blank"
               rel="noopener noreferrer"
               class="qualification-review-download-btn">
              Descargar
            </a>
            @else
            <span class="qualification-review-download-btn qualification-review-download-btn--disabled">
              Sin enlace
            </span>
            @endif
          </article>
          @endforeach
        </div>
        @endif

        @if($hasSelection && $studentComment !== '')
        <div class="qualification-review-notes">
          <article class="qualification-review-note-card">
            <span>Comentario del estudiante</span>
            <p>{{ $studentComment }}</p>
          </article>
        </div>
        @endif
      </section>
    </div>

    <aside class="qualification-review-rubric" data-review-rubric>
      @if($hasSelection)
      <form id="qualificationReviewForm"
            method="POST"
            data-no-global-loader
            action="{{ route('backoffice.qualifications.review.save', [$courseId, $evaluationId, $selectedDeliveryId]) }}">
        @csrf
        <input type="hidden" name="next_delivery_id" value="{{ $nextDeliveryId }}">

        <section class="qualification-review-panel qualification-review-panel--rubric">
          <div class="qualification-review-rubric-head">
            <h2>{{ $review['rubric']['name'] ?? 'Rúbrica de Evaluación' }}</h2>
            <p class="qualification-review-rubric-hint">
              Marca un nivel por criterio. El sistema convierte la escala 1 a 5 en puntaje ponderado del criterio.
            </p>
          </div>

          <div class="qualification-review-rubric-list">
            @forelse($rubricCriteria as $index => $criterion)
            @php
              $criterionId = (int) ($criterion['id'] ?? ($criterion['criterio_id'] ?? ($index + 1)));
              $existingLevel = isset($criterion['level']) ? (int) $criterion['level'] : null;
              $selectedLevel = old("criteria.$criterionId.level");
              $selectedLevel = $selectedLevel !== null ? (int) $selectedLevel : $existingLevel;
              $criterionScore = $computeWeightedScore($selectedLevel, (float) ($criterion['max_score'] ?? 0));
            @endphp
            <article class="qualification-review-rubric-card qualification-review-rubric-card--editable"
                     data-review-criterion
                     data-max-score="{{ (float) ($criterion['max_score'] ?? 0) }}">
              <div class="qualification-review-rubric-copy">
                <strong>{{ $criterion['name'] ?? 'Criterio' }}</strong>
                @if(!empty($criterion['description']))
                <p>{{ $criterion['description'] }}</p>
                @endif
              </div>

              <div class="qualification-review-rubric-editor">
                <div class="qualification-review-levels">
                  @foreach($levelLabels as $level => $label)
                  <label class="qualification-review-level-option">
                    <input type="radio"
                           name="criteria[{{ $criterionId }}][level]"
                           value="{{ $level }}"
                           {{ $selectedLevel === $level ? 'checked' : '' }}>
                    <span class="qualification-review-level-chip">
                      <strong>{{ $level }}</strong>
                      <small>{{ $label }}</small>
                    </span>
                  </label>
                  @endforeach
                </div>

                <div class="qualification-review-rubric-score">
                  <span>Puntaje</span>
                  <strong data-review-criterion-score>{{ $formatScore($criterionScore) }}/{{ $formatScore($criterion['max_score'] ?? 0) }}</strong>
                </div>
              </div>
            </article>
            @empty
            <div class="qualification-review-empty qualification-review-empty--large">
              No hay criterios disponibles para esta revision.
            </div>
            @endforelse
          </div>

          <div class="qualification-review-observation">
            <label for="qualificationTeacherObservation">Observacion del docente</label>
            <textarea id="qualificationTeacherObservation"
                      name="observacion_docente"
                      rows="4"
                      class="qualification-review-textarea"
                      placeholder="Escribe una retroalimentacion general para el estudiante...">{{ old('observacion_docente', $feedback) }}</textarea>
          </div>

          <div class="qualification-review-total">
            <span>Total de puntos</span>
            <strong data-review-total-score>{{ $formatScore($totalScore) }}/{{ $formatScore($maxScore) }}</strong>
          </div>
        </section>
      </form>
      @else
      <section class="qualification-review-panel qualification-review-panel--rubric">
        <div class="qualification-review-empty qualification-review-empty--large">
          Selecciona un estudiante con entrega para calificar.
        </div>
      </section>
      @endif
    </aside>
  </section>
</div>
@endsection

@push('scripts')
@vite('resources/js/backoffice-qualifications-evaluate.js')
@endpush
