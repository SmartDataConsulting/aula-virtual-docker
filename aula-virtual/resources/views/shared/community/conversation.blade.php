@include('shared.chat.conversation-panel', [
    'chatTitle' => 'Conversación del curso',
    'showChatHeader' => false,
    'chatContext' => $chatContext ?? 'COURSE',
    'contextId' => $contextId ?? data_get($chat ?? [], 'context_id'),
    'userRole' => $userRole ?? '',
    'readOnly' => $readOnly ?? false,
    'chatCount' => data_get($chat ?? [], 'total_mensajes', 0),
    'chatMessages' => data_get($chat ?? [], 'mensajes', []),
    'chatPagination' => data_get($chat ?? [], 'pagination', []),
    'chatError' => data_get($chat ?? [], 'error'),
    'chatLoading' => data_get($chat ?? [], 'loading', false),
    'chatSalaId' => data_get($chat ?? [], 'sala_id')
])
