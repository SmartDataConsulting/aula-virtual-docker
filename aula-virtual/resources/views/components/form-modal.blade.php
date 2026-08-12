<div id="{{ $id }}" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center px-4" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold" id="modal-title">{{ $title }}</h3>
        </div>
        <form method="POST" enctype="multipart/form-data" id="{{ $formId }}" class="p-6 space-y-4" action="{{ $action }}">
            @csrf
            @if($method)
                @method($method)
            @endif
            {{ $slot }}
            <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" data-close-handler="{{ $closeFn }}" class="px-4 py-2 rounded hover:bg-gray-100" aria-label="Cancelar">Cancelar</button>
                <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg font-medium flex items-center gap-2" aria-label="Guardar">
                    <span>{{ $submitLabel ?? 'Guardar' }}</span>
                </button>
            </div>
        </form>
    </div>
</div>
