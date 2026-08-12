@extends('layouts.main')

@section('hide-app-chrome', '1')

@section('content')

<div class="exam-start-wrapper">
    <div class="exam-start-card">

        <h1 class="exam-title">
            {{ $evaluation['course_name'] ?? '' }}
        </h1>

        <p class="exam-subtitle font-semibold mb-4">
            {{ $evaluation['name'] }}
        </p>
        
        <div class="exam-stats">
            <div class="stat">
                <div class="stat-value">{{ $questionCount }}</div>
                <div class="stat-label">Preguntas</div>
            </div>

            <div class="stat">
                <div class="stat-value">{{ $evaluation['time_minutes'] }}</div>
                <div class="stat-label">Minutos</div>
            </div>

            <div class="stat">
                <div class="stat-value">{{ $evaluation['pass_score'] ?? '—' }}</div>
                <div class="stat-label">Puntaje mínimo</div>
            </div>
        </div>

        <div class="exam-instructions">
            <strong>Instrucciones</strong>
            <ul>
                <li>Lee cuidadosamente cada pregunta</li>
                <li>Puedes navegar entre las preguntas</li>
                <li>Asegúrate de responder todo antes de finalizar</li>
            </ul>
        </div>

        <a href="{{ route('mis-cursos.evaluaciones.rendicion.show', [$courseId, $sessionId, $evaluationId]) }}"
        class="btn-start">
            Comenzar Evaluación
        </a>

    </div>
</div>

@endsection
