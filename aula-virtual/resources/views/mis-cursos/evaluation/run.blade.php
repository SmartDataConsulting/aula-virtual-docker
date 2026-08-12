@extends('layouts.main')

@section('hide-app-chrome', '1')

@section('content')

<div class="exam-run-wrapper min-h-screen flex flex-col bg-white">

    {{-- HEADER ARRIBA --}}
    @include('mis-cursos.evaluation.partials.header')

    {{-- BODY --}}
    <div class="flex flex-1 overflow-hidden">

        {{-- SIDEBAR --}}
        @include('mis-cursos.evaluation.partials.sidebar')

        {{-- CONTENT --}}
        @include('mis-cursos.evaluation.partials.question')

    </div>

</div>
<div id="finishModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-50">

    <div class="bg-white w-[460px] rounded-xl shadow-lg overflow-hidden">

        <!-- header -->
        <div class="flex items-center justify-between px-5 py-4 border-b">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-[#EEF4FF] flex items-center justify-center text-[#1F6AE1]">
                    !
                </div>
                <span class="font-medium text-[#0A2540]">Confirmar envío</span>
            </div>

            <button class="js-close-modal text-gray-400 hover:text-gray-600">
                ✕
            </button>
        </div>

        <!-- body -->
        <div class="p-5">

            <p class="text-sm text-[#2B2B2B] mb-4 js-unanswered-text">
                ...
            </p>

            <div class="bg-[#EEF4FF] text-[#1F6AE1] text-sm rounded-lg px-4 py-3 mb-5">
                Las preguntas sin responder se contarán como incorrectas.
            </div>

            <div class="flex justify-end gap-3">
                <button class="js-close-modal px-4 py-2 border rounded-lg text-[#2B2B2B] hover:bg-gray-50">
                    Continuar evaluación
                </button>

                <button class="js-confirm-finish px-5 py-2 bg-[#1F6AE1] text-white rounded-lg">
                    Finalizar
                </button>
            </div>

        </div>

    </div>
</div>
<div id="gradingOverlay" class="fixed inset-0 hidden items-center justify-center bg-black/30 z-50">
    <div class="bg-white px-6 py-5 rounded-xl shadow-lg text-center">
        <div class="mb-3 text-[#0A2540] font-medium">
            Calificando evaluación...
        </div>
        <div class="w-6 h-6 border-2 border-[#1F6AE1] border-t-transparent rounded-full animate-spin mx-auto"></div>
    </div>
</div>

@include('mis-cursos.evaluation.partials.result')
<div
    id="evaluationRunContext"
    class="hidden"
    data-minutes="{{ $evaluacion['time_minutes'] ?? 30 }}"
    data-pass-score="{{ $evaluacion['pass_score'] ?? 11 }}"
    data-answer-url="{{ route('mis-cursos.evaluaciones.rendicion.answer', [$courseId, $sessionId, $evaluationId]) }}"
    data-finalize-url="{{ route('mis-cursos.evaluaciones.rendicion.finalize', [$courseId, $sessionId, $evaluationId]) }}">
</div>

<template id="evaluationRunPayload">{{ json_encode([
    'questions' => $preguntas,
    'submission' => $rendicion ?? null,
    'answers' => $respuestas ?? [],
    'finalResult' => $resultadoFinal ?? null
]) }}</template>

@vite('resources/js/evaluation-run.js')

@endsection
