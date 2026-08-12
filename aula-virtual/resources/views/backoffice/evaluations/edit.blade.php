@extends('layouts.main')

@section('title','Editar evaluación')

@section('content')

<div id="appLoadingOverlay"
     class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/50">

    <div class="bg-white px-6 py-4 rounded-xl shadow-xl text-center">
        <div class="text-lg font-semibold" id="loadingText">
            Cargando...
        </div>
    </div>
</div>

<div
    id="evaluationEditContext"
    class="hidden"
    data-course-id="{{ $courseId }}"
    data-evaluation-id="{{ $evaluationId }}"
    data-autosave-url="{{ route('backoffice.evaluations.autosave', [$courseId, $evaluationId]) }}"
    data-publish-url="{{ route('backoffice.evaluations.publish', [$courseId, $evaluationId]) }}"
    data-view-url="{{ route('backoffice.evaluations.view', [$courseId, $evaluationId]) }}"
    data-duplicating="{{ session('duplicating') ? 'true' : 'false' }}">
</div>

<template id="evaluationEditPayload">@json([
    'evaluacion' => $evaluation ?? null,
    'preguntas' => $preguntas ?? [],
])</template>

<div id="autosaveStatus"
class="fixed bottom-6 right-6 px-3 py-2 rounded-lg text-sm shadow-lg
bg-neutral-800 text-white opacity-0 transition-all duration-300">
Guardado
</div>

<div class="max-w-4xl mx-auto">
    <div class="page-header mb-4">

        <a href="{{ route('backoffice.evaluations.show', $courseId) }}"
        class="text-sm text-blue-600 hover:underline block mb-1">
            ← Volver
        </a>

        <div class="flex justify-between items-start gap-4">

            <div class="flex-1">
                <textarea
    id="evaluationTitle"
    class="text-3xl font-bold resize-none outline-none border-none w-full overflow-hidden"
    rows="1"
    placeholder="Nombre de la evaluación"
></textarea>

                <div class="text-sm text-gray-500 mt-1">
                    Evaluación
                    @if(($evaluation['publicada'] ?? 0))
                        <span class="ml-2 text-green-600 font-medium">• Publicada</span>
                    @else
                        <span class="ml-2 text-gray-400">• Borrador</span>
                    @endif
                </div>
            </div>

            <div class="flex gap-2">
                 <a
                    id="previewEvaluation"
                    href="{{ route('backoffice.evaluations.view', [$courseId, $evaluationId]) }}?from=edit"
                    class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 text-gray-700 hover:bg-gray-50">
                    Vista previa
                </a>
                <button
                    id="publishEvaluation"
                    @class([
                        'px-4 py-2 rounded-lg text-white text-sm font-medium transition',
                        'bg-gray-500 cursor-not-allowed' => ($evaluation['publicada'] ?? 0),
                        'bg-green-600 hover:bg-green-700' => !($evaluation['publicada'] ?? 0),
                    ])
                    {{ ($evaluation['publicada'] ?? 0) ? 'disabled' : '' }}
                >
                    {{ ($evaluation['publicada'] ?? 0) ? 'Publicado' : 'Publicar' }}
                </button>
            </div>

        </div>

    </div>

    @php
        $approvalScore = $evaluation['pass_score'] ?? 0;
    @endphp

    @php
        $approvalScore = $evaluation['pass_score'] ?? 0;
        $weightPercent = $evaluation['weight_percent'] ?? 0;
        $timeMinutes = $evaluation['time_minutes'] ?? 0;
    @endphp

    <div class="page-shell space-y-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

            <div class="bg-white rounded-2xl p-4 card-shadow">
                <label class="block text-sm font-medium mb-2">Peso (%)</label>
                <input id="evaluationWeight"
                    type="number"
                    min="0.01"
                    max="100"
                    step="0.01"
                    value="{{ $weightPercent }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2">
            </div>

            <div class="bg-white rounded-2xl p-4 card-shadow">
                <label class="block text-sm font-medium mb-2">Puntaje mínimo para aprobar</label>
                <input id="approvalScore"
                    type="number"
                    min="0"
                    max="20"
                    step="1"
                    value="{{ $approvalScore }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2">
            </div>

            <div class="bg-white rounded-2xl p-4 card-shadow">
                <label class="block text-sm font-medium mb-2">Tiempo (minutos)</label>
                <input id="timeMinutes"
                    type="number"
                    min="1"
                    step="1"
                    value="{{ $timeMinutes }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2">
            </div>

        </div>

         <p id="evaluationScoreHint" class="text-sm text-blue-600 text-right font-medium">
            La suma de puntajes de todas las preguntas debe ser exactamente 20 puntos para poder publicar.
        </p>
    </div>

    <!-- CONTENIDO -->
    <div class="page-shell">

    <div class="editor-wrapper">

    <div class="add-question-toolbar top">
    <span class="text-sm text-gray-500">Agregar pregunta:</span>

    <button type="button" class="btn-type single" data-add-question-type="single">
    <i class="fa-regular fa-circle-dot"></i> Opción única
    </button>

    <button type="button" class="btn-type boolean" data-add-question-type="boolean">
    <i class="fa-regular fa-circle-check"></i> Verdadero/Falso
    </button>

    <button type="button" class="btn-type multiple" data-add-question-type="multiple">
    <i class="fa-regular fa-square-check"></i> Opción múltiple
    </button>
    </div>

    <div id="emptyState" class="empty-state">
    <div class="empty-title">Comienza agregando tu primera pregunta</div>
    <div class="empty-subtitle">
    Usa los botones de arriba para seleccionar el tipo de pregunta
    </div>
    </div>

    <div id="questionsContainer" class="questions-container"></div>

    <div id="bottomToolbar" class="add-question-toolbar bottom" style="display:none;">
    <span class="text-sm text-gray-500">Agregar pregunta:</span>

    <button type="button" class="btn-type single" data-add-question-type="single">
    <i class="fa-regular fa-circle-dot"></i> Opción única
    </button>

    <button type="button" class="btn-type boolean" data-add-question-type="boolean">
    <i class="fa-regular fa-circle-check"></i> Verdadero/Falso
    </button>

    <button type="button" class="btn-type multiple" data-add-question-type="multiple">
    <i class="fa-regular fa-square-check"></i> Opción múltiple
    </button>
    </div>

    </div>
    </div>
</div>
<div id="appErrorModal"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">

    <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">

        <div class="text-lg font-semibold mb-3 text-red-600">
            Validación
        </div>

        <div id="appErrorMessage"
             class="text-sm text-slate-700 mb-5 whitespace-pre-line">
        </div>

        <div class="flex justify-end">
            <button id="appErrorOk"
                    class="px-4 py-2 rounded-lg bg-red-600 text-white">
                OK
            </button>
        </div>

    </div>
</div>

@endsection

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

@vite([
'resources/js/evaluation-edit.js'
])
