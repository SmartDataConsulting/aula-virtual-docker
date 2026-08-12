@extends('layouts.main')

@section('title', 'Resultados de encuestas - Smart Data')
@section('meta-description', 'Analiza la participación, valoraciones y comentarios de las encuestas del curso.')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
@php
    $courseName = $courseTitle ?? ($course['title'] ?? 'Curso sin identificar');
    $courseCode = trim((string) ($course['code'] ?? ''));
    $teacher = trim((string) ($course['teacher'] ?? ''));
    $schedule = trim((string) ($course['schedule_formatted'] ?? $course['schedule'] ?? ''));
    $activeView = $filters['view'] ?? 'summary';
    $questions = collect($questionResults ?? []);
    $scaleQuestions = $questions->where('type', 'scale')->filter(fn ($question) => ($question['average'] ?? null) !== null);
    $strengths = $scaleQuestions->sortByDesc('average')->take(3);
    $reviewItems = $scaleQuestions->sortBy('average')->take(3);
    $sessionComparisons = collect($comparisons['sessions'] ?? []);
    $teacherComparisons = collect($comparisons['teachers'] ?? []);
    $forms = collect($catalogs['forms'] ?? []);
    $sessions = collect($catalogs['sessions'] ?? []);
    $teachers = collect($catalogs['teachers'] ?? []);
    $exportQuery = array_filter([
        'kind' => ($filters['kind'] ?? 'all') !== 'all' ? $filters['kind'] : null,
        'session' => $filters['session'] ?? null,
        'teacher' => $filters['teacher'] ?? null,
        'form' => $filters['form'] ?? null,
    ]);
    $exportBase = route('backoffice.surveys.results.export', $cursoEdicionId);
@endphp

<div class="survey-results-page">
    <header class="survey-results-header">
        <nav class="survey-breadcrumb" aria-label="Ruta de navegación">
            <a href="{{ route('backoffice.surveys.index') }}">Encuestas</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">Resultados</span>
        </nav>

        <div class="survey-results-heading">
            <div class="survey-results-heading-copy">
                <div class="survey-results-chips">
                    <span class="survey-chip survey-chip-primary">Encuestas</span>
                    @if($courseCode !== '')
                        <span class="survey-chip">{{ $courseCode }}</span>
                    @endif
                </div>
                <h1>{{ $courseName }}</h1>
                <div class="survey-results-context">
                    @if($isAdmin && $teacher !== '')
                        <span>Responsable: <strong>{{ $teacher }}</strong></span>
                    @endif
                    @if($schedule !== '')
                        <span>{{ $schedule }}</span>
                    @endif
                </div>
            </div>

            <div class="survey-results-actions">
                <a class="survey-button survey-button-secondary" href="{{ route('backoffice.surveys.index') }}">Volver</a>
                <a class="survey-button survey-button-primary" href="{{ $exportBase }}?{{ http_build_query($exportQuery + ['scope' => 'summary']) }}">Exportar resumen</a>
                @if($isAdmin)
                    <a class="survey-button survey-button-secondary" href="{{ $exportBase }}?{{ http_build_query($exportQuery + ['scope' => 'detail']) }}">Exportar detalle</a>
                @endif
            </div>
        </div>
    </header>

    <main class="survey-results-shell">
        <form method="GET" class="survey-filter-bar" aria-label="Filtrar resultados de encuestas" data-navigation-loading-form>
            <input type="hidden" name="view" value="{{ $activeView }}">
            <label class="survey-filter-field">
                <span>Tipo</span>
                <select name="kind">
                    <option value="all" @selected(($filters['kind'] ?? 'all') === 'all')>Todas</option>
                    <option value="session" @selected(($filters['kind'] ?? '') === 'session')>Por sesión</option>
                    <option value="final" @selected(($filters['kind'] ?? '') === 'final')>Encuesta final</option>
                </select>
            </label>

            <label class="survey-filter-field">
                <span>Sesión</span>
                <select name="session">
                    <option value="0">Todas</option>
                    @foreach($sessions as $sessionNumber)
                        <option value="{{ $sessionNumber }}" @selected((int) ($filters['session'] ?? 0) === (int) $sessionNumber)>Sesión {{ $sessionNumber }}</option>
                    @endforeach
                </select>
            </label>

            @if($isAdmin && $teachers->isNotEmpty())
                <label class="survey-filter-field">
                    <span>Docente</span>
                    <select name="teacher">
                        <option value="0">Todos</option>
                        @foreach($teachers as $teacherOption)
                            <option value="{{ $teacherOption['id'] }}" @selected((int) ($filters['teacher'] ?? 0) === (int) $teacherOption['id'])>{{ $teacherOption['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if($forms->count() > 1)
                <label class="survey-filter-field survey-filter-field-wide">
                    <span>Formulario y versión</span>
                    <select name="form">
                        <option value="0">Todos</option>
                        @foreach($forms as $form)
                            <option value="{{ $form['id'] }}" @selected((int) ($filters['form'] ?? 0) === (int) $form['id'])>
                                {{ $form['name'] }} - v{{ $form['version'] }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif

            <div class="survey-filter-actions">
                <button class="survey-button survey-button-primary" type="submit">Aplicar filtros</button>
                <a class="survey-button survey-button-quiet" href="{{ route('backoffice.surveys.results', $cursoEdicionId) }}">Limpiar filtros</a>
            </div>
        </form>

        @if($error)
            <section class="survey-inline-error" role="alert">
                <div>
                    <strong>No se pudo cargar el análisis</strong>
                    <p>{{ $error }}</p>
                </div>
                <a class="survey-button survey-button-secondary" href="{{ request()->fullUrl() }}">Reintentar</a>
            </section>
        @else
            <section class="survey-metrics" aria-label="Resumen del segmento seleccionado">
                <article class="survey-metric">
                    <span>Participación</span>
                    <strong>{{ $surveySummary['participants'] }} de {{ $surveySummary['eligible_students'] }}</strong>
                    <p>
                        @if($surveySummary['roster_mismatch'] ?? false)
                            Padrón actual por revisar
                        @elseif($surveySummary['participation_percent'] !== null)
                            {{ number_format($surveySummary['participation_percent'], 1) }}%
                        @else
                            Sin matrícula disponible
                        @endif
                    </p>
                </article>
                <article class="survey-metric">
                    <span>Encuestas recibidas</span>
                    <strong>{{ $surveySummary['submissions'] }}</strong>
                    <p>Entregas únicas en este segmento</p>
                </article>
                <article class="survey-metric">
                    <span>Promedio general</span>
                    <strong>{{ $surveySummary['average'] !== null ? number_format($surveySummary['average'], 2) : '-' }}</strong>
                    <p>Escala de 1 a 5</p>
                </article>
                <article class="survey-metric">
                    <span>Comentarios utiles</span>
                    <strong>{{ $surveySummary['comments'] }}</strong>
                    <p>{{ $surveySummary['sessions'] }} sesiones evaluadas</p>
                </article>
            </section>

            @if($surveySummary['sample_small'])
                <div class="survey-notice survey-notice-warning" role="status">
                    <strong>Muestra pequeña.</strong>
                    <span>Este segmento tiene menos de cinco respuestas; interpreta sus resultados con cautela.</span>
                </div>
            @endif

            @if($surveySummary['roster_mismatch'] ?? false)
                <div class="survey-notice survey-notice-warning" role="status">
                    <strong>Padrón por revisar.</strong>
                    <span>Hay más participantes históricos que alumnos en la matrícula actual; el porcentaje se oculta para no mostrar un dato incorrecto.</span>
                </div>
            @endif

            @if($surveySummary['privacy_protected'])
                <div class="survey-notice" role="status">
                    <strong>Resultados protegidos.</strong>
                    <span>Los resultados agregados se mostraran cuando este segmento alcance cinco respuestas.</span>
                </div>
            @endif

            <div class="survey-results-tabs" role="tablist" aria-label="Secciones del análisis">
                @foreach(['summary' => 'Resumen', 'questions' => 'Preguntas', 'comments' => 'Comentarios'] as $tabKey => $tabLabel)
                    <button id="surveyTab-{{ $tabKey }}" type="button" role="tab" data-survey-tab="{{ $tabKey }}" aria-controls="surveyPanel-{{ $tabKey }}" aria-selected="{{ $activeView === $tabKey ? 'true' : 'false' }}">{{ $tabLabel }}</button>
                @endforeach
                @if($isAdmin)
                    <button id="surveyTab-responses" type="button" role="tab" data-survey-tab="responses" aria-controls="surveyPanel-responses" aria-selected="{{ $activeView === 'responses' ? 'true' : 'false' }}">Respuestas</button>
                @endif
            </div>

            <section id="surveyPanel-summary" class="survey-tab-panel" role="tabpanel" aria-labelledby="surveyTab-summary" data-survey-tab-panel="summary" @if($activeView !== 'summary') hidden @endif>
                <h2 class="survey-panel-title">Lectura general</h2>
                <p class="survey-panel-description">Identifica rápidamente lo que funciona y dónde conviene intervenir.</p>

                @if($questions->isEmpty())
                    <div class="survey-empty-state">
                        <strong>Sin datos suficientes para generar el resumen</strong>
                        <p>Prueba otro filtro o espera nuevas respuestas.</p>
                    </div>
                @else
                    <div class="survey-insight-grid">
                        <article class="survey-insight-card survey-insight-positive">
                            <div class="survey-insight-heading">
                                <span aria-hidden="true">+</span>
                                <div><h3>Mejor valoradas</h3><p>Fortalezas del segmento seleccionado.</p></div>
                            </div>
                            <ol class="survey-ranked-list">
                                @forelse($strengths as $question)
                                    <li><span>{{ $question['label'] }}</span><strong>{{ number_format($question['average'], 2) }}</strong></li>
                                @empty
                                    <li class="survey-ranked-empty">No hay preguntas de escala.</li>
                                @endforelse
                            </ol>
                        </article>
                        <article class="survey-insight-card survey-insight-review">
                            <div class="survey-insight-heading">
                                <span aria-hidden="true">!</span>
                                <div><h3>Aspectos por revisar</h3><p>Preguntas con menor valoración relativa.</p></div>
                            </div>
                            <ol class="survey-ranked-list">
                                @forelse($reviewItems as $question)
                                    <li><span>{{ $question['label'] }}</span><strong>{{ number_format($question['average'], 2) }}</strong></li>
                                @empty
                                    <li class="survey-ranked-empty">No hay preguntas de escala.</li>
                                @endforelse
                            </ol>
                        </article>
                    </div>

                    <div class="survey-comparison-grid">
                        <article class="survey-comparison-card">
                            <h3>Evolución por sesión</h3>
                            <p>Promedio de preguntas de escala.</p>
                            <div class="survey-bar-list">
                                @forelse($sessionComparisons as $item)
                                    <div class="survey-bar-row">
                                        <div><span>Sesión {{ $item['session'] }}</span><small>{{ $item['responses'] }} respuestas</small></div>
                                        <div class="survey-bar-track" role="img" aria-label="Sesión {{ $item['session'] }}: promedio {{ number_format($item['average'], 2) }} de 5"><span style="width: {{ min(100, max(0, ((float) $item['average'] / 5) * 100)) }}%"></span></div>
                                        <strong>{{ number_format($item['average'], 2) }}</strong>
                                    </div>
                                @empty
                                    <div class="survey-mini-empty">No hay comparación por sesiones para estos filtros.</div>
                                @endforelse
                            </div>
                        </article>

                        @if($teacherComparisons->isNotEmpty())
                            <article class="survey-comparison-card">
                                <h3>{{ $isAdmin ? 'Comparacion por docente' : 'Tu resultado' }}</h3>
                                <p>{{ $isAdmin ? 'Promedios de preguntas asociadas al docente.' : 'Comparado con el promedio general del curso.' }}</p>
                                <div class="survey-bar-list">
                                    @foreach($teacherComparisons as $item)
                                        <div class="survey-bar-row">
                                            <div><span>{{ $item['teacher'] }}</span><small>{{ $item['responses'] }} respuestas</small></div>
                                            <div class="survey-bar-track" role="img" aria-label="{{ $item['teacher'] }}: promedio {{ number_format($item['average'], 2) }} de 5"><span style="width: {{ min(100, max(0, ((float) $item['average'] / 5) * 100)) }}%"></span></div>
                                            <strong>{{ number_format($item['average'], 2) }}</strong>
                                        </div>
                                    @endforeach
                                    @if(!$isAdmin && ($comparisons['course_average'] ?? null) !== null)
                                        <div class="survey-bar-row survey-bar-row-reference">
                                            <div><span>Promedio general</span><small>Referencia del curso</small></div>
                                            <div class="survey-bar-track" role="img" aria-label="Promedio general: {{ number_format($comparisons['course_average'], 2) }} de 5"><span style="width: {{ min(100, max(0, ((float) $comparisons['course_average'] / 5) * 100)) }}%"></span></div>
                                            <strong>{{ number_format($comparisons['course_average'], 2) }}</strong>
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @endif
                    </div>
                @endif
            </section>

            <section id="surveyPanel-questions" class="survey-tab-panel" role="tabpanel" aria-labelledby="surveyTab-questions" data-survey-tab-panel="questions" @if($activeView !== 'questions') hidden @endif>
                <h2 class="survey-panel-title">Resultados por pregunta</h2>
                <p class="survey-panel-description">Cada tarjeta conserva el formulario y la versión que respondió el alumno.</p>
                <div class="survey-question-grid">
                    @forelse($questions as $question)
                        <article class="survey-question-card">
                            <div class="survey-question-meta"><span>{{ $question['form_name'] }} · v{{ $question['version'] }}</span><span>{{ $question['kind'] === 'final' ? 'Final' : 'Sesión' }}</span></div>
                            <h3>{{ $question['label'] }}</h3>
                            <div class="survey-question-stat">
                                @if($question['average'] !== null)
                                    <strong>{{ number_format($question['average'], 2) }} <small>de 5</small></strong>
                                @elseif($question['type'] === 'textarea')
                                    <strong>{{ $question['comments_count'] }} <small>comentarios utiles</small></strong>
                                @else
                                    <strong>{{ $question['responses'] }} <small>respuestas</small></strong>
                                @endif
                                <span>{{ $question['responses'] }} respuestas</span>
                            </div>
                            @if(!empty($question['distribution']))
                                <div class="survey-distribution" aria-label="Distribucion de respuestas">
                                    @foreach($question['distribution'] as $value => $count)
                                        @php $percent = $question['responses'] > 0 ? ((int) $count / (int) $question['responses']) * 100 : 0; @endphp
                                        <div class="survey-distribution-row">
                                            <span>{{ $value }}</span>
                                            <div class="survey-distribution-track" role="img" aria-label="Opcion {{ $value }}: {{ $count }} respuestas, {{ number_format($percent, 0) }} por ciento"><span style="width: {{ $percent }}%"></span></div>
                                            <strong>{{ $count }}</strong>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif($question['type'] === 'textarea' && $question['comments_count'] > 0)
                                <button type="button" class="survey-text-link" data-open-survey-tab="comments">Revisar comentarios</button>
                            @endif
                        </article>
                    @empty
                        <div class="survey-empty-state survey-grid-empty"><strong>No hay preguntas para este segmento</strong><p>Ajusta los filtros para consultar otra versión o tipo de encuesta.</p></div>
                    @endforelse
                </div>
            </section>

            <section id="surveyPanel-comments" class="survey-tab-panel" role="tabpanel" aria-labelledby="surveyTab-comments" data-survey-tab-panel="comments" @if($activeView !== 'comments') hidden @endif>
                <h2 class="survey-panel-title">Comentarios utiles</h2>
                <p class="survey-panel-description">Se excluyen respuestas vacias, guiones y valores como N/A.</p>
                <div class="survey-comments-list">
                    @forelse($comments as $comment)
                        <article class="survey-comment-card">
                            <div class="survey-comment-meta">
                                <span>{{ ($comment['kind'] ?? 'session') === 'final' ? 'Encuesta final' : 'Sesión '.($comment['session'] ?? '-') }}</span>
                                <span>{{ $comment['form'] ?? 'Formulario' }}</span>
                                @if(!empty($comment['submitted_at']))<time>{{ $comment['submitted_at'] }}</time>@endif
                            </div>
                            <p>{{ $comment['text'] }}</p>
                            @if($isAdmin && !empty($comment['respondent']))<small>Participante: {{ $comment['respondent'] }}</small>@endif
                        </article>
                    @empty
                        <div class="survey-empty-state"><strong>No hay comentarios útiles</strong><p>Las respuestas abiertas aparecerán aquí cuando contengan información revisable.</p></div>
                    @endforelse
                </div>
            </section>

            @if($isAdmin)
                <section id="surveyPanel-responses" class="survey-tab-panel" role="tabpanel" aria-labelledby="surveyTab-responses" data-survey-tab-panel="responses" @if($activeView !== 'responses') hidden @endif>
                    <h2 class="survey-panel-title">Respuestas individuales</h2>
                    <p class="survey-panel-description">Vista administrativa para soporte y auditoría. Se muestran 25 registros por página.</p>
                    <div class="survey-response-list">
                        @forelse($resultados as $row)
                            <article class="survey-response-row">
                                <div class="survey-response-summary">
                                    <div><strong>{{ $row['respondent'] ?? 'Participante sin identificar' }}</strong><span>{{ ($row['kind'] ?? 'session') === 'final' ? 'Encuesta final' : 'Sesión '.($row['nro_sesion'] ?? '-') }}</span></div>
                                    <div><strong>{{ $row['formulario'] ?? 'Formulario' }}</strong><span>Versión {{ $row['formulario_version'] ?? '-' }}</span></div>
                                    <div><strong>{{ $row['docente'] ?? 'Sin docente' }}</strong><span>{{ $row['submitted_at'] ?? 'Sin fecha' }}</span></div>
                                </div>
                                <details>
                                    <summary>Ver respuestas</summary>
                                    <dl class="survey-answer-list">
                                        @forelse(($row['answers'] ?? []) as $answer)
                                            <div><dt>{{ $answer['label'] ?? $answer['code'] ?? 'Pregunta' }}</dt><dd>{{ $answer['value'] ?? '-' }}</dd></div>
                                        @empty
                                            <div><dt>Detalle</dt><dd>No hay respuestas dinámicas registradas.</dd></div>
                                        @endforelse
                                    </dl>
                                </details>
                            </article>
                        @empty
                            <div class="survey-empty-state"><strong>No hay respuestas individuales</strong><p>Prueba otro filtro o espera nuevas entregas.</p></div>
                        @endforelse
                    </div>
                    @if($resultados->hasPages())
                        <div class="smart-pagination-wrap">{{ $resultados->withQueryString()->links() }}</div>
                    @endif
                </section>
            @endif
        @endif
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/backoffice-surveys-results.js')
@endpush
