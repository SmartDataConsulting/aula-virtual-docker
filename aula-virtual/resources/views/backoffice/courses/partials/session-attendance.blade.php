@php
  $records = collect($attendance['items'] ?? [])->values();
  $students = $records->where('participant_type', 'alumno')->values();
  $teacher = $records->firstWhere('participant_type', 'docente');
  $unresolved = collect($attendance['unresolved'] ?? [])->values();
  $summary = $attendance['summary'] ?? [];
  $sessionData = $attendance['session'] ?? [];
  $sessionNumber = (int) ($sessionData['number'] ?? $session->number ?? 0);
  $panelStatus = (string) ($attendance['status'] ?? 'pending');
  $statusLabels = [
      'disabled' => 'Asistencia no habilitada',
      'no_meeting' => 'Sin reunión de Zoom',
      'reconciled' => 'Conciliada',
      'pending' => 'Pendiente',
  ];
  $recordLabels = [
      'asistio' => 'Asistió', 'presente' => 'Presente', 'tardanza' => 'Tardanza',
      'falta' => 'Falta', 'justificada' => 'Justificada', 'no_aplica' => 'No aplica',
      'pendiente' => 'Pendiente',
  ];
  $timeLabel = static function ($value): string {
      if (!$value) return 'Sin ingreso';
      try { return \Carbon\Carbon::parse($value)->format('H:i:s'); }
      catch (\Throwable) { return 'Sin ingreso'; }
  };
  $syncLabel = null;
  if (!empty($attendance['sync']['synced_at'])) {
      try { $syncLabel = \Carbon\Carbon::parse($attendance['sync']['synced_at'])->format('d/m/Y H:i'); }
      catch (\Throwable) { $syncLabel = null; }
  }
@endphp

<section class="session-attendance" data-session-attendance-panel data-attendance-session-id="{{ $session->id }}" @if(!empty($refreshUrl)) data-attendance-refresh-url="{{ $refreshUrl }}" @endif>
  <div class="session-attendance__feedback" data-attendance-feedback role="status" aria-live="polite" tabindex="-1" hidden></div>

  <header class="session-attendance__header">
    <div>
      <span class="session-attendance__eyebrow">CONTROL DE ASISTENCIA</span>
      <h3>Asistencia de la sesión {{ $sessionNumber ?: '-' }}</h3>
      <p>{{ \App\Support\SessionPresentation::dateTimeLabel($session) ?: 'Horario por confirmar' }}</p>
    </div>
    <div class="session-attendance__header-actions">
      <span class="attendance-status is-{{ $panelStatus }}">{{ $statusLabels[$panelStatus] ?? 'Pendiente' }}</span>
      @if($syncLabel)<small>Última actualización: {{ $syncLabel }}</small>@endif
    </div>
  </header>

  @if(!$attendance['enabled'])
    <div class="session-attendance__empty">
      <strong>La asistencia todavía no está habilitada.</strong>
      <p>Los registros aparecerán cuando se active la integración de asistencia.</p>
    </div>
  @elseif(!$attendance['meeting_scheduled'])
    <div class="session-attendance__empty">
      <strong>Esta sesión no tiene una reunión asociada.</strong>
      <p>Asocia la reunión de Zoom para registrar y conciliar ingresos.</p>
    </div>
  @else
    <div class="session-attendance__metrics" aria-label="Resumen de asistencia de alumnos">
      <div><span>Alumnos registrados</span><strong>{{ $summary['students'] ?? 0 }}</strong></div>
      <div><span>Asistieron</span><strong>{{ $summary['present'] ?? 0 }}</strong></div>
      <div><span>Faltaron</span><strong>{{ $summary['absent'] ?? 0 }}</strong></div>
      <div><span>Pendientes</span><strong>{{ $summary['pending'] ?? 0 }}</strong></div>
    </div>

    <div class="session-attendance__actions-bar">
      <div>
        @if($isAdmin && $attendance['can_sync'])
          <form method="POST" action="{{ route('backoffice.attendance.sync', $session->id) }}" data-attendance-action>
            @csrf
            <button type="submit" class="session-attendance__primary-action">Conciliar con Zoom</button>
          </form>
        @elseif($isAdmin && !($attendance['sync_enabled'] ?? false))
          <span>La sincronización con Zoom todavía no está habilitada.</span>
        @elseif($panelStatus === 'pending')
          <span>Zoom aún no ha confirmado la asistencia.</span>
        @endif
      </div>
      <div>
        @if($isAdmin)
          <a href="{{ $attendanceExportUrl ?? route('backoffice.attendance.course.export', [$course->id, 'session_id' => $session->id]) }}">Exportar sesión</a>
        @endif
        @if($showFullAttendanceLink ?? true)
          <a href="{{ route('backoffice.attendance.show', ['course' => $course->id, 'session' => $session->id]) }}">Abrir reporte del curso</a>
        @elseif(!empty($workspaceUrl))
          <a href="{{ $workspaceUrl }}">Abrir sesión en el aula</a>
        @endif
      </div>
    </div>

    @if($teacher)
      <article class="session-attendance__teacher {{ $panelStatus === 'pending' ? 'is-pending' : '' }}">
        <div>
          <span>{{ $isAdmin ? 'ASISTENCIA DOCENTE' : 'TU ASISTENCIA' }}</span>
          <strong>{{ $isAdmin ? $teacher->name : 'Estado de tu participación' }}</strong>
          <small>Ingreso: {{ $timeLabel($teacher->first_join_at) }}</small>
        </div>
        <div>
          @if($panelStatus === 'reconciled')
            <strong>{{ number_format($teacher->percentage, 1) }}%</strong>
            <span>{{ round($teacher->minutes) }} min de permanencia</span>
          @else
            <strong>Por confirmar</strong>
            <span>Se actualizará después de la conciliación</span>
          @endif
        </div>
        <div class="session-attendance__teacher-status">
          <span class="attendance-status is-{{ $teacher->status }}">{{ $recordLabels[$teacher->status] ?? 'Pendiente' }}</span>
          @if($isAdmin)
            <button type="button" data-attendance-correct
                    data-action="{{ route('backoffice.attendance.update', [$teacher->session_id, $teacher->id]) }}"
                    data-participant="{{ $teacher->name }}" data-type="docente" data-status="{{ $teacher->status }}">Corregir</button>
          @endif
        </div>
      </article>
    @endif

    <details class="session-attendance__students" data-attendance-students>
      <summary>
        <span><strong>Alumnos de la sesión</strong><small>Consulta ingresos y registra excepciones cuando corresponda.</small></span>
        <span>Ver alumnos ({{ $students->count() }})</span>
      </summary>

      <div class="session-attendance__students-content">
        @if($students->isNotEmpty())
          <div class="session-attendance__toolbar">
            <label><span class="sr-only">Buscar alumno</span><input type="search" data-attendance-search placeholder="Buscar por nombre o correo"></label>
            <label><span class="sr-only">Filtrar por estado</span><select data-attendance-status-filter><option value="">Todos los estados</option>@foreach($recordLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <span data-attendance-visible-count>{{ $students->count() }} alumnos</span>
          </div>

          <div class="session-attendance__table-wrap">
            <table class="session-attendance__table">
              <thead><tr><th>Alumno</th><th>Estado</th><th>Ingreso</th><th>Evidencia</th><th><span class="sr-only">Acciones</span></th></tr></thead>
              <tbody>
              @foreach($students as $item)
                <tr data-attendance-row data-search="{{ mb_strtolower($item->name.' '.($item->email ?? '')) }}" data-status="{{ $item->status }}">
                  <td data-label="Alumno"><strong>{{ $item->name }}</strong><span>{{ $item->email ?: 'Correo no disponible' }}</span></td>
                  <td data-label="Estado"><span class="attendance-status is-{{ $item->status }}">{{ $recordLabels[$item->status] ?? 'Pendiente' }}</span>@if($item->manual_status)<small>Corrección manual</small>@endif</td>
                  <td data-label="Ingreso">{{ $timeLabel($item->first_join_at) }}</td>
                  <td data-label="Evidencia"><strong>{{ round($item->minutes) }} min</strong><span>La duración no cambia el estado</span></td>
                  <td data-label="Acciones"><button type="button" data-attendance-correct
                        data-action="{{ route('backoffice.attendance.update', [$item->session_id, $item->id]) }}"
                        data-participant="{{ $item->name }}" data-type="alumno" data-status="{{ $item->status }}">Corregir</button></td>
                </tr>
              @endforeach
              </tbody>
            </table>
          </div>
          <div class="session-attendance__no-results" data-attendance-no-results hidden>No hay alumnos que coincidan con los filtros.</div>
        @else
          <div class="session-attendance__empty"><strong>La sesión todavía no tiene registros de asistencia.</strong><p>Los participantes aparecerán cuando se genere el padrón o Zoom confirme ingresos.</p></div>
        @endif
      </div>
    </details>

    @if($unresolved->isNotEmpty())
      <details class="session-attendance__unresolved">
        <summary>Participantes por identificar <span>{{ $unresolved->count() }}</span></summary>
        <p>Asocia solamente conexiones cuya identidad puedas confirmar.</p>
        @foreach($unresolved as $rawEvent)
          @php $event = (object) $rawEvent; @endphp
          <form method="POST" action="{{ route('backoffice.attendance.identify', $event->sesion_id) }}" data-attendance-action>
            @csrf
            <input type="hidden" name="event_id" value="{{ $event->id }}">
            <div><strong>{{ $event->participante_nombre ?: 'Nombre no informado' }}</strong><span>{{ $event->participante_correo ?: 'Sin correo' }}</span></div>
            <select name="attendance_id" required aria-label="Persona correspondiente"><option value="">Selecciona una persona</option>@foreach($records as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }} - {{ $candidate->participant_type === 'docente' ? 'Docente' : 'Alumno' }}</option>@endforeach</select>
            <button type="submit">Asociar</button>
          </form>
        @endforeach
      </details>
    @endif
  @endif

  <dialog class="session-attendance__dialog" data-attendance-dialog aria-labelledby="attendanceDialogTitle">
    <form method="POST" data-attendance-correction-form data-attendance-action>
      @csrf
      @method('PATCH')
      <div class="session-attendance__dialog-header"><div><span>CORRECCIÓN MANUAL</span><h4 id="attendanceDialogTitle">Corregir asistencia</h4><p data-attendance-participant></p></div><button type="button" data-attendance-dialog-close aria-label="Cerrar">&times;</button></div>
      <label>Estado<select name="status" required data-attendance-correction-status></select></label>
      <label>Motivo<textarea name="reason" minlength="3" maxlength="500" required placeholder="Explica el motivo de la corrección"></textarea></label>
      <div class="session-attendance__dialog-actions"><button type="button" data-attendance-dialog-close>Cancelar</button><button type="submit">Guardar corrección</button></div>
    </form>
  </dialog>

  <noscript><p class="session-attendance__noscript">Activa JavaScript para gestionar esta sesión o usa <a href="{{ route('backoffice.attendance.show', ['course' => $course->id, 'session' => $session->id]) }}">la vista completa de asistencia</a>.</p></noscript>
</section>
