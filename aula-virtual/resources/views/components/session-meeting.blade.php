@props(['session', 'privileged' => false])

@php
    $meeting = is_array($session->meeting ?? null)
        ? (object) $session->meeting
        : ($session->meeting ?? (object) []);
    $scheduled = (bool) ($meeting->scheduled ?? false);
    $canJoin = (bool) ($meeting->can_join ?? false) && !empty($meeting->join_url);
    $availability = (string) ($meeting->availability ?? 'unavailable');
    $startsLabel = null;

    if (!empty($meeting->starts_at)) {
        try {
            $startsLabel = \Carbon\Carbon::parse($meeting->starts_at)
                ->locale('es')
                ->isoFormat('ddd D [de] MMM, h:mm a');
        } catch (\Throwable) {
            $startsLabel = null;
        }
    }

    $meetingId = trim((string) ($meeting->meeting_id ?? ''));
    $accessCode = trim((string) ($meeting->access_code ?? ''));
    $copyValue = trim('ID: '.$meetingId.($accessCode !== '' ? "\nCódigo: ".$accessCode : ''));
    $courseId = (int) (data_get($session, 'curso_edicion_id') ?: data_get($session, 'curso_id', 0));
    $sessionId = (int) data_get($session, 'id', 0);
@endphp

<section class="session-meeting {{ $canJoin ? 'is-open' : '' }}" aria-label="Acceso a la clase por Zoom">
    <div class="session-meeting__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="3" y="6" width="13" height="12" rx="3"/><path d="m16 10 5-3v10l-5-3z"/></svg>
    </div>

    <div class="session-meeting__content">
        <strong>Clase por Zoom</strong>

        @if(!$scheduled)
            <span>El enlace estará disponible próximamente.</span>
        @elseif($canJoin)
            <span>{{ $privileged ? 'Reunión activa y disponible.' : 'La sala ya está disponible.' }}</span>
        @elseif($availability === 'upcoming')
            <span>
                Disponible 15 minutos antes{{ $startsLabel ? ' · '.$startsLabel : '' }}.
            </span>
        @elseif($availability === 'ended')
            <span>La clase por Zoom finalizó.</span>
        @else
            <span>El acceso no está disponible en este momento.</span>
        @endif
    </div>

    <div class="session-meeting__actions">
        @if($canJoin)
            <form id="sessionZoomJoinForm-{{ $sessionId }}"
                  method="POST"
                  action="{{ route('zoom.join', ['course' => $courseId, 'session' => $sessionId]) }}"
                  target="_blank">
                @csrf
            <button type="submit" class="session-meeting__join">
                {{ $privileged ? 'Abrir Zoom' : 'Ingresar a la clase' }}
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8"/><path d="M19 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>
            </button>
            </form>
        @endif

        @if($privileged && $meetingId !== '')
            <button type="button"
                    class="session-meeting__copy"
                    data-copy-meeting="{{ $copyValue }}"
                    aria-label="Copiar ID y código de acceso de Zoom">
                Copiar acceso
            </button>
            <span class="session-meeting__feedback" data-copy-meeting-feedback role="status" aria-live="polite"></span>
        @endif
    </div>
</section>
