@php
  $evaluations = collect($session->evaluaciones ?? []);
  $formatScore = static fn (?float $value): string => $value === null ? '--' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
  $formatDeadline = static function ($value): ?string {
      if (empty($value)) {
          return null;
      }

      try {
          return \Carbon\Carbon::parse($value, 'America/Lima')->locale('es')->isoFormat('D [de] MMMM [de] YYYY · h:mm a');
      } catch (\Throwable) {
          return (string) $value;
      }
  };
@endphp

<section class="student-panel-section">
  <div class="student-panel-heading">
    <div>
      <h3>Evaluaciones</h3>
      <p>Consulta tus hitos, entregas y puntajes del curso.</p>
    </div>
  </div>

  <div class="student-panel-list">
    @forelse($evaluations as $evaluation)
      @php
        $item = is_array($evaluation) ? $evaluation : (array) $evaluation;
        $id = (int) ($item['id'] ?? 0);
        $typeId = (int) ($item['tipo_param_id'] ?? $item['type_id'] ?? 0);
        $isWork = in_array($typeId, [3, 4], true);
        $delivery = is_array($item['entrega'] ?? null) ? $item['entrega'] : [];
        $submissionId = (int) ($item['rendicion_id'] ?? $item['delivery_id'] ?? $delivery['entrega_id'] ?? 0);
        $submissionStatus = strtolower(trim((string) ($item['rendicion_estado'] ?? $item['entrega_estado'] ?? $item['estado'] ?? $item['status'] ?? $item['status_key'] ?? $delivery['estado'] ?? '')));
        $workStatus = strtolower(trim((string) ($item['entrega_estado'] ?? $submissionStatus)));
        $deliveredStatuses = ['finalizado', 'finalizada', 'entregado', 'entregada', 'corregido', 'corregida', 'corrected', 'graded', 'calificado', 'calificada', 'evaluado', 'evaluada', 'evaluated', 'presentado', 'presentada', 'completado', 'completada', 'aprobado', 'aprobada'];
        $reviewingStatuses = ['revisando', 'reviewing', 'en_revision', 'revision', 'revisión'];
        $gradeStatuses = ['corregido', 'corregida', 'corrected', 'graded', 'calificado', 'calificada', 'evaluado', 'evaluada', 'evaluated', 'aprobado', 'aprobada'];
        $workDelivered = $isWork && (
            (!empty($item['finalizada']) && $item['finalizada'])
            || in_array($workStatus, $deliveredStatuses, true)
            || in_array($submissionStatus, $deliveredStatuses, true)
            || (!empty($item['has_delivery']) && $item['has_delivery'])
            || (!empty($item['delivery_id']) && (int) $item['delivery_id'] > 0)
        );
        $scoreValue = $item['score'] ?? $item['puntaje_total'] ?? $item['puntaje_obtenido'] ?? $item['nota_final'] ?? $item['nota'] ?? $item['calificacion'] ?? $item['total'] ?? $delivery['score'] ?? $delivery['nota_final'] ?? $delivery['puntaje_total'] ?? $delivery['puntaje_obtenido'] ?? $delivery['nota'] ?? $delivery['calificacion'] ?? null;
        $maxScoreValue = $item['max_score'] ?? $item['puntaje_maximo'] ?? $item['puntaje_max'] ?? $delivery['max_score'] ?? $delivery['puntaje_max'] ?? $delivery['puntaje_maximo'] ?? null;
        $passScoreValue = $item['pass_score'] ?? $item['puntaje_aprobacion'] ?? null;
        $score = is_numeric($scoreValue) ? (float) $scoreValue : null;
        $maxScore = is_numeric($maxScoreValue) && (float) $maxScoreValue > 0 ? (float) $maxScoreValue : 20.0;
        $passScore = is_numeric($passScoreValue) ? (float) $passScoreValue : 11.0;
        $isCorrected = in_array($submissionStatus, $gradeStatuses, true) || in_array($workStatus, $gradeStatuses, true);
        $hasScore = $score !== null;
        $approved = $hasScore
            ? (($item['approved'] ?? $item['aprobado'] ?? $delivery['aprobado'] ?? null) !== null
                ? (bool) ($item['approved'] ?? $item['aprobado'] ?? $delivery['aprobado'])
                : ($score >= $passScore))
            : null;
        $url = $isWork
            ? route('mis-cursos.evaluaciones.trabajo.show', [$course->id, $session->id, $id])
            : ($hasScore
                ? route('mis-cursos.evaluaciones.rendicion.result', [$course->id, $session->id, $id, $submissionId])
                : route('mis-cursos.evaluaciones.rendir', [$course->id, $session->id, $id]));
        $label = $isWork ? ($workDelivered ? 'Ver entrega' : 'Ver trabajo') : ($hasScore ? 'Ver resultado' : ($submissionId > 0 ? 'Continuar' : 'Comenzar'));
        $stateLabel = $hasScore
            ? 'Calificado'
            : (($workDelivered || $isCorrected) ? 'Entregado · pendiente de calificación' : (in_array($submissionStatus, $reviewingStatuses, true) ? 'En revisión' : ($submissionId > 0 ? 'En progreso' : 'Pendiente')));
        $milestone = $item['hito_nombre'] ?? $item['milestone_name'] ?? $item['nombre'] ?? 'Actividad evaluable';
        $groupName = $item['grupo_nombre'] ?? $item['group_name'] ?? null;
        $deadline = $formatDeadline($item['fecha_limite'] ?? $item['deadline'] ?? null);
        $weight = $item['peso'] ?? $item['weight'] ?? null;
      @endphp

      @if($id > 0)
        <article class="student-activity-row student-evaluation-card {{ $hasScore ? 'has-score' : '' }}">
          <div class="student-evaluation-card__content">
            <span>{{ $isWork ? 'TRABAJO' : 'EVALUACIÓN' }}</span>
            <strong>{{ $milestone }}</strong>
            @if($groupName)
              <small>{{ $groupName }}</small>
            @endif
            <small>{{ $stateLabel }}</small>
            <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
              @if($deadline)
                <span class="rounded-md bg-slate-100 px-2 py-1">Vence: {{ $deadline }}</span>
              @endif
              <span class="rounded-md bg-slate-100 px-2 py-1">Máximo: {{ $formatScore($maxScore) }} pts</span>
              @if(is_numeric($weight))
                <span class="rounded-md bg-slate-100 px-2 py-1">Peso: {{ $formatScore((float) $weight) }}%</span>
              @endif
            </div>
          </div>

          @if($hasScore)
            <div class="student-evaluation-score" aria-label="Puntaje {{ $formatScore($score) }} de {{ $formatScore($maxScore) }}. {{ $approved ? 'Evaluación aprobada' : 'Evaluación no aprobada' }}">
              <span>Puntaje</span>
              <strong>{{ $formatScore($score) }} <small>/ {{ $formatScore($maxScore) }}</small></strong>
              <em class="{{ $approved ? 'is-approved' : 'is-not-approved' }}">{{ $approved ? 'Aprobada' : 'No aprobada' }}</em>
            </div>
          @endif

          <a href="{{ $url }}">{{ $label }}</a>
        </article>
      @endif
    @empty
      <div class="student-panel-empty">
        <strong>Esta sesión no tiene actividad evaluable.</strong>
        <span>Tu docente publicará entregas solo en las sesiones que correspondan al plan del curso.</span>
      </div>
    @endforelse
  </div>
</section>
