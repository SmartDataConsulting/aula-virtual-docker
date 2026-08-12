@extends('layouts.main')

@section('title','Evaluaciones')

@section('content')

<div id="evaluationsPageContext"
     class="hidden"
     data-course-id="{{ $courseId }}">
</div>

<div id="appLoadingOverlay"
     class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/50">
    <div class="bg-white px-6 py-4 rounded-xl shadow-xl text-center">
        <div class="text-lg font-semibold" id="loadingText">
            Cargando...
        </div>
    </div>
</div>

<div class="page-header">
    <h1>Evaluaciones</h1>
    <p class="text-sm text-gray-500">
        {{ $courseName }}
    </p>
</div>

<div class="page-shell max-w-6xl mx-auto px-4">

    <div class="flex justify-between items-center mb-6">
        <div></div>

        <button
            type="button"
            id="openCreateEvaluationModalBtn"
            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-sm font-medium transition">
            Nueva evaluación
        </button>
    </div>

    @if($evaluations->isEmpty())

        <div class="bg-white rounded-2xl p-6 text-sm text-gray-500 card-shadow">
            No hay evaluaciones registradas.
        </div>

    @else

       <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">

            @foreach($evaluations as $e)
                @php
                    $passScore = $e['pass_score'] ?? null;

                    $weightText = isset($e['weight_percent']) && $e['weight_percent'] !== null
                        ? rtrim(rtrim(number_format((float) $e['weight_percent'], 2, '.', ''), '0'), '.') . '%'
                        : 'No definido';

                    $deadlineText = !empty($e['deadline'])
                        ? \Illuminate\Support\Carbon::parse($e['deadline'])->format('d/m/Y h:i A')
                        : 'No definida';
                @endphp

                <div class="bg-white rounded-2xl border border-slate-200 p-4 w-full card-shadow hover:shadow-md hover:-translate-y-1 transition">
                    {{-- Cabecera --}}
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                                    {{ $e['type'] }}
                                </span>

                                @if($e['published'])
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-700">
                                        Publicada
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-700">
                                        Borrador
                                    </span>
                                @endif
                            </div>

                            <h3 class="text-lg font-semibold leading-snug text-slate-900">
                                {{ $e['name'] }}
                            </h3>
                        </div>
                    </div>

                    {{-- Resumen principal --}}
                    <div class="rounded-xl bg-slate-50 border border-slate-100 px-4 py-3 mb-3">

                        <div class="grid grid-cols-1 gap-y-1.5 text-sm">

                            <!-- Fila 1 -->
                            <div class="flex justify-between">
                                <span class="text-slate-500">Peso</span>
                                <span class="font-semibold text-slate-900">
                                    {{ $weightText }}
                                </span>
                            </div>

                            <!-- Fila 2 -->
                            <div class="flex justify-between">
                                <span class="text-slate-500">Puntaje mín. aprobar:</span>
                                <span class="font-semibold text-slate-900">
                                    {{ $passScore !== null ? $passScore : '—' }}
                                </span>
                            </div>

                            @if(($e['type_id'] ?? 0) < 3)
                                <div class="flex justify-between">
                                    <span class="text-slate-500">
                                        {{ 'Duración:' }}
                                    </span>
                                    <span class="font-semibold text-slate-900">
                                        {{ $e['time_minutes'] ?? '—' }} min
                                    </span>
                                </div>
                            @else
                                <div class="flex justify-between opacity-0" aria-hidden="true">
                                    <span class="text-slate-500">Duración:</span>
                                    <span class="font-semibold text-slate-900">—</span>
                                </div>
                            @endif

                        </div>

                    </div>

                
                    {{-- Acciones --}}
                    <div class="flex justify-end gap-2 pt-1">
                        <button
                            type="button"
                            data-duplicate-evaluation-id="{{ $e['id'] }}"
                            data-duplicate-type-id="{{ $e['type_id'] ?? 0 }}"
                            class="px-3 py-2 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 text-sm font-medium transition">
                            Duplicar
                        </button>

                        <a
                            href="{{ ($e['type_id'] ?? null) >= 3
                                ? ($e['published']
                                    ? route('backoffice.evaluations.work.view', [$courseId, $e['id']])
                                    : route('backoffice.evaluations.work.edit', [$courseId, $e['id']]))
                                : ($e['published']
                                    ? route('backoffice.evaluations.view', [$courseId, $e['id']])
                                    : route('backoffice.evaluations.edit', [$courseId, $e['id']]))
                            }}"
                            class="px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">
                            {{ $e['published'] ? 'Ver' : 'Editar' }}
                        </a>
                    </div>
                </div>
            @endforeach

        </div>

    @endif

</div>

@endsection

@vite([
    'resources/js/evaluations.js'
])

<x-form-modal
    id="createEvaluationModal"
    title="Nueva evaluación"
    formId="createEvaluationForm"
    action="{{ route('backoffice.evaluations.store', $courseId) }}"
    method="POST"
    closeFn="closeCreateEvaluationModal()"
    submitLabel="Crear"
>
    <div>
        <label class="block text-sm font-medium">Nombre</label>
        <input type="text" name="nombre"
            class="w-full border rounded-lg px-3 py-2" required>
    </div>

    <div>
        <label class="block text-sm font-medium">Tipo</label>
        <select id="tipoEvaluacion"
                name="tipo"
                class="w-full border rounded-lg px-3 py-2"
                required>
            <option value="">Cargando...</option>
        </select>
    </div>

    <div id="sharedRequiredFields" class="hidden space-y-4">
        <div>
            <label class="block text-sm font-medium">
                Peso (%)
            </label>
            <input type="number"
                name="peso"
                min="0.01"
                max="100"
                step="0.01"
                class="w-full border rounded-lg px-3 py-2"
                placeholder="Ej: 20">
        </div>
        <div>
            <label class="block text-sm font-medium">
                Puntaje mínimo para aprobar
            </label>
            <input type="number"
                name="puntaje_aprobacion"
                min="1"
                max="20"
                class="w-full border rounded-lg px-3 py-2"
                placeholder="Ej: 11">
        </div>
    </div>

    <div id="examOnlyFields" class="hidden space-y-4">
        <div>
            <label class="block text-sm font-medium">
                Tiempo (minutos)
            </label>
            <input type="number"
                name="tiempo_minutos"
                min="1"
                class="w-full border rounded-lg px-3 py-2"
                placeholder="Ej: 30">
        </div>


    </div>
    <div id="workOnlyFields" class="hidden space-y-4"></div>
    <div id="workOnlyMessage" style="display:none !important"
        class="hidden rounded-lg bg-amber-50 px-3 py-3 text-sm text-amber-800">
        Los trabajos se crean con datos básicos y luego se completan en su pantalla de edición.
    </div>

</x-form-modal>
