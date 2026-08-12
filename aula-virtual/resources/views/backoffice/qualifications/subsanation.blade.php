@extends('layouts.main')

@section('title', 'Aula Virtual - Subsanacion')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
@php
  $subsanation = ($history ?? collect())->first();
  $isUpdate = is_array($subsanation) && (int) ($subsanation['id'] ?? 0) > 0;
  $canSubmit = !$error
      && is_array($evaluation)
      && ((($cell['status_key'] ?? '') === 'missing') || $isUpdate)
      && !empty($student['email']);
@endphp

<div class="page-header">
  <a href="{{ $backUrl }}"
     class="inline-flex items-center text-sm text-slate-500 hover:text-indigo-600">
    Volver al libro de notas
  </a>

</div>

<div class="page-shell qualification-subsanation-page">
  @if($error)
    <div class="qualification-empty">
      {{ $error }}
    </div>
  @else
    @if(session('qualification_subsanation_error'))
      <div class="qualification-notes-alert" role="status">
        {{ session('qualification_subsanation_error') }}
      </div>
    @endif

    @if($errors->any())
      <div class="qualification-notes-alert" role="status">
        {{ $errors->first() }}
      </div>
    @endif

    @if(($warnings ?? collect())->isNotEmpty())
      <div class="qualification-notes-alert" role="status">
        {{ $warnings->first() }}
      </div>
    @endif

    <div class="qualification-subsanation-layout">
      <section class="qualification-notes-panel qualification-subsanation-main">
        <div class="qualification-notes-panel-head">
          <span class="qualification-pill">{{ $isUpdate ? 'Actualizar subsanacion' : 'Datos de subsanacion' }}</span>
        </div>

        <div class="qualification-notes-panel-content">
          <div class="qualification-notes-panel-card qualification-subsanation-summary-card">
            <div class="qualification-notes-panel-student">
              <div class="qualification-review-student-avatar qualification-review-student-avatar--pending qualification-notes-panel-avatar" aria-hidden="true">
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

              <div class="qualification-notes-panel-student-copy">
                <span class="qualification-notes-panel-label">Alumno</span>
                <strong>{{ $student['name'] ?? 'Estudiante' }}</strong>
                <span class="qualification-notes-panel-email">{{ $student['email'] ?? 'Sin correo' }}</span>
              </div>
            </div>

            <div class="qualification-notes-panel-grid">
              <div>
                <span class="qualification-notes-panel-label">Evaluacion</span>
                <strong>{{ $evaluation['name'] ?? 'Evaluacion' }}</strong>
              </div>
              <div>
                <span class="qualification-notes-panel-label">Nota actual</span>
                <strong class="qualification-subsanation-score">
                  {{ ($cell['display_score'] ?? null) !== null ? number_format($cell['display_score'], 2) : ($cell['label'] ?? '--') }}
                </strong>
              </div>
              <div>
                <span class="qualification-notes-panel-label">Minimo aprobatorio</span>
                <strong>{{ number_format((float) ($evaluation['pass_score'] ?? 11), 2) }}</strong>
              </div>
            </div>
          </div>

          @if($canSubmit)
            <form method="POST"
                  action="{{ $isUpdate ? $updateUrl : $saveUrl }}"
                  enctype="multipart/form-data"
                  class="qualification-notes-form qualification-subsanation-form">
              @csrf
              @if($isUpdate)
                @method('PUT')
                <input type="hidden" name="subsanacion_id" value="{{ $subsanation['id'] ?? 0 }}">
              @endif
              <input type="hidden" name="evaluation_id" value="{{ $evaluation['evaluation_id'] ?? $evaluation['id'] ?? 0 }}">
              <input type="hidden" name="alumno_correo" value="{{ $student['email'] ?? '' }}">
              
              <label>
                <span>Nueva nota</span>
                <input type="number"
                       name="score"
                       min="0"
                       max="20"
                       step="0.01"
                       value="{{ old('score', $isUpdate ? ($subsanation['score'] ?? null) : null) }}"
                       placeholder="0.00"
                       required>
              </label>

              <label>
                <span>Motivo</span>
                <input type="text"
                       name="motivo"
                       maxlength="200"
                       value="{{ old('motivo', $isUpdate ? ($subsanation['reason'] ?? '') : '') }}"
                       placeholder="Ej. ajuste por revision">
              </label>

              <label>
                <span>Observacion</span>
                <textarea name="observacion"
                          rows="4"
                          maxlength="1000"
                          placeholder="Detalle de la subsanacion">{{ old('observacion', $isUpdate ? ($subsanation['observation'] ?? '') : '') }}</textarea>
              </label>

              <label>
                <span>Evidencia</span>
                @if(!empty($evidenceFile))
                  <div class="qualification-subsanation-current-file">
                    <span>Archivo actual</span>
                    @if(!empty($evidenceFile['url']))
                      <a href="{{ $evidenceFile['url'] }}" target="_blank" rel="noopener">
                        {{ $evidenceFile['name'] }}
                      </a>
                    @else
                      <strong>{{ $evidenceFile['name'] }}</strong>
                    @endif
                  </div>
                @endif
                <input type="file"
                       name="evidencia_archivo"
                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,.zip,.rar">
              </label>

              <div class="qualification-subsanation-actions">
                <a href="{{ $backUrl }}" class="qualification-course-btn qualification-course-btn--secondary">
                  Cancelar
                </a>
                <button type="submit" class="qualification-course-btn qualification-course-btn--primary">
                  {{ $isUpdate ? 'Actualizar subsanacion' : 'Registrar subsanacion' }}
                </button>
              </div>
            </form>
          @else
            <div class="qualification-empty">
              Esta evaluacion no esta disponible para registrar una subsanacion.
            </div>
          @endif
        </div>
      </section>

      
    </div>
  @endif
</div>
@endsection
