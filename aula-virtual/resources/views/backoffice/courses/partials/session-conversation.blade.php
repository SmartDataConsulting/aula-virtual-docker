@php
    $chatUserRole = session(\App\Support\AuthSessionKeys::USER_ROLE, '');
    $chatReadOnly = in_array(strtolower((string) $chatUserRole), ['admin', 'administrador'], true);
@endphp

@include('shared.community.panel', [
    'chat' => $chat ?? [],
    'participants' => $participants ?? [],
    'userRole' => $chatUserRole,
    'readOnly' => $chatReadOnly,
    'context' => 'COURSE',
    'contextId' => $chat['context_id'] ?? $course->id ?? $curso->id ?? null
])
