@extends('layouts.main')

@section('title','Evaluación')

@section('content')

@php
$from = request()->query('from');
@endphp

<div class="page-header mb-4">

@if($from === 'edit')
<a href="{{ route('backoffice.evaluations.edit', [$courseId, $evaluationId]) }}"
   class="text-sm text-blue-600 hover:underline block mb-1">
    ← Volver
</a>
@else
<a href="{{ route('backoffice.evaluations.show', $courseId) }}"
   class="text-sm text-blue-600 hover:underline block mb-1">
    ← Volver
</a>
@endif

    <div class="flex-1">
        <div class="text-3xl font-bold">
            {{ $evaluation['nombre'] ?? '' }}
        </div>

        <div class="text-sm text-gray-500 mt-1">
            Evaluación
             
        </div>
    </div>

</div>

@php
    $approvalScore = $evaluation['pass_score'] ?? 0;
    $maxScore = $evaluation['puntaje_max']
        ?? $evaluation['max_score']
        ?? collect($preguntas ?? [])->sum(fn($pregunta) => (int) ($pregunta['points'] ?? 0));
@endphp

@php
    $approvalScore = $evaluation['pass_score'] ?? 0;
    $weightPercent = $evaluation['weight_percent'] ?? $evaluation['peso'] ?? null;
    $timeMinutes = $evaluation['time_minutes'] ?? null;
    $totalPuntaje = collect($preguntas ?? [])->sum(fn($pregunta) => (float) ($pregunta['points'] ?? 0));
@endphp

<div class="page-shell space-y-6">
    <div class="max-w-4xl mx-auto">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">

                <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">
                Peso
            </div>
            <div class="font-medium text-gray-900">
                {{ $weightPercent !== null ? $weightPercent . '%' : 'No definido' }}
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">
                Puntaje mínimo para aprobar
            </div>
            <div class="font-medium text-gray-900">
                {{ $approvalScore }}
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">
                Tiempo
            </div>
            <div class="font-medium text-gray-900">
                {{ $timeMinutes !== null ? $timeMinutes . ' min' : 'No definido' }}
            </div>
        </div>
        </div>
    </div>

<div class="max-w-4xl mx-auto">
<div class="questions-container space-y-6">

@foreach(($preguntas ?? []) as $index => $pregunta)

<div class="question-card bg-white border border-gray-200 rounded-xl p-5 shadow-sm">

    <!-- Header -->
    <div class="mb-3 text-sm text-gray-500">
        Pregunta {{ $index + 1 }}
    </div>

    <!-- Pregunta -->
    <div class="question-text mb-4 text-base font-semibold text-gray-900">
        {{ $pregunta['text'] ?? '' }}
    </div>

    <!-- Opciones -->
    <div class="options-list space-y-2 mb-4">

        @foreach(($pregunta['options'] ?? []) as $opcion)

        <div class="option-row flex justify-between items-center px-3 py-2 rounded-lg border
            {{ ($opcion['correct'] ?? false) ? 'bg-green-50 border-green-300' : 'bg-gray-50 border-gray-200' }}">

            <div class="option-text">
                {{ $opcion['text'] ?? '' }}
            </div>

            @if($opcion['correct'] ?? false)
                <div class="text-green-600 text-xs font-semibold">
                    ✔ Correcta
                </div>
            @endif

        </div>

        @endforeach

    </div>

    <!-- Feedback -->
    @if(!empty($pregunta['feedback']))
    <div class="mb-3 text-sm text-gray-700">
        <strong>Feedback:</strong>
        <div class="mt-1">
            {{ $pregunta['feedback'] }}
        </div>
    </div>
    @endif

    <!-- Puntaje -->
    <div class="text-xs text-gray-500">
        Puntaje: {{ $pregunta['points'] ?? '' }}
    </div>

</div>

@endforeach

</div>
</div>


@endsection
