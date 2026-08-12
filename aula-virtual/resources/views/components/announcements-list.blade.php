<div class="p-6 space-y-6">
@php
$isSession = request()->routeIs('mis-cursos.sessions.announcements')
    || request()->routeIs('backoffice.courses.show');
@endphp

{{-- =========================
    FLASH SUCCESS
========================= --}}
@if(session('success_annuncio'))
<div id="flash-success-annuncio"
     class="mb-4 rounded-lg bg-green-50 border border-green-200 p-3 text-green-700 text-sm transition-opacity duration-500">
    ✅ {{ session('success_annuncio') }}
</div>
@endif

@if($errors->has('announcement'))
<div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-3 text-red-700 text-sm">
    ❌ {{ $errors->first('announcement') }}
</div>
@endif

<input type="hidden" name="active_tab" value="anuncios">

{{-- =========================
    LISTADO
========================= --}}
<div class="card card-colored p-5 mb-6 space-y-4">

    <div class="flex justify-between items-center">
        <div class="font-semibold">
            {{ $isSession ? 'Anuncios de la sesión' : 'Anuncios del curso' }}
        </div>
        @if($mode === 'edit')
        <button type="button"
            data-open-create-announcement-modal
            class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
            ➕ Crear anuncio
        </button>
        @endif
    </div>

    @forelse($announcements ?? [] as $anuncio)

        @php
            $tipo = strtolower($anuncio->type ?? 'general');

            $cardClass = 'announcement-card';

            if ($tipo === 'importante') {
                $cardClass .= ' announcement-important';
            } elseif ($tipo === 'informativo') {
                $cardClass .= ' announcement-info';
            } else {
                $cardClass .= ' announcement-general';
            }
        @endphp



    <div class="announcement {{ $cardClass }}"
        data-id="{{ $anuncio->id }}"
        data-titulo="{{ $anuncio->title }}"
        data-contenido="{{ $anuncio->content }}"
        data-tipo="{{ $anuncio->type }}"
        data-update-url="{{ route('backoffice.courses.announcements.update', [$course->id, $anuncio->id]) }}">
        {{-- HEADER --}}
        <div class="flex justify-between items-start mb-2">

            <div class="flex items-center gap-2">
                <span class="announcement-badge">{{ strtoupper($tipo) }}</span>
                </span>

                @if(!empty($anuncio->created_at))
                    <span class="text-xs text-gray-500">
                        {{ $anuncio->created_at }}
                    </span>
                @endif
            </div>

            {{-- MENÚ --}}
             @if($mode === 'edit')
            <div class="menu-wrapper relative">
               
                <button type="button"
                    data-toggle-announcement-menu
                    class="h-8 w-8 rounded-full hover:bg-gray-200 flex items-center justify-center">
                    ⋮
                </button>
               
                <div class="menu hidden absolute right-0 top-full mt-2 bg-white border rounded-lg shadow-lg w-40 z-40">

                    <button
                        data-edit-announcement-id="{{ $anuncio->id }}"
                        class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-100">
                        ✏️ Editar
                    </button>

                    <button
                        data-delete-announcement-id="{{ $anuncio->id }}"
                        class="flex items-center gap-2 w-full px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                        🗑 Eliminar
                    </button>

                </div>
            </div>
             @endif
        </div>

        {{-- TITLE --}}
        <div class="font-semibold text-gray-800 mb-1">
            {{ $anuncio->title }}
        </div>

        {{-- CONTENT --}}
        <div class="text-sm text-gray-700 whitespace-pre-line">
            {{ $anuncio->content }}
        </div>

    </div>
    @if($mode === 'edit')
    <form id="delete-announcement-{{ $anuncio->id }}"
        method="POST"
        action="{{ route('backoffice.courses.announcements.destroy', [$course->id, $anuncio->id]) }}"
        class="hidden">
        @csrf
        @method('DELETE')
        <input type="hidden" name="active_tab" value="anuncios">
    </form>
    @endif
    @empty

    <div class="rounded-lg p-4 bg-slate-50 text-slate-500 text-sm">
        No hay anuncios creados para esta sesión.
    </div>

    @endforelse

</div>
@if($mode === 'edit')
{{-- =========================
    MODAL CREAR ANUNCIO
========================= --}}
<x-form-modal
    :id="'createAnnouncementModal'"
    :title="'Nuevo anuncio'"
    :formId="'createAnnouncementForm'"
    :action="route('backoffice.courses.announcements.store', $course->id)"
    :method="null"
    :closeFn="'closeCreateAnnouncementModal()'"
    :submitLabel="'Guardar'">
    
    <input type="hidden" name="entidad_tipo" value="{{ $isSession ? 'sesion' : 'curso' }}">
    <input type="hidden" name="entidad_id" value="{{ $isSession ? $session->id : $course->id }}">
    
    <input name="title"
        placeholder="Título"
        required
        class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">

    <select name="type"
        class="w-full border rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-blue-500 outline-none">
        <option value="general">📌 General</option>
        <option value="informativo">ℹ️ Informativo</option>
        <option value="importante">⚠️ Importante</option>
    </select>

    <textarea name="content"
        rows="3"
        placeholder="Contenido"
        required
        class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none"></textarea>

</x-form-modal>

{{-- =========================
    MODAL EDITAR ANUNCIO
========================= --}}
<x-form-modal
    :id="'editAnnouncementModal'"
    :title="'Editar anuncio'"
    :formId="'editAnnouncementForm'"
    :action="''"
    :method="'PUT'"
    :closeFn="'closeEditAnnouncementModal()'"
    :submitLabel="'Guardar'">

    <input name="title"
        id="edit_annuncio_title"
        class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">

    <select name="type"
        id="edit_annuncio_type"
        class="w-full border rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-blue-500 outline-none">
        <option value="general">📌 General</option>
        <option value="informativo">ℹ️ Informativo</option>
        <option value="importante">⚠️ Importante</option>
    </select>

    <textarea name="content"
        id="edit_annuncio_content"
        rows="3"
        class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none"></textarea>

</x-form-modal>
</div>
@endif
