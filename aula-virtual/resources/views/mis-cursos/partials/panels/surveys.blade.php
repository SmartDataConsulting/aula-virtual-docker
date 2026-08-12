@php $surveys = collect($session->surveys ?? []); @endphp
<section class="student-panel-section">
  <div class="student-panel-heading"><div><h3>Encuestas</h3><p>Tu opinión nos ayuda a mejorar la experiencia del curso.</p></div></div>
  <div class="student-panel-list">
    @forelse($surveys as $survey)
      @php
        $status = $survey->status ?? 'upcoming';
        $label = match($status) {'pending' => 'Responder', 'answered' => 'Respuesta enviada', 'closed' => 'Cerrada', default => 'Disponible pronto'};
      @endphp
      <article class="student-activity-row">
        <div><span>{{ ($survey->kind ?? 'session') === 'final' ? 'CIERRE DEL CURSO' : 'TU OPINIÓN' }}</span><strong>{{ $survey->title ?? 'Encuesta de la sesión' }}</strong><small>{{ $status === 'answered' ? 'Gracias por completar esta encuesta.' : ($status === 'upcoming' ? 'Disponible 15 minutos antes de finalizar la sesión.' : 'Completarla toma pocos minutos.') }}</small></div>
        @if($status === 'pending')
          <a href="{{ route('mis-cursos.encuestas.show', [$course->id, $session->id, $survey->link_id]) }}">{{ $label }}</a>
        @else<span class="student-activity-status is-{{ $status }}">{{ $label }}</span>@endif
      </article>
    @empty
      <div class="student-panel-empty"><strong>No hay encuestas disponibles</strong><span>Las encuestas de esta sesión aparecerán aquí cuando se publiquen.</span></div>
    @endforelse
  </div>
</section>
