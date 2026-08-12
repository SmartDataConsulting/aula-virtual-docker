<div class="space-y-6" data-notes-panel data-course-id="{{ $courseId }}">
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 shadow-sm">
                    Calificaciones
                </span>
                <h1 class="mt-3 text-3xl font-bold text-slate-800">Mis Notas</h1>
                <p class="mt-2 text-sm text-slate-500">
                    Consulta el detalle de tus evaluaciones y calificaciones.
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Evaluaciones</div>
                    <div data-notes-count class="mt-2 text-2xl font-bold text-slate-800">--</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Nota Final</div>
                    <div data-weighted-average class="mt-2 text-2xl font-bold text-slate-800">--</div>
                </div>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div data-notes-loader class="flex min-h-[320px] flex-col items-center justify-center gap-4 p-8 text-center">
            <div class="h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-blue-600"></div>
            <div>
                <div class="text-base font-semibold text-slate-700">Cargando notas</div>
                <p class="mt-1 text-sm text-slate-500">Estamos consultando tus evaluaciones del curso.</p>
            </div>
        </div>

        <div data-notes-error class="hidden p-6">
            <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-6 text-center">
                <div class="text-base font-semibold text-red-700">No se pudieron cargar tus notas</div>
                <p data-notes-error-message class="mt-2 text-sm text-red-600">
                    Intenta nuevamente en unos momentos.
                </p>
                <button
                    data-retry-notes
                    type="button"
                    class="mt-4 inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">
                    Reintentar
                </button>
            </div>
        </div>

        <div data-notes-empty class="hidden p-6">
            <div class="flex min-h-[320px] flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-6 text-center">
                <div class="text-4xl">📝</div>
                <div class="mt-4 text-lg font-semibold text-slate-700">Todavía no hay notas registradas</div>
                <p class="mt-2 max-w-xl text-sm text-slate-500">
                    Cuando tus evaluaciones sean calificadas, aquí podrás revisar el detalle de cada una.
                </p>
            </div>
        </div>

        <div data-notes-content class="hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Evaluación</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Tipo</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Fecha</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Nota</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Peso %</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Acción</th>
                        </tr>
                    </thead>
                    <tbody data-notes-table-body class="divide-y divide-slate-100 bg-white"></tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 bg-slate-50 px-6 py-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Resumen final</div>
                        <div class="mt-1 text-sm text-slate-500">
                            Nota calculado en base al peso de cada evaluación registrada.
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Nota Final</div>
                        <div data-weighted-average-footer class="mt-1 text-2xl font-bold text-slate-800">--</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
