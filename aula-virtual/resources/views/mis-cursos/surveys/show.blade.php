@extends('layouts.main')

@section('title', 'Aula Virtual - '.($encuesta->title ?? 'Encuesta'))
@section('meta-description', 'Responde la encuesta de tu curso en el Aula Virtual Smart Data.')

@section('content')
@php
  $isFinal = ($encuesta->kind ?? 'session') === 'final';
  $visibleQuestions = $preguntas->reject(fn ($question) => $question->contextual);
  $courseQuestions = $visibleQuestions->filter(fn ($question) => !$isFinal || $question->scope !== 'teacher');
  $teacherQuestions = $isFinal ? $visibleQuestions->filter(fn ($question) => $question->scope === 'teacher') : collect();
@endphp
<div class="survey-form-shell">
  <a class="survey-form-back" href="{{ route('mis-cursos.show', [$course->id, $session->id]) }}">
    <span aria-hidden="true">&larr;</span> Volver a la sesión
  </a>

  <header class="survey-form-header">
    <span class="survey-form-eyebrow">Encuesta confidencial</span>
    <h1>{{ $encuesta->title ?? $encuesta->form_name ?? 'Encuesta' }}</h1>
    <p>Tu opinión nos ayuda a mejorar la experiencia de aprendizaje.</p>
    <div class="survey-form-context" aria-label="Contexto de la encuesta">
      <span>{{ $course->title }}</span>
      <span>Edición {{ $course->edition ?? '-' }}</span>
      <span>Sesión {{ $session->number }}</span>
    </div>
    <p class="survey-form-privacy">Tu correo se obtiene de tu sesión y no necesitas escribirlo nuevamente.</p>
  </header>

  @if($errors->any())
    <div class="survey-form-error" role="alert">
      {{ $errors->first('message') ?: 'Revisa las respuestas indicadas antes de continuar.' }}
    </div>
  @endif

  <form method="POST"
        action="{{ route('mis-cursos.survey.store', [$course->id, $session->id, $encuesta->link_id]) }}"
        class="survey-form" data-survey-form>
    @csrf

    @if(!$isFinal && $docentes->count() > 1)
      <fieldset class="survey-question">
        <legend><span class="survey-question-number">1</span> Docente que deseas evaluar <span class="survey-required">Obligatoria</span></legend>
        <label class="sr-only" for="surveyTeacher">Docente</label>
        <select id="surveyTeacher" name="teacher_id" required>
          <option value="">Selecciona un docente</option>
          @foreach($docentes as $teacher)
            <option value="{{ $teacher->id }}" @selected((int) old('teacher_id') === (int) $teacher->id)>{{ $teacher->name }}</option>
          @endforeach
        </select>
      </fieldset>
    @elseif(!$isFinal && $docentes->count() === 1)
      <input type="hidden" name="teacher_id" value="{{ $docentes->first()->id }}">
    @endif

    <div class="survey-question-list">
      @foreach($courseQuestions as $question)
        @include('mis-cursos.surveys.question', [
          'question' => $question,
          'fieldName' => 'answers['.$question->code.']',
          'fieldId' => 'answer-'.$question->code,
          'errorKey' => 'answers.'.$question->code,
          'number' => $loop->iteration,
          'oldValue' => old('answers.'.$question->code),
        ])
      @endforeach

      @if($isFinal)
        @foreach($docentes as $teacher)
          <section class="survey-teacher-section" aria-labelledby="teacher-{{ $teacher->id }}-title">
            <header>
              <span>Evaluación del docente</span>
              <h2 id="teacher-{{ $teacher->id }}-title">{{ $teacher->name }}</h2>
            </header>
            @foreach($teacherQuestions as $question)
              @include('mis-cursos.surveys.question', [
                'question' => $question,
                'fieldName' => 'teacher_answers['.$teacher->id.']['.$question->code.']',
                'fieldId' => 'teacher-'.$teacher->id.'-'.$question->code,
                'errorKey' => 'teacher_answers.'.$teacher->id.'.'.$question->code,
                'number' => $loop->iteration,
                'oldValue' => old('teacher_answers.'.$teacher->id.'.'.$question->code),
              ])
            @endforeach
          </section>
        @endforeach
      @endif
    </div>

    <footer class="survey-form-footer">
      <p id="surveySubmitStatus" aria-live="polite">Revisa tus respuestas antes de enviarlas.</p>
      <button type="submit" data-survey-submit>Enviar encuesta</button>
    </footer>
  </form>
</div>
@endsection

@push('scripts')
  @vite('resources/js/mis-cursos-survey-show.js')
@endpush
