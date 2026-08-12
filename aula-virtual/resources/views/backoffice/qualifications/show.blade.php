@extends('layouts.main')

@section('title','Aula Virtual - Calificaciones del curso')
@section('body-class','bg-gray-50 min-h-screen text-gray-800')

@section('content')

@php
  use Illuminate\Support\Carbon;

  $courseName = $course['name'] ?? 'Curso';
  $courseSubtitle = $course['subtitle'] ?? 'Gestion de evaluaciones';

  $formatDate = function (?string $value, string $fallback = 'Sin fecha') {
      if (!$value) {
          return $fallback;
      }

      try {
          return Carbon::parse($value)->translatedFormat('d M Y');
      } catch (\Throwable $e) {
          return $fallback;
      }
  };

  $formatNumber = function ($value, int $decimals = 1) {
      return number_format((float) $value, $decimals, '.', '');
  };

  $statusClasses = function (string $status) {
      return match (mb_strtolower($status)) {
          'completado' => 'qualification-status qualification-status--completed',
          'borrador' => 'qualification-status qualification-status--draft',
          default => 'qualification-status qualification-status--pending',
      };
  };
@endphp

<div class="page-header">
  <a href="{{ route('backoffice.qualifications.index') }}"
     class="inline-flex items-center text-sm text-gray-500 hover:text-indigo-600 mb-3">
    Volver a calificaciones
  </a>
  <h1>{{ $courseName }}</h1>
  <p class="text-sm text-gray-500">
    {{ $courseSubtitle }}
  </p>
</div>

<div class="page-shell space-y-6">

  @if($error)
  <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
    {{ $error }}
  </div>
  @endif

  <section class="space-y-5">
      @if($evaluations->isEmpty())
      <div class="qualification-empty">
        Este curso todavia no tiene trabajos publicados o listos para revisar.
      </div>
      @else
      <div class="qualification-work-grid">
        @foreach($evaluations as $evaluation)
        @php
          $dateLabel = $formatDate($evaluation['deadline'] ?? $evaluation['created_at'] ?? null);
          $weightLabel = $formatNumber($evaluation['weight_percent'] ?? 0, 0);
          $progressWidth = max(0, min(100, (float) ($evaluation['progress_percent'] ?? 0)));
          $detailEvaluationId = (int) ($evaluation['evaluation_id'] ?? $evaluation['id'] ?? 0);
          $detailUrl = route('backoffice.qualifications.evaluate', [$courseId, $detailEvaluationId]);
        @endphp
        <article class="qualification-card">
          <div class="flex flex-wrap items-center gap-2">
            <span class="qualification-pill">
              Trabajo
            </span>
            <span class="{{ $statusClasses($evaluation['status'] ?? 'Pendiente') }}">
              {{ $evaluation['status'] ?? 'Pendiente' }}
            </span>
          </div>

          <div class="mt-4">
            <h2 class="qualification-card-title">
              {{ $evaluation['name'] }}
            </h2>
            <div class="qualification-card-meta">
              <span>{{ $dateLabel }}</span>
              <span>{{ $weightLabel }}% del curso</span>
            </div>
          </div>

          <div class="qualification-stat-grid">
            <div class="qualification-stat-card">
              <div class="qualification-stat-label">Entregas</div>
              <div class="qualification-stat-value">
                Entregaron: {{ $evaluation['delivered_count'] }} / {{ $evaluation['students_total'] }}
              </div>
              <div class="qualification-stat-note">
                Sin entregar: {{ $evaluation['missing_count'] }}
              </div>
            </div>

            <div class="qualification-stat-card">
              <div class="qualification-stat-label">Correccion</div>
              <div class="qualification-stat-value">
                Corregidos: {{ $evaluation['corrected_count'] ?? 0 }} / {{ $evaluation['delivered_count'] }}
              </div>
              <div class="qualification-stat-note">
                Pendientes de correccion: {{ $evaluation['pending_correction_count'] }}
              </div>
            </div>
          </div>

          <div class="qualification-progress-row">
            <span>Progreso</span>
            <span class="qualification-progress-value">
              {{ $evaluation['corrected_count'] ?? 0 }} / {{ $evaluation['delivered_count'] }}
            </span>
          </div>

          <div class="w-full bg-gray-100 h-2 rounded-full overflow-hidden">
            <div class="h-2 bg-indigo-600"
                 style="width: {{ $progressWidth }}%"></div>
          </div>

          <div class="mt-5">
            <a href="{{ $detailUrl }}"
               class="w-full inline-block text-center px-6 py-3 rounded-lg accent text-white font-semibold">
              Calificar trabajo
            </a>
          </div>
        </article>
        @endforeach
      </div>
      @endif
  </section>
</div>

@endsection
