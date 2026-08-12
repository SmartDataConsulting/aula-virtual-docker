@if(session('success'))
<div id="flash-success"
     class="mb-4 rounded-lg bg-green-50 border border-green-200 p-3 text-green-700 text-sm transition-opacity duration-500">
    ✅ {{ session('success') }}
</div>
@endif
@if($errors->any())
<div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-3 text-red-700 text-sm">
    ❌ {{ $errors->first() }}
</div>
@endif

<div class="card card-colored p-5 mb-6 space-y-4">

    <div class="flex justify-between items-center">
        <div class="font-semibold">Material de la sesión</div>

        <button
            type="button"
            data-open-create-material-modal
            class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
            aria-label="Subir material">
        ➕ Subir material
        </button>
    </div>

    {{-- Componente reutilizable --}}
        <x-session-materials-list
            :materials="$session->materials"
            mode="admin"
             :course="$course"
             :session="$session"
        />
</div>

{{-- Modal Crear Material --}}
<x-form-modal
    :id="'createMaterialModal'"
    :title="'Nuevo material'"
    :formId="'createMaterialForm'"
    :action="route('backoffice.courses.materials.store', [$course->id, $session->id])"
    :method="null"
    :closeFn="'closeCreateMaterialModal()'"
    :submitLabel="'Guardar'">
    <input name="titulo" placeholder="Título" required class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none @error('titulo') border-red-500 @enderror">
    @error('titulo')
        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
    @enderror
    <textarea name="descripcion" rows="2" placeholder="Descripción" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none @error('descripcion') border-red-500 @enderror"></textarea>
    @error('descripcion')
        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
    @enderror
    <select name="tipo" id="createTipo" class="w-full border rounded-lg px-3 py-2 bg-white @error('tipo') border-red-500 @enderror">
        <option value="archivo">📄 Archivo</option>
        <option value="link">🔗 Link</option>
        <option value="video">▶️ Video</option>
    </select>
    @error('tipo')
        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
    @enderror
    <div id="archivoWrapper" class="hidden border-2 border-dashed rounded-lg p-4 text-center">
        <input
            type="file"
            name="archivo"
            id="archivoInput"
            accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.csv,.zip,.jpg,.jpeg,.png,.gif,.webp"
            class="hidden">
        <label for="archivoInput" class="cursor-pointer inline-flex items-center gap-2 px-4 py-2 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-100">
        📎 Seleccionar archivo
        </label>
        <p id="archivoNombre" class="text-xs text-gray-500 mt-2">Ningún archivo seleccionado</p>
        <p class="mt-1 text-xs text-slate-500">PDF, Office, ZIP o imagen. Maximo 30 MB.</p>
        @error('archivo')
            <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
        @enderror
    </div>
    <div id="urlWrapper" class="hidden">
        <input name="url_externa" placeholder="URL externa" class="w-full border rounded-lg px-3 py-2 @error('url_externa') border-red-500 @enderror">
        @error('url_externa')
            <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
        @enderror
    </div>
    <div class="flex items-center justify-between"></div>
</x-form-modal>

{{-- Modal Editar Material --}}
<x-form-modal
    :id="'editMaterialModal'"
    :title="'Editar material'"
    :formId="'editMaterialForm'"
    :action="''" {{-- Se asigna por JS --}}
    :method="'PUT'"
    :closeFn="'closeEditMaterialModal()'"
    :submitLabel="'Guardar'">
    <input name="titulo" id="edit_titulo" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none @error('titulo') border-red-500 @enderror">
    @error('titulo')
        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
    @enderror
    <textarea name="descripcion" id="edit_descripcion" rows="2" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 outline-none @error('descripcion') border-red-500 @enderror"></textarea>
    @error('descripcion')
        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
    @enderror
    <select name="tipo" id="edit_tipo" class="w-full border rounded-lg px-3 py-2 bg-white @error('tipo') border-red-500 @enderror">
        <option value="archivo">📄 Archivo</option>
        <option value="link">🔗 Link</option>
        <option value="video">▶️ Video</option>
    </select>
    @error('tipo')
        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
    @enderror
    <div id="editArchivoWrapper" class="hidden border-2 border-dashed rounded-lg p-4 text-center">
        <input type="file" name="archivo" id="editArchivoInput" class="hidden">
        <label for="editArchivoInput" class="cursor-pointer inline-flex items-center gap-2 px-4 py-2 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-100">
        📎 Cambiar archivo
        </label>
        <p id="editArchivoNombre" class="text-xs text-gray-500 mt-2"> Mantener archivo actual</p>
        @error('archivo')
            <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
        @enderror
    </div>
    <div id="editUrlWrapper" class="hidden">
        <input name="url_externa" id="edit_url" placeholder="URL externa" class="w-full border rounded-lg px-3 py-2 @error('url_externa') border-red-500 @enderror">
        @error('url_externa')
            <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
        @enderror
    </div>
</x-form-modal>
