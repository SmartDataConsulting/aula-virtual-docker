@php
  $item = $attendanceItem ?? null;
  $status = data_get($item, 'status', 'pendiente');
  $label = match($status) {'asistio' => 'Asistió', 'falta' => 'No asistió', 'justificada' => 'Justificada', 'no_aplica' => 'No aplica', default => 'Pendiente'};
@endphp
<section class="student-panel-section">
  <div class="student-panel-heading"><div><h3>Mi asistencia</h3><p>Zoom confirma tu ingreso. No se aplican tardanzas ni tiempo mínimo.</p></div></div>
  @if(!empty($attendanceError))
    <div class="course-panel-error" role="alert"><strong>No se pudo cargar tu asistencia.</strong><span>Intenta nuevamente.</span></div>
  @else
    <div class="student-attendance-current">
      <div><span>ESTADO DE ESTA SESIÓN</span><strong>{{ $label }}</strong><small>{{ $status === 'pendiente' ? 'Zoom aún está confirmando tu ingreso.' : 'Este estado corresponde únicamente a tu registro.' }}</small></div>
      <span class="attendance-status is-{{ $status }}">{{ $label }}</span>
    </div>
  @endif
  <a class="student-panel-secondary-link" href="{{ route('mis-cursos.attendance', $course->id) }}">Ver historial del curso <span aria-hidden="true">&rsaquo;</span></a>
</section>
