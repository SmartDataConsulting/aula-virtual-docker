@include('shared.community.panel', [
    'chat' => $chat ?? [],
    'participants' => $participants ?? [],
    'userRole' => $userRole ?? 'ALUMNO',
    'readOnly' => $readOnly ?? false,
    'context' => 'COURSE',
    'contextId' => $chat['context_id'] ?? $course->id ?? $curso->id ?? null
])
