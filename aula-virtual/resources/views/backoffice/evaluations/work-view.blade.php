@extends('layouts.main')

@section('title','Trabajo')

@section('content')

@php
$from = request()->query('from');
$rubrica = $trabajo['rubrica'] ?? [];
$criterios = $rubrica['criterios'] ?? [];
$totalPuntaje = collect($criterios)->sum(fn($criterio) => (float) ($criterio['puntaje_max'] ?? 0));
$descripcionTrabajo = (string) ($trabajo['descripcion'] ?? '');
$descripcionTrabajoHtml = trim($descripcionTrabajo) !== ''
    ? html_entity_decode($descripcionTrabajo, ENT_QUOTES | ENT_HTML5, 'UTF-8')
    : '<p>No hay descripcion registrada.</p>';
@endphp

<div class="page-header mb-4">

@if($from === 'edit')
<a href="{{ route('backoffice.evaluations.work.edit', [$courseId, $evaluationId]) }}"
   class="text-sm text-blue-600 hover:underline block mb-1">
    Volver
</a>
@else
<a href="{{ route('backoffice.evaluations.show', $courseId) }}"
   class="text-sm text-blue-600 hover:underline block mb-1">
    Volver
</a>
@endif

    <div class="flex-1">
        <div class="text-3xl font-bold">
            {{ $evaluation['nombre'] ?? $evaluation['name'] ?? '' }}
        </div>

        <div class="text-sm text-gray-500 mt-1">
            Trabajo
        </div>
    </div>

</div>

<div class="page-shell space-y-6">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Puntaje máximo total</div>
            <div class="font-medium text-gray-900">{{ rtrim(rtrim(number_format($totalPuntaje, 2, '.', ''), '0'), '.') }}</div>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Puntaje mínimo para aprobar</div>
            <div class="font-medium text-gray-900">
                {{ isset($evaluation['pass_score']) ? $evaluation['pass_score'] : 'No definido' }}
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Peso</div>
            <div class="font-medium text-gray-900">{{ $evaluation['peso'] ?? $evaluation['weight_percent'] ?? 'No definido' }}</div>
        </div>
    </div>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200">
        <h2 class="text-lg font-semibold mb-4">Descripcion</h2>
        <div class="work-description-content prose max-w-3xl text-gray-800">
            {!! $descripcionTrabajoHtml !!}
        </div>
    </div>

    <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200">
        <h2 class="text-lg font-semibold mb-4">Rubrica</h2>

        @if(empty($criterios))
            <div class="text-sm text-gray-500">No hay criterios registrados.</div>
        @else
            <div class="space-y-3">
                @foreach($criterios as $index => $criterio)
                @php
                    $criterionName = $criterio['nombre'] ?? $criterio['descripcion'] ?? '';
                    $criterionDescription = $criterio['descripcion'] ?? '';
                @endphp
                <div class="grid grid-cols-1 md:grid-cols-[1fr_140px] gap-4 rounded-xl border border-gray-200 px-4 py-4">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">Criterio {{ $index + 1 }}</div>
                        <div class="font-medium text-gray-900">{{ $criterionName }}</div>
                        @if($criterionDescription && $criterionDescription !== $criterionName)
                            <div class="mt-1 text-sm text-gray-600">{{ $criterionDescription }}</div>
                        @endif
                    </div>

                    <div class="md:text-right">
                        <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">Puntaje</div>
                        <div class="font-semibold text-gray-900">{{ $criterio['puntaje_max'] ?? '0' }}</div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@endsection
