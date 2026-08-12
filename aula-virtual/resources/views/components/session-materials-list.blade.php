<div class="{{ $mode === 'student' ? 'student-materials-list' : 'card card-colored p-5 mb-6 space-y-4' }}">

    @if($mode !== 'student')
        <div class="font-semibold">
            Material de la sesión
        </div>
    @endif

    @forelse($materials ?? [] as $material)

        @php
            $type = (string) ($material->type ?? '');
            $mimeType = strtolower((string) ($material->mime_type ?? $material->mime ?? ''));
            $fileName = strtolower((string) ($material->file_name ?? $material->title ?? ''));
            $icon = '📄';
            $url = '#';
            $actionLabel = 'Descargar';
            $isDownload = true;

            if ($mimeType === '') {
                $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                $mimeType = match ($extension) {
                    'pdf' => 'application/pdf',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => '',
                };
            }

            $isPreviewable = $type === 'archivo'
                && ($mimeType === 'application/pdf' || str_starts_with($mimeType, 'image/'));

            if ($type === 'link') {
                $icon = '🔗';
                $url = $material->external_url;
                $actionLabel = 'Abrir';
                $isDownload = false;
            } elseif ($type === 'video') {
                $icon = '▶️';
                $url = $material->external_url;
                $actionLabel = 'Abrir';
                $isDownload = false;
            } else {
                if ($mimeType === 'application/pdf') {
                    $icon = 'PDF';
                } elseif (str_starts_with($mimeType, 'image/')) {
                    $icon = 'IMG';
                }

                $url = route('backoffice.courses.materials.download', [
                    'material' => $material->id
                ]);
            }

            $previewUrl = $isPreviewable
                ? route('backoffice.courses.materials.preview', ['material' => $material->id])
                : null;
        @endphp

        <div class="session-material relative flex flex-col gap-3 rounded-lg p-3 sm:flex-row sm:items-center"
             data-id="{{ $material->id }}"
             data-titulo="{{ $material->title }}"
             data-descripcion="{{ $material->description }}"
             data-tipo="{{ $type }}"
             data-url="{{ $material->external_url }}"
             @if($mode === 'admin')
                data-update-url="{{ route('backoffice.courses.materials.update', [$course->id, $session->id, $material->id]) }}"
             @endif
        >

            <div class="flex items-start gap-3 sm:flex-1">
                <div class="h-9 w-9 shrink-0 rounded bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-700">
                    {{ $icon }}
                </div>

                <div class="min-w-0 flex-1">
                    <div class="font-semibold text-slate-900">
                        {{ $material->title }}
                    </div>

                    @if(!empty($material->description))
                        <div class="text-xs text-gray-600">
                            {{ $material->description }}
                        </div>
                    @endif

                    <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-2 items-center">

                        <span class="uppercase">
                            {{ $type }}
                        </span>

                        @if(!empty($material->size))
                            <span>• {{ number_format($material->size / 1024, 1) }} KB</span>
                        @endif

                        @if($type === 'link' && !empty($material->external_url))
                            <span>• {{ parse_url($material->external_url, PHP_URL_HOST) }}</span>
                        @endif

                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 sm:ml-auto">
                @if($mode === 'admin')
                    <div class="menu-wrapper relative">
                        <button type="button"
                            data-toggle-material-menu
                            class="h-8 w-8 rounded-full hover:bg-slate-200 flex items-center justify-center"
                            aria-label="Mostrar opciones">
                            ⋮
                        </button>

                        <div class="menu hidden absolute right-0 top-full mt-2 bg-white border rounded-lg shadow-lg w-40 z-40">
                            <button type="button"
                                data-edit-material-id="{{ $material->id }}"
                                class="w-full px-4 py-2 text-sm hover:bg-gray-100">
                                Editar
                            </button>

                            <button type="button"
                                data-delete-material-id="{{ $material->id }}"
                                class="w-full px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                Eliminar
                            </button>
                        </div>
                    </div>
                @endif

                @if($isPreviewable)
                    <button type="button"
                        data-open-material-preview
                        data-preview-url="{{ $previewUrl }}"
                        data-download-url="{{ $url }}"
                        data-material-title="{{ $material->title }}"
                        data-material-type="{{ $mimeType }}"
                        class="badge bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full text-xs font-semibold">
                        Ver
                    </button>
                @else
                    <a href="{{ $url }}"
                       target="_blank"
                       rel="noopener"
                       class="badge bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full text-xs font-semibold">
                       {{ $actionLabel }}
                    </a>
                @endif
            </div>

        </div>

        @if($mode === 'admin')
            <form id="delete-material-{{ $material->id }}"
                  method="POST"
                  action="{{ route('backoffice.courses.materials.destroy', [$course->id, $session->id, $material->id]) }}"
                  class="hidden">
                @csrf
                @method('DELETE')
            </form>
        @endif

    @empty
        @if($mode === 'student')
            <div class="student-panel-empty">
                <strong>No se publicaron materiales</strong>
                <span>Los recursos compartidos por tu docente aparecerán aquí.</span>
            </div>
        @else
            <div class="text-sm text-gray-500">
                No hay materiales disponibles.
            </div>
        @endif
    @endforelse

</div>

<script>
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-open-material-preview]');
    if (!button) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();

    const modal = document.getElementById('materialPreviewModal');
    const title = document.getElementById('materialPreviewTitle');
    const image = document.getElementById('materialPreviewImage');
    const frame = document.getElementById('materialPreviewFrame');
    const unsupported = document.getElementById('materialPreviewUnsupported');
    const loading = document.getElementById('materialPreviewLoading');
    const download = document.getElementById('materialPreviewDownload');

    if (!modal || !title || !image || !frame || !unsupported || !loading || !download) {
        return;
    }

    const previewUrl = button.dataset.previewUrl || '#';
    const downloadUrl = button.dataset.downloadUrl || previewUrl;
    const materialTitle = button.dataset.materialTitle || 'Vista previa';

    title.textContent = materialTitle;
    download.href = downloadUrl;

    image.classList.add('hidden');
    frame.classList.add('hidden');
    unsupported.classList.add('hidden');
    loading.classList.remove('hidden');
    image.removeAttribute('src');
    frame.removeAttribute('src');

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');

    frame.onload = function () {
        loading.classList.add('hidden');
    };
    frame.onerror = function () {
        loading.classList.add('hidden');
        unsupported.classList.remove('hidden');
    };
    frame.src = previewUrl;
    frame.classList.remove('hidden');
}, true);

document.addEventListener('click', function (event) {
    if (!event.target.closest('[data-close-material-preview]')) {
        return;
    }

    const modal = document.getElementById('materialPreviewModal');
    const image = document.getElementById('materialPreviewImage');
    const frame = document.getElementById('materialPreviewFrame');

    image?.removeAttribute('src');
    frame?.removeAttribute('src');
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}, true);
</script>

<div id="materialPreviewModal"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4"
     role="dialog"
     aria-modal="true"
     aria-labelledby="materialPreviewTitle">
    <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div class="min-w-0">
                <h2 id="materialPreviewTitle" class="truncate text-lg font-semibold text-slate-900">
                    Vista previa
                </h2>
                <p class="text-sm text-slate-500">
                    Revisa el archivo antes de descargarlo.
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <a id="materialPreviewDownload"
                   href="#"
                   class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    Descargar
                </a>

                <button type="button"
                    data-close-material-preview
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Cerrar
                </button>
            </div>
        </div>

        <div class="min-h-[60vh] overflow-auto bg-slate-100 p-4">
            <div id="materialPreviewLoading"
                 class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
                Cargando vista previa...
            </div>

            <img id="materialPreviewImage"
                 src=""
                 alt=""
                 class="hidden mx-auto max-h-[74vh] max-w-full rounded-lg bg-white object-contain shadow-sm">

            <iframe id="materialPreviewFrame"
                    src=""
                    title="Vista previa del material"
                    class="hidden h-[74vh] w-full rounded-lg border border-slate-200 bg-white"></iframe>

            <div id="materialPreviewUnsupported"
                 class="hidden rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
                Este archivo no se puede previsualizar. Puedes descargarlo para abrirlo en tu equipo.
            </div>
        </div>
    </div>
</div>
