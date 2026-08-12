@extends('mis-cursos.partials.layout')

@section('title', ($evaluation['nombre'] ?? $evaluation['name'] ?? 'Trabajo'))

@section('course-content')
@php
    $rubrica = $trabajo['rubrica'] ?? [];
    $criterios = $rubrica['criterios'] ?? [];
    $entregaFinalizada = (bool) ($entrega['finalizada'] ?? false);
    $puedeEditar = (bool) ($entrega['puede_editar'] ?? false);
    $fueraDePlazo = (bool) ($entrega['fuera_de_plazo'] ?? false);
    $maxArchivos = (int) ($entrega['max_archivos'] ?? 5);
    $maxFileSizeMb = (int) ($entrega['max_file_size_mb'] ?? 50);
    $allowedExtensions = array_values(array_filter(array_map(
        static fn ($extension) => trim(strtolower((string) $extension)),
        (array) ($entrega['allowed_extensions'] ?? ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'txt', 'csv', 'odt', 'ods', 'odp', 'json', 'yml', 'yaml'])
    )));
    $allowedAccept = implode(',', array_map(static fn ($extension) => '.' . $extension, $allowedExtensions));
    $allowedLabel = implode(', ', array_map(static fn ($extension) => '.' . $extension, $allowedExtensions));
    $formatGroups = 'Documentos, hojas de calculo, presentaciones, imagenes, comprimidos, JSON y YAML';
    $deadlineStateText = null;
    $deadlineStateClass = 'bg-slate-100 text-slate-700';

    if (!empty($trabajo['fecha_limite'])) {
        try {
            $deadline = \Carbon\CarbonImmutable::parse($trabajo['fecha_limite'], config('app.timezone', 'America/Lima'));
            $now = \Carbon\CarbonImmutable::now(config('app.timezone', 'America/Lima'));

            if ($deadline->isPast()) {
                $deadlineStateText = 'Plazo vencido';
                $deadlineStateClass = 'bg-rose-100 text-rose-700';
            } elseif ($deadline->isSameDay($now)) {
                $deadlineStateText = 'Vence hoy';
                $deadlineStateClass = 'bg-amber-100 text-amber-700';
            } else {
                $daysToDeadline = max(1, (int) ceil($now->diffInHours($deadline) / 24));
                $deadlineStateText = 'Vence en ' . $daysToDeadline . ' ' . ($daysToDeadline === 1 ? 'dia' : 'dias');
                $deadlineStateClass = $daysToDeadline <= 2 ? 'bg-amber-100 text-amber-700' : 'bg-indigo-50 text-indigo-700';
            }
        } catch (\Throwable $exception) {
            $deadlineStateText = null;
        }
    }

    $descripcionTrabajo = (string) ($trabajo['descripcion'] ?? '');
    $descripcionTrabajoHtml = trim($descripcionTrabajo) !== ''
        ? html_entity_decode($descripcionTrabajo, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : '<p>No hay descripción registrada.</p>';
    $evaluationTypeLabel = trim((string) ($evaluation['tipo_descripcion'] ?? $evaluation['type'] ?? 'Trabajo'));
    $evaluationTypeLabel = str_ireplace('Trabajo practico', 'Trabajo práctico', $evaluationTypeLabel);
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <a class="text-sm font-medium text-slate-500 transition hover:text-indigo-600"
               href="{{ route('mis-cursos.show', [$course->id, $session->id ?? null]) }}?tab=evaluations">
                Volver a evaluaciones
            </a>
            <h1 class="mt-3 text-2xl font-semibold tracking-tight text-slate-900">
                {{ $evaluation['nombre'] ?? $evaluation['name'] ?? 'Trabajo' }}
            </h1>
            <p class="mt-2 max-w-3xl text-xs leading-6 text-slate-500">
                Revisa las indicaciones, adjunta tus archivos y guarda tus cambios antes de enviar la entrega.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
                {{ $evaluationTypeLabel }}
            </span>
            @if($entregaFinalizada)
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-emerald-700">
                    Entrega finalizada
                </span>
            @elseif($fueraDePlazo)
                <span class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-rose-700">
                    Vencida
                </span>
            @elseif($puedeEditar)
                <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-amber-700">
                    Borrador editable
                </span>
            @else
                <span class="inline-flex items-center rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-700">
                    Solo lectura
                </span>
            @endif
        </div>
    </div>

    <div id="studentWorkAlert" class="hidden rounded-2xl border px-4 py-3 text-sm"></div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.9fr)]">
        <div class="space-y-3">
            <section class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_18px_50px_rgba(15,23,42,0.08)]">
                <div class="border-b border-slate-200 bg-[linear-gradient(180deg,#f3f4f6,#eef2f7)] px-6 py-4 text-slate-900">
                    <h2 class="text-lg font-semibold text-slate-900">
                        Detalle del trabajo
                    </h2>
                     
                </div>

                <div class="space-y-3 px-6 py-4">
                    <div class="grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <span class="block text-xs font-medium text-slate-500">Fecha límite</span>
                            <strong class="mt-1 block font-semibold text-slate-800">{{ $trabajo['fecha_limite_label'] ?? 'No definida' }}</strong>
                            @if($deadlineStateText)
                                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $deadlineStateClass }}">
                                    {{ $deadlineStateText }}
                                </span>
                            @endif
                        </div>
                        <div>
                            <span class="block text-xs font-medium text-slate-500">Puntaje máximo</span>
                            <strong class="mt-1 block font-semibold text-slate-800">{{ $trabajo['puntaje_max'] ?? '0' }} pts</strong>
                        </div>
                        <div>
                            <span class="block text-xs font-medium text-slate-500">Puntaje mínimo para aprobar</span>
                            <strong class="mt-1 block font-semibold text-slate-800">{{ $evaluation['pass_score'] ?? '0' }} pts</strong>
                        </div>
                        <div>
                            <span class="block text-xs font-medium text-slate-500">Peso en el curso</span>
                            <strong class="mt-1 block font-semibold text-slate-800">{{ $evaluation['peso'] ?? $evaluation['weight_percent'] ?? '—' }}%</strong>
                        </div>
                    </div>
                    <div class="pb-3 border-b border-slate-200">
                        <h2 class="text-lg font-semibold text-slate-900">Descripción</h2>
                        <div class="work-description-content prose mt-2 max-w-none text-sm text-slate-700 prose-p:my-2 prose-li:my-0.5">
                            {!! $descripcionTrabajoHtml !!}
                        </div>
                    </div>

                    <div class="pt-1">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-slate-900">{{ $rubrica['nombre'] ?? 'Rúbrica general' }}</h2>
                                <p class="mt-1 text-xs text-slate-500">Estos criterios se usaran para revisar tu entrega.</p>
                            </div>
                        </div>

                        @if(empty($criterios))
                        <div class="mt-3 rounded-2xl border border-dashed border-slate-300 px-4 py-4 text-xs text-slate-500">
                            No hay criterios registrados.
                        </div>
                    @else
                            <div class="mt-3 grid gap-3">
                                @foreach($criterios as $index => $criterio)
                                    @php
                                        $criterionName = $criterio['nombre'] ?? $criterio['descripcion'] ?? '';
                                        $criterionDescription = $criterio['descripcion'] ?? '';
                                    @endphp
                                    <article class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                        <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                            <div class="min-w-0">
                                                <h3 class="text-sm font-semibold text-slate-800">{{ $criterionName }}</h3>
                                                @if($criterionDescription && $criterionDescription !== $criterionName)
                                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ $criterionDescription }}</p>
                                                @endif
                                            </div>
                                            <div class="shrink-0 rounded-full bg-white px-3 py-1 text-right text-sm font-semibold text-slate-800 ring-1 ring-slate-200">
                                                {{ $criterio['puntaje_max'] ?? '0' }} pts
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        </div>

        <aside class="space-y-4">
            <section class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_18px_50px_rgba(15,23,42,0.08)]">
                <div class="border-b border-slate-200 bg-[linear-gradient(180deg,#f3f4f6,#eef2f7)] px-6 py-4 text-slate-900">
                    <h2 class="text-lg font-semibold text-slate-900">Tu entrega</h2>
                </div>

                <div class="space-y-5 px-5 py-5">
                    <div id="studentWorkStatusCard" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm text-slate-500">Estado</div>
                                <div id="studentWorkStatusText" class="mt-2 text-sm font-semibold text-slate-700"></div>
                            </div>
                            <div id="studentWorkStatusBadge" class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide"></div>
                        </div>
                        <div class="mt-4 grid gap-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm text-slate-500">Archivos activos</span>
                                <strong id="studentWorkFileCounter" class="text-sm font-semibold text-slate-700">0 / {{ $maxArchivos }}</strong>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm text-slate-500">Fecha de entrega</span>
                                <strong id="studentWorkSubmittedAt" class="text-sm font-semibold text-slate-700">Todavia no enviaste tu entrega</strong>
                            </div>
                        </div>
                        <p id="studentWorkDeadlineNote" class="hidden mt-3 text-xs text-slate-500">
                            Entrega cerrada por plazo vencido
                        </p>
                    </div>

                    @php
                        $teacherObservation = trim((string) ($entrega['feedback'] ?? $entrega['observacion_docente'] ?? $entrega['retroalimentacion'] ?? $entrega['observacion'] ?? $entrega['comentario'] ?? ''));
                        $studentScore = isset($entrega['score']) && is_numeric($entrega['score']) ? (float) $entrega['score'] : null;
                        $studentMaxScore = isset($entrega['max_score']) && is_numeric($entrega['max_score']) ? (float) $entrega['max_score'] : ($trabajo['puntaje_max'] ?? null);
                    @endphp

                    @if($studentScore !== null || $teacherObservation !== '')
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-700">
                            <div class="mb-3 border-b border-slate-200 pb-3">
                                <h2 class="text-sm font-semibold text-slate-900">Resultado</h2>
                            </div>
                            @if($studentScore !== null)
                                <div class="mb-3">
                                    <span class="block text-sm text-slate-500">Nota final</span>
                                    <strong class="mt-1 block text-lg text-slate-900">{{ number_format($studentScore, 2, '.', '') }}{{ $studentMaxScore !== null ? ' / ' . number_format($studentMaxScore, 2, '.', '') . ' pts' : '' }}</strong>
                                </div>
                            @endif
                            @if($teacherObservation !== '')
                                <div>
                                    <span class="block text-sm text-slate-500">Observación del docente</span>
                                    <p class="mt-2 text-sm text-slate-700 whitespace-pre-line">{{ $teacherObservation }}</p>
                                </div>
                            @endif
                        </section>
                    @endif

                    <div id="studentWorkBlockedNotice" class="hidden rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3">
                        <div class="text-sm font-semibold text-rose-700">No puedes enviar esta entrega</div>
                    </div>

                    <section id="studentWorkNextStep" class="rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Tu siguiente paso</div>
                        <h3 id="studentWorkNextStepTitle" class="mt-2 text-sm font-semibold text-slate-900">Agrega tus archivos</h3>
                        <p id="studentWorkNextStepText" class="mt-1 text-xs leading-5 text-slate-600">
                            Sube al menos un archivo para poder enviar tu trabajo.
                        </p>
                        <button
                            id="studentWorkNextStepAction"
                            type="button"
                            class="mt-3 inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                            Agregar archivos
                        </button>
                    </section>

                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium text-slate-700">Adjuntos</div>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    Maximo {{ $maxArchivos }} archivos, {{ $maxFileSizeMb }} MB cada uno.
                                </p>
                                <details class="mt-1 text-xs text-slate-500">
                                    <summary class="cursor-pointer text-indigo-700">Ver formatos permitidos</summary>
                                    <p class="mt-1">{{ $formatGroups }}.</p>
                                    <p class="mt-1 break-words">{{ $allowedLabel }}.</p>
                                </details>
                            </div>
                            <label id="studentWorkFileTrigger"
                                   for="studentWorkFiles"
                                   class="inline-flex min-h-11 cursor-pointer items-center justify-center rounded-full bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">
                                Agregar archivos
                            </label>
                        </div>

                        <input
                            id="studentWorkFiles"
                            type="file"
                            class="hidden"
                            accept="{{ $allowedAccept }}"
                            multiple>

                        <div id="studentWorkFilesList" class="space-y-3"></div>
                        <div id="studentWorkRemovedList" class="space-y-2"></div>
                    </div>

                    <div class="space-y-2">
                        <label for="studentWorkObservation" class="text-sm text-slate-500">
                            Observación para el docente
                        </label>
                        <textarea
                            id="studentWorkObservation"
                            rows="3"
                            class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                            placeholder="Puedes dejar una nota breve sobre tu entrega..."
                        >{{ $entrega['observacion_alumno'] ?? '' }}</textarea>
                    </div>

                    <div class="grid gap-3 pt-1">
                        <button
                            id="studentWorkSaveBtn"
                            type="button"
                            class="inline-flex items-center justify-center rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                            Guardar cambios
                        </button>
                        <button
                            id="studentWorkFinalizeBtn"
                            type="button"
                            class="inline-flex items-center justify-center rounded-2xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-slate-50">
                            Enviar entrega
                        </button>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>

<div
    id="studentWorkContext"
    class="hidden"
    data-save-url="{{ route('mis-cursos.evaluaciones.trabajo.save', [$courseId, $sessionId, $evaluationId]) }}"
    data-finalize-url="{{ route('mis-cursos.evaluaciones.trabajo.finalize', [$courseId, $sessionId, $evaluationId]) }}"
    data-download-url-template="{{ route('mis-cursos.evaluaciones.trabajo.attachments.download', [$courseId, $sessionId, $evaluationId, 'attachment' => '__ATTACHMENT__']) }}"
    data-allowed-extensions="{{ implode(',', $allowedExtensions) }}"
    data-max-file-size-mb="{{ $maxFileSizeMb }}"
    data-csrf-token="{{ csrf_token() }}">
</div>

<script type="application/json" id="studentWorkPayload">
{!! json_encode([
    'evaluacion' => $evaluation ?? null,
    'trabajo' => $trabajo ?? null,
    'entrega' => $entrega ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>

@endsection

@push('scripts')
@vite([
    'resources/js/ui.js',
    'resources/js/chat.js',
    'resources/js/course-workspace.js',
    'resources/js/evaluation-work-student.js',
])
@endpush
