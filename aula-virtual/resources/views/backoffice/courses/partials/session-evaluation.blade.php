<div class="space-y-5">
    @php
        $allSessions = collect($sessions ?? [])->values();
        $totalSessions = $allSessions->count();
        $middleSessionNumber = $totalSessions > 0 ? (int) ceil($totalSessions / 2) : null;
        $finalSessionNumber = $totalSessions > 0 ? (int) ($allSessions->last()->number ?? $totalSessions) : null;
        $planSessions = collect($evaluationPlan['sessions'] ?? []);
        $planMilestones = $planSessions
            ->flatMap(fn ($planSession) => collect($planSession['milestones'] ?? [])
                ->map(fn ($milestone) => array_merge($milestone, [
                    'session_number' => $planSession['session_number'] ?? null,
                ])))
            ->values();
        $evaluationOptions = collect($session->evaluaciones_asignadas ?? [])
            ->merge($session->evaluaciones_disponibles ?? [])
            ->merge($planMilestones->map(fn ($milestone) => [
                'id' => $milestone['evaluation_id'] ?? null,
                'nombre' => $milestone['name'] ?? $milestone['nombre'] ?? 'Evaluación',
                'tipo' => $milestone['type'] ?? $milestone['tipo'] ?? null,
            ]))
            ->filter(fn ($item) => !empty($item['id']))
            ->unique(fn ($item) => (int) ($item['id'] ?? 0))
            ->values();
    @endphp

    <div id="sessionEvaluationContext"
         class="hidden"
         data-course-id="{{ $course->id ?? $cursoId ?? 0 }}"
         data-session-id="{{ $session->id ?? 0 }}"
         data-template-url="{{ route('backoffice.courses.evaluation-plan.template', $course->id ?? $cursoId ?? 0) }}"
         data-evaluaciones-asignadas="{{ base64_encode(json_encode($session->evaluaciones_asignadas ?? [])) }}"
         data-evaluaciones-disponibles="{{ base64_encode(json_encode($session->evaluaciones_disponibles ?? [])) }}">
    </div>

    <div>
        <div class="text-base font-semibold text-slate-900">Plan de evaluación de la sesión</div>
        <p class="mt-1 text-sm text-slate-600">
            Asigna solo los hitos reales de esta clase: avances, entregables, proyecto integrador o presentación final.
        </p>
    </div>

    <section class="rounded-xl border border-indigo-100 bg-indigo-50/60 p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="text-sm font-semibold text-slate-950">Plantilla rápida: parcial + final</div>
                <p class="mt-1 text-sm text-slate-600">
                    Para {{ $totalSessions }} sesiones, se propone parcial en sesión {{ $middleSessionNumber ?? '-' }}
                    y final en sesión {{ $finalSessionNumber ?? '-' }}.
                </p>
                <p class="mt-1 text-xs font-semibold text-slate-700">
                    Regla: parcial vence en la última sesión; final vence 5 días después, salvo extensión excepcional.
                </p>
            </div>
            <span class="inline-flex w-fit rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-indigo-700">
                No crea evaluaciones nuevas
            </span>
        </div>

        @if($evaluationOptions->count() >= 2 && $totalSessions >= 2)
            <form id="evaluationPlanTemplateForm" class="mt-4 grid gap-3 lg:grid-cols-[1fr_1fr_170px_auto] lg:items-end">
                @csrf
                <input type="hidden" name="template" value="partial_final">
                <label class="grid gap-1 text-sm font-semibold text-slate-700">
                    Evaluación parcial
                    <select name="partial_evaluation_id" class="min-h-11 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">Selecciona...</option>
                        @foreach($evaluationOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['nombre'] ?? 'Evaluación' }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm font-semibold text-slate-700">
                    Evaluación final
                    <select name="final_evaluation_id" class="min-h-11 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">Selecciona...</option>
                        @foreach($evaluationOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['nombre'] ?? 'Evaluación' }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm font-semibold text-slate-700">
                    Plazo del final
                    <select name="final_extra_days" class="min-h-11 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="5">5 días después</option>
                        <option value="7">7 días después</option>
                        <option value="10">10 días después</option>
                        <option value="15">15 días después</option>
                        <option value="0">Mismo día</option>
                    </select>
                </label>
                <button type="submit" class="min-h-11 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    Aplicar plan
                </button>
                <label class="grid gap-1 text-sm font-semibold text-slate-700 lg:col-span-4">
                    Grupo o proyecto
                    <input name="group_name" type="text" maxlength="120" placeholder="Ej. Proyecto integrador"
                           class="min-h-11 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                </label>
            </form>
            <p class="mt-2 text-xs text-slate-600">
                Si una evaluación ya estaba en otra sesión, se moverá al hito correcto. Luego puedes extender una fecha puntual desde el hito asignado.
            </p>
        @else
            <div class="mt-4 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">
                Publica al menos dos evaluaciones para aplicar esta plantilla.
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <div class="text-sm font-semibold text-slate-950">Plan del curso</div>
                <p class="text-xs text-slate-600">Solo las sesiones con hito muestran actividad evaluable al alumno.</p>
            </div>
            <span class="text-xs font-semibold text-slate-500">{{ $planMilestones->count() }} hitos</span>
        </div>
        <div class="grid gap-2 md:grid-cols-2">
            @forelse($planSessions->filter(fn ($item) => !empty($item['milestones'])) as $planSession)
                @foreach($planSession['milestones'] ?? [] as $milestone)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase text-indigo-700">
                                    Sesión {{ $planSession['session_number'] ?? '-' }}
                                </div>
                                <div class="mt-1 text-sm font-semibold text-slate-950">
                                    {{ $milestone['milestone_name'] ?? $milestone['name'] ?? 'Actividad evaluable' }}
                                </div>
                                @if(!empty($milestone['deadline']))
                                    <div class="mt-1 text-xs text-slate-600">Vence: {{ $milestone['deadline'] }}</div>
                                @endif
                            </div>
                            @if(!empty($milestone['weight']))
                                <span class="rounded-md bg-white px-2 py-1 text-xs font-semibold text-slate-700">{{ $milestone['weight'] }}%</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            @empty
                <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600 md:col-span-2">
                    Este curso todavía no tiene hitos evaluables asignados.
                </div>
            @endforelse
        </div>
    </section>

    <div class="space-y-3">
        <label class="text-sm font-medium text-slate-700">
            Hitos asignados
        </label>
        <div id="evaluacionesAsignadas" class="grid gap-4">
        </div>
    </div>

    <div class="space-y-3">
        <label class="text-sm font-medium text-slate-700">
            Actividades publicadas disponibles
        </label>
        <div id="evaluacionesDisponibles" class="grid gap-4">
        </div>
    </div>

    <div class="pt-2 flex justify-end">
        <button type="button"
                id="assignEvaluationsBtn"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
            Asignar hito
        </button>
    </div>

    <div id="evaluationSyncStatus"
         class="hidden rounded-lg border px-3 py-2 text-sm">
    </div>
</div>
