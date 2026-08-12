@extends('layouts.main')

@section('title','Editar trabajo')

@section('content')

<link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">


<div id="autosaveStatus"
class="fixed bottom-6 right-6 px-3 py-2 rounded-lg text-sm shadow-lg
bg-neutral-800 text-white opacity-0 transition-all duration-300">
Guardado
</div>

<div class="max-w-5xl mx-auto">
    <div class="page-header mb-4">

        <a href="{{ route('backoffice.evaluations.show', $courseId) }}"
        class="text-sm text-blue-600 hover:underline block mb-1">
            Volver
        </a>

        <div class="flex justify-between items-start gap-4">

            <div class="flex-1">
                <textarea
                    id="evaluationTitle"
                    class="text-3xl font-bold resize-none outline-none border-none w-full overflow-hidden"
                    rows="1"
                    placeholder="Nombre del trabajo"></textarea>

                <div class="text-sm text-gray-500 mt-1">
                    Trabajo
                    @if(($evaluation['publicada'] ?? false))
                        <span class="ml-2 text-green-600 font-medium">• Publicada</span>
                    @else
                        <span class="ml-2 text-gray-400">• Borrador</span>
                    @endif
                </div>
            </div>

            <div class="flex gap-2">
                <a
                    href="{{ route('backoffice.evaluations.work.view', [$courseId, $evaluationId]) }}?from=edit"
                    class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 text-gray-700 hover:bg-gray-50">
                    Vista previa
                </a>
                <button
                    id="publishEvaluation"
                    @class([
                        'px-4 py-2 rounded-lg text-white text-sm font-medium transition',
                        'bg-gray-500 cursor-not-allowed' => ($evaluation['publicada'] ?? false),
                        'bg-green-600 hover:bg-green-700' => !($evaluation['publicada'] ?? false),
                    ])
                    {{ ($evaluation['publicada'] ?? false) ? 'disabled' : '' }}>
                    {{ ($evaluation['publicada'] ?? false) ? 'Publicado' : 'Publicar' }}
                </button>
            </div>

        </div>

    </div>

    <div class="page-shell space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">

            <div class="bg-white rounded-2xl p-4 card-shadow">
                <label class="block text-sm font-medium mb-2">Peso (%)</label>
                <input id="evaluationWeight"
                    type="number"
                    min="0.01"
                    max="100"
                    step="0.01"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2">
            </div>
            <div class="bg-white rounded-2xl p-4 card-shadow">
                <label class="block text-sm font-medium mb-2">Puntaje mínimo para aprobar</label>
                <input id="evaluationPassScore" type="number" min="0" max="100"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2">
            </div>

        </div>

        <div class="space-y-4">
            <div class="bg-white rounded-2xl p-5 card-shadow">
                <label class="block text-sm font-medium mb-2">Descripción</label>
                <div class="w-full">
                    <div id="workDescriptionEditor" class="rounded-xl border border-gray-300 overflow-hidden bg-white"></div>
                </div>
            </div>

             <div class="bg-white rounded-2xl p-5 card-shadow">
                <div class="flex flex-col gap-2 mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Rúbrica</h2>
                    <p class="text-sm text-slate-500">
                        Define los criterios y puntajes con los que se evaluará este trabajo.
                    </p>
                </div>

                <div class="rounded-xl border border-slate-200 overflow-hidden bg-white">

                    <!-- Header -->
                    <div class="hidden md:grid grid-cols-[minmax(0,0.95fr)_minmax(0,1.45fr)_120px_44px] gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        <div>Nombre</div>
                        <div>Descripcion</div>
                        <div>Puntaje</div>
                        <div></div>
                    </div>

                    <!-- Empty state -->
                    <div id="rubricEmptyState" class="px-6 py-10">
                        <div class="mx-auto flex max-w-md flex-col items-center text-center">
                            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6M9 16h6M9 8h6M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z" />
                                </svg>
                            </div>

                            <h3 class="text-base font-semibold text-slate-900">Empieza creando tu rúbrica</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-500">
                                Agrega criterios y puntajes para que la evaluación del trabajo sea clara, ordenada y consistente.
                            </p>

                            <button
                                type="button"
                                id="addCriterionBtn"
                                class="mt-5 inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                                Agregar primer criterio
                            </button>
                        </div>
                    </div>

                    <!-- Lista -->
                    <div id="rubricCriteria" class="divide-y divide-slate-200"></div>

                    <!-- Botón para seguir agregando -->
                    <div class="p-4 border-t border-slate-200">
                        <button
                            type="button"
                            id="addCriterionBtnSecondary"
                            class="w-full rounded-lg py-3 text-sm font-medium bg-blue-50 text-blue-700 border border-dashed border-blue-300 hover:bg-blue-100 hover:border-blue-400 transition"
                            >
                            + Agregar criterio
                        </button>
                    </div>

                </div>

                <!-- Total -->
                <div class="mt-4 flex flex-col items-end gap-2">
                    <div class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600">
                        Total:
                        <span id="workScoreDisplay" class="ml-1 font-semibold text-slate-900">0</span>
                        <span class="ml-1">pts</span>
                    </div>
                    <p id="workScoreHint" class="text-sm text-slate-500 text-right">
                        El puntaje máximo de la rúbrica debe sumar exactamente 20 puntos para poder publicar.
                    </p>
                </div>
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

<div
    id="workEvaluationContext"
    class="hidden"
    data-save-url="{{ route('backoffice.evaluations.work.save', [$courseId, $evaluationId]) }}"
    data-publish-url="{{ route('backoffice.evaluations.publish', [$courseId, $evaluationId]) }}"
    data-view-url="{{ route('backoffice.evaluations.work.view', [$courseId, $evaluationId]) }}">
</div>

<script id="workEvaluationPayload" type="application/json">
@json([
    'evaluacion' => $evaluation ?? null,
    'trabajo' => $trabajo ?? null,
])
</script>

<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>

@endsection

@vite([
    'resources/js/evaluation-work-edit.js'
])
