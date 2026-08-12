@extends('layouts.main')

@section('title', 'Aula Virtual - Mi perfil')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@php
    $alumno = $alumno ?? null;
    $nombreCompleto = $alumno['nombre_completo'] ?? 'Alumno';
    $initials = collect(explode(' ', trim($nombreCompleto)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_substr($part, 0, 1))
        ->implode('');
    $initials = $initials !== '' ? mb_strtoupper($initials) : 'A';
    $empty = 'No registrado';
    $editing = $errors->any() || session()->has('profile_error');
    $adjuntos = $alumno['adjuntos'] ?? [];
    $fotoAdjunto = $adjuntos['foto'] ?? null;
    $cvAdjunto = $adjuntos['cv'] ?? null;
    $fotoSrc = $alumno['foto_data_uri'] ?? ($fotoAdjunto['url_descarga'] ?? ($alumno['foto_url'] ?? null));
    $solicitudesContacto = collect($solicitudesContacto ?? [])
        ->filter(fn ($solicitud) => strtoupper((string) data_get($solicitud, 'estado')) === 'PENDIENTE')
        ->values();

    $formatDate = function ($date) use ($empty): string {
        if (empty($date)) {
            return $empty;
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable $exception) {
            return $empty;
        }
    };

    $formatBytes = function ($bytes): string {
        if (!is_numeric($bytes) || (int) $bytes <= 0) {
            return '';
        }

        $bytes = (int) $bytes;

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        return number_format($bytes / 1024, 0) . ' KB';
    };

    $fieldValue = function (string $field, $fallback = null) use ($alumno) {
        return old($field, $alumno[$field] ?? $fallback);
    };

    $contactoPublico = $alumno ? (string) old('contacto_publico', (string) (int) $alumno['contacto_publico']) === '1' : false;
    $permiteSolicitudes = $alumno ? (string) old('permite_solicitudes_contacto', (string) (int) $alumno['permite_solicitudes_contacto']) === '1' : false;
@endphp

@section('content')
  <div
    data-profile-loading
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/45 px-4 backdrop-blur-sm"
    aria-live="polite"
    aria-modal="true"
    role="status"
  >
    <div class="flex w-full max-w-sm items-center gap-4 rounded-2xl border border-white/20 bg-white p-5 shadow-2xl">
      <div class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
        <svg class="h-6 w-6 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
          <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
        </svg>
      </div>
      <div>
        <p class="text-sm font-extrabold text-slate-900">Aplicando cambios</p>
        <p class="text-xs font-medium text-slate-500">Guardando tu perfil...</p>
      </div>
    </div>
  </div>

  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Mi perfil</h1>
        <p class="text-sm text-slate-500">Actualiza tu información personal y profesional</p>
      </div>

      @if($alumno)
        <div class="flex items-center gap-3">
          <button
            type="button"
            class="inline-flex items-center justify-center px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-bold shadow-sm hover:bg-blue-700 transition active:scale-95"
            data-profile-edit-button
            aria-expanded="{{ $editing ? 'true' : 'false' }}"
            @if($editing) hidden @endif
          >
            Editar perfil
          </button>

          <div class="flex items-center gap-3" data-profile-actions @if(!$editing) hidden @endif>
            <button
              type="button"
              class="px-5 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-700 text-sm font-semibold hover:bg-slate-50 transition"
              data-profile-cancel
            >
              Cancelar
            </button>
            <button
              type="submit"
              form="student-profile-form"
              class="px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-bold shadow-sm hover:bg-blue-700 transition active:scale-95"
            >
              Guardar cambios
            </button>
          </div>
        </div>
      @endif
    </div>

    {{-- Alerts --}}
    <div class="space-y-4 mb-6" data-profile-alerts>
      @if(session('profile_success'))
        <div class="p-4 rounded-xl bg-green-50 border border-green-200 text-green-700 text-sm font-medium" role="status">
          {{ session('profile_success') }}
        </div>
      @endif

      @if(session('profile_error'))
        <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-medium" role="alert">
          {{ session('profile_error') }}
        </div>
      @endif

      @if($errors->any())
        <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-medium" role="alert">
          {{ $errors->first() }}
        </div>
      @endif
    </div>

    @if(!empty($profileError))
      <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-10 text-center">
        <p class="text-slate-500">{{ $profileError }}</p>
      </section>
    @elseif($alumno)
      {{-- =================== EDIT VIEW =================== --}}
      <form
        id="student-profile-form"
        method="POST"
        action="{{ route('alumno.perfil.actualizar') }}"
        enctype="multipart/form-data"
        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6 lg:p-8"
        data-profile-edit
        @if(!$editing) hidden @endif
      >
        @csrf
        @method('PUT')

        {{-- Datos personales --}}
        <section>
          <div class="flex flex-col md:flex-row md:items-start gap-6">
            <div class="relative shrink-0 mx-auto md:mx-0">
              <div
                class="w-24 h-24 rounded-full overflow-hidden bg-blue-100 flex items-center justify-center"
                data-avatar-box
                data-avatar-original="{{ $fotoSrc }}"
                data-avatar-initials="{{ $initials }}"
                aria-hidden="true"
              >
                @if(!empty($fotoSrc))
                  <img src="{{ $fotoSrc }}" alt="" class="w-full h-full object-cover" onerror="this.hidden=true; this.nextElementSibling.hidden=false;">
                  <span hidden class="text-2xl font-bold text-blue-600">{{ $initials }}</span>
                @else
                  <span class="text-2xl font-bold text-blue-600">{{ $initials }}</span>
                @endif
              </div>

              <label for="foto_archivo" class="absolute bottom-0 right-0 w-8 h-8 flex items-center justify-center rounded-full bg-blue-600 text-white border-2 border-white shadow cursor-pointer hover:bg-blue-700 transition" title="Cambiar foto">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </label>
              <input id="foto_archivo" name="foto_archivo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" hidden data-file-input="foto">
            </div>

            <div class="flex-1 min-w-0">
              <h2 class="text-lg font-bold text-slate-900 mb-4">Datos personales</h2>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                  <label class="text-sm font-semibold text-slate-700">Nombre completo</label>
                  <div class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-600 text-sm truncate">
                    {{ $nombreCompleto }}
                  </div>
                </div>

                <div class="flex flex-col gap-1.5">
                  <label class="text-sm font-semibold text-slate-700" for="fecha_nacimiento">Fecha de nacimiento</label>
                  <input
                    id="fecha_nacimiento"
                    name="fecha_nacimiento"
                    type="date"
                    value="{{ old('fecha_nacimiento', $alumno['fecha_nacimiento_form'] ?? null) }}"
                    class="px-4 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none @error('fecha_nacimiento') border-red-500 @enderror"
                  >
                  @error('fecha_nacimiento')
                    <span class="text-xs text-red-600">{{ $message }}</span>
                  @enderror
                </div>
              </div>

              @error('foto_archivo')
                <span class="block text-xs text-red-600 mt-2">{{ $message }}</span>
              @enderror
            </div>
          </div>
        </section>

        <hr class="my-7 border-slate-200">

        {{-- Perfil profesional --}}
        <section>
          <h2 class="text-lg font-bold text-slate-900 mb-4">Perfil profesional</h2>

          <div class="space-y-4">
            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-semibold text-slate-700" for="presentacion_profesional">Presentación profesional</label>
              <textarea
                id="presentacion_profesional"
                name="presentacion_profesional"
                rows="4"
                maxlength="5000"
                placeholder="Cuéntanos sobre ti, tu experiencia, habilidades e intereses profesionales..."
                class="px-4 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-y @error('presentacion_profesional') border-red-500 @enderror"
              >{{ $fieldValue('presentacion_profesional') }}</textarea>
              @error('presentacion_profesional')
                <span class="text-xs text-red-600">{{ $message }}</span>
              @enderror
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-semibold text-slate-700" for="linkedin_url">LinkedIn (URL)</label>
              <input
                id="linkedin_url"
                name="linkedin_url"
                type="url"
                value="{{ $fieldValue('linkedin_url') }}"
                placeholder="https://www.linkedin.com/in/tu-perfil"
                class="px-4 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none @error('linkedin_url') border-red-500 @enderror"
              >
              @error('linkedin_url')
                <span class="text-xs text-red-600">{{ $message }}</span>
              @enderror
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-semibold text-slate-700" for="cv_archivo">CV adjunto</label>
              <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-3 border border-slate-200 rounded-xl bg-white">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-10 h-10 flex items-center justify-center border border-red-200 text-red-600 rounded-lg font-bold text-xs shrink-0" aria-hidden="true">PDF</div>
                  <div class="min-w-0">
                    <p class="text-sm font-semibold text-slate-800 truncate" data-file-name="cv" data-default="{{ $cvAdjunto['nombre_original'] ?? 'Sin CV adjunto' }}">
                      {{ $cvAdjunto['nombre_original'] ?? 'Sin CV adjunto' }}
                    </p>
                    <p class="text-xs text-slate-500">
                      {{ !empty($cvAdjunto['peso_bytes']) ? $formatBytes($cvAdjunto['peso_bytes']) : 'Formatos permitidos: PDF. Max. 10 MB.' }}
                    </p>
                  </div>
                </div>

                <input id="cv_archivo" name="cv_archivo" type="file" accept=".pdf,application/pdf" hidden data-file-input="cv">
                <label for="cv_archivo" class="inline-flex items-center justify-center px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold cursor-pointer hover:bg-slate-50 transition shrink-0">
                  {{ $cvAdjunto ? 'Reemplazar CV' : 'Adjuntar CV' }}
                </label>
              </div>

              @if(!empty($cvAdjunto['url_descarga']))
                <a class="text-xs text-blue-600 font-semibold inline-flex items-center gap-1 hover:underline" href="{{ $cvAdjunto['url_descarga'] }}">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  Descargar CV actual
                </a>
              @endif
              @error('cv_archivo')
                <span class="text-xs text-red-600">{{ $message }}</span>
              @enderror
            </div>
          </div>
        </section>

        <hr class="my-7 border-slate-200">

        {{-- Datos de contacto --}}
        <section>
          <h2 class="text-lg font-bold text-slate-900 mb-4">Datos de contacto</h2>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-semibold text-slate-700" for="correo">Correo personal</label>
                <input
                  id="correo"
                  name="correo"
                  type="email"
                  value="{{ $fieldValue('correo', $alumno['correo'] ?? '') }}"
                  placeholder="correo@ejemplo.com"
                  class="px-4 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none @error('correo') border-red-500 @enderror"
                >
                @error('correo')
                  <span class="text-xs text-red-600">{{ $message }}</span>
                @enderror
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-semibold text-slate-700" for="correo_corporativo">Correo corporativo (opcional)</label>
              <input
                id="correo_corporativo"
                name="correo_corporativo"
                type="email"
                value="{{ $fieldValue('correo_corporativo') }}"
                placeholder="correo@empresa.com"
                class="px-4 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none @error('correo_corporativo') border-red-500 @enderror"
              >
              @error('correo_corporativo')
                <span class="text-xs text-red-600">{{ $message }}</span>
              @enderror
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="text-sm font-semibold text-slate-700" for="telefono">Teléfono</label>
              <input
                id="telefono"
                name="telefono"
                type="text"
                maxlength="30"
                value="{{ $fieldValue('telefono') }}"
                placeholder="+51 999 999 999"
                class="px-4 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none @error('telefono') border-red-500 @enderror"
              >
              @error('telefono')
                <span class="text-xs text-red-600">{{ $message }}</span>
              @enderror
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4">
              <div>
                <p class="text-sm font-semibold text-slate-800">Contacto público</p>
                <p class="text-xs text-slate-500">Tu información será visible para otros usuarios.</p>
                @error('contacto_publico')
                  <span class="text-xs text-red-600">{{ $message }}</span>
                @enderror
              </div>
              <label class="relative inline-flex items-center cursor-pointer shrink-0">
                <input type="hidden" name="contacto_publico" value="0">
                <input type="checkbox" name="contacto_publico" value="1" class="sr-only peer" @checked($contactoPublico)>
                <div class="w-11 h-6 bg-slate-200 rounded-full peer peer-checked:bg-blue-600 transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-5"></div>
              </label>
            </div>

            <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4">
              <div>
                <p class="text-sm font-semibold text-slate-800">Permitir solicitudes de contacto</p>
                <p class="text-xs text-slate-500">Otros miembros pueden enviarte solicitudes.</p>
                @error('permite_solicitudes_contacto')
                  <span class="text-xs text-red-600">{{ $message }}</span>
                @enderror
              </div>
              <label class="relative inline-flex items-center cursor-pointer shrink-0">
                <input type="hidden" name="permite_solicitudes_contacto" value="0">
                <input type="checkbox" name="permite_solicitudes_contacto" value="1" class="sr-only peer" @checked($permiteSolicitudes)>
                <div class="w-11 h-6 bg-slate-200 rounded-full peer peer-checked:bg-blue-600 transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-5"></div>
              </label>
            </div>
          </div>
        </section>
      </form>

      {{-- =================== READ VIEW =================== --}}
      <div data-profile-read @if($editing) hidden @endif>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6 lg:p-8">
          {{-- Datos personales --}}
          <section>
            <div class="flex flex-col md:flex-row md:items-start gap-6">
              <div class="w-24 h-24 rounded-full overflow-hidden bg-blue-100 flex items-center justify-center shrink-0 mx-auto md:mx-0" aria-hidden="true" data-profile-read-avatar data-avatar-initials="{{ $initials }}">
                @if(!empty($fotoSrc))
                  <img src="{{ $fotoSrc }}" alt="" class="w-full h-full object-cover" onerror="this.hidden=true; this.nextElementSibling.hidden=false;">
                  <span hidden class="text-2xl font-bold text-blue-600">{{ $initials }}</span>
                @else
                  <span class="text-2xl font-bold text-blue-600">{{ $initials }}</span>
                @endif
              </div>

              <div class="flex-1 min-w-0">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Datos personales</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold text-slate-700">Nombre completo</span>
                    <div class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 text-sm truncate" data-profile-read-name>
                      {{ $nombreCompleto }}
                    </div>
                  </div>

                  <div class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold text-slate-700">Fecha de nacimiento</span>
                    <div class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 text-sm" data-profile-read-fecha>
                      {{ $formatDate($alumno['fecha_nacimiento_form'] ?? ($alumno['fecha_nacimiento'] ?? null)) }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <hr class="my-7 border-slate-200">

          {{-- Perfil profesional --}}
          <section>
            <h2 class="text-lg font-bold text-slate-900 mb-4">Perfil profesional</h2>

            <div class="space-y-4">
              <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold text-slate-700">Presentación profesional</span>
                <div class="min-h-24 px-4 py-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-700" data-profile-read-presentacion>
                  @if(!empty($alumno['presentacion_profesional']))
                    {!! nl2br(e($alumno['presentacion_profesional'])) !!}
                  @else
                    <span class="text-slate-400 italic">{{ $empty }}</span>
                  @endif
                </div>
              </div>

              <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold text-slate-700">LinkedIn (URL)</span>
                <div class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm truncate" data-profile-read-linkedin>
                  @if(!empty($alumno['linkedin_url']))
                    <a href="{{ $alumno['linkedin_url'] }}" target="_blank" rel="noopener" class="text-blue-600 font-semibold hover:underline">
                      {{ $alumno['linkedin_url'] }}
                    </a>
                  @else
                    <span class="text-slate-400 italic">{{ $empty }}</span>
                  @endif
                </div>
              </div>

              <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold text-slate-700">CV adjunto</span>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-3 border border-slate-200 rounded-xl bg-slate-50" data-profile-read-cv>
                  <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 flex items-center justify-center border border-red-200 text-red-600 bg-white rounded-lg font-bold text-xs shrink-0" aria-hidden="true">PDF</div>
                    <div class="min-w-0">
                      @if(!empty($cvAdjunto['url_descarga']))
                        <p class="text-sm font-semibold text-slate-800 truncate">{{ $cvAdjunto['nombre_original'] ?? 'CV adjunto' }}</p>
                        <p class="text-xs text-slate-500">{{ !empty($cvAdjunto['peso_bytes']) ? $formatBytes($cvAdjunto['peso_bytes']) : 'PDF adjunto' }}</p>
                      @elseif(!empty($alumno['cv_url']))
                        <p class="text-sm font-semibold text-slate-800 truncate">CV registrado</p>
                        <p class="text-xs text-slate-500">Enlace disponible</p>
                      @else
                        <p class="text-sm font-semibold text-slate-700">Sin CV adjunto</p>
                        <p class="text-xs text-slate-500">PDF. Máx. 10 MB.</p>
                      @endif
                    </div>
                  </div>

                  @if(!empty($cvAdjunto['url_descarga']))
                    <a href="{{ $cvAdjunto['url_descarga'] }}" class="inline-flex items-center justify-center px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition shrink-0">
                      Descargar CV
                    </a>
                  @elseif(!empty($alumno['cv_url']))
                    <a href="{{ $alumno['cv_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition shrink-0">
                      Ver CV
                    </a>
                  @endif
                </div>
              </div>
            </div>
          </section>

          <hr class="my-7 border-slate-200">

          {{-- Datos de contacto --}}
          <section>
            <h2 class="text-lg font-bold text-slate-900 mb-4">Datos de contacto</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
              <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold text-slate-700">Correo personal</span>
                <div class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 text-sm truncate" data-profile-read-correo>
                  {{ $alumno['correo'] ?: $empty }}
                </div>
              </div>

              <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold text-slate-700">Correo corporativo (opcional)</span>
                <div class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 text-sm truncate" data-profile-read-correo-corporativo>
                  {{ $alumno['correo_corporativo'] ?: $empty }}
                </div>
              </div>

              <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold text-slate-700">Teléfono</span>
                <div class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 text-sm" data-profile-read-telefono>
                  {{ $alumno['telefono'] ?: $empty }}
                </div>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 bg-slate-50">
                <div>
                  <p class="text-sm font-semibold text-slate-800">Contacto público</p>
                  <p class="text-xs text-slate-500">Tu información será visible para otros usuarios.</p>
                </div>
                <div class="relative inline-flex items-center shrink-0">
                  <span class="w-11 h-6 rounded-full transition-colors {{ $contactoPublico ? 'bg-blue-600' : 'bg-slate-200' }}" data-profile-read-contacto-track></span>
                  <span class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform {{ $contactoPublico ? 'translate-x-5' : '' }}" data-profile-read-contacto-knob></span>
                </div>
              </div>

              <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 bg-slate-50">
                <div>
                  <p class="text-sm font-semibold text-slate-800">Permitir solicitudes de contacto</p>
                  <p class="text-xs text-slate-500">Otros miembros pueden enviarte solicitudes.</p>
                </div>
                <div class="relative inline-flex items-center shrink-0">
                  <span class="w-11 h-6 rounded-full transition-colors {{ $permiteSolicitudes ? 'bg-blue-600' : 'bg-slate-200' }}" data-profile-read-solicitudes-track></span>
                  <span class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform {{ $permiteSolicitudes ? 'translate-x-5' : '' }}" data-profile-read-solicitudes-knob></span>
                </div>
              </div>
            </div>
          </section>

          <hr class="my-7 border-slate-200">

          <section>
            <h2 class="text-lg font-bold text-slate-900 mb-4">Solicitudes de contacto recibidas</h2>

            @if($solicitudesContacto->isEmpty())
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-500">
                No tienes solicitudes pendientes.
              </div>
            @else
              <div class="space-y-3">
                @foreach($solicitudesContacto as $solicitud)
                  @php
                    $nombreSolicitante = trim((string) data_get($solicitud, 'solicitante_nombre_completo', ''));
                    $nombreSolicitante = $nombreSolicitante !== '' ? $nombreSolicitante : data_get($solicitud, 'solicitante_correo', 'Alumno');
                    $mensajeSolicitud = trim((string) data_get($solicitud, 'mensaje', ''));
                    $fechaSolicitud = data_get($solicitud, 'fecha_solicitud');
                  @endphp
                  <article class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-contact-request-card>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                      <div>
                        <p class="text-sm font-bold text-slate-900">{{ $nombreSolicitante }}</p>
                        <p class="text-xs font-medium text-slate-500">{{ data_get($solicitud, 'solicitante_correo') }}</p>
                      </div>
                      <span class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1 text-xs font-extrabold text-blue-700" data-contact-request-status>
                        PENDIENTE
                      </span>
                    </div>
                    <p class="mt-3 text-sm text-slate-700">
                      {{ $mensajeSolicitud !== '' ? $mensajeSolicitud : 'Sin mensaje.' }}
                    </p>
                    @if(!empty($fechaSolicitud))
                      <p class="mt-2 text-xs font-medium text-slate-500">{{ $fechaSolicitud }}</p>
                    @endif
                    <div class="mt-4 flex flex-wrap items-center gap-2" data-contact-request-actions>
                      <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                        data-contact-request-action="ACEPTADA"
                        data-contact-request-url="{{ route('alumno.perfil.solicitudes.responder', ['solicitudId' => data_get($solicitud, 'id')]) }}"
                      >
                        Aceptar
                      </button>
                      <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        data-contact-request-action="RECHAZADA"
                        data-contact-request-url="{{ route('alumno.perfil.solicitudes.responder', ['solicitudId' => data_get($solicitud, 'id')]) }}"
                      >
                        Rechazar
                      </button>
                    </div>
                    <p class="mt-3 hidden text-sm font-semibold text-red-600" data-contact-request-error></p>
                  </article>
                @endforeach
              </div>
            @endif
          </section>
        </div>
      </div>
    @else
      <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-10 text-center">
        <p class="text-slate-500 italic">No se encontró información del alumno.</p>
      </section>
    @endif
  </div>
@endsection

@push('scripts')
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const editButton = document.querySelector('[data-profile-edit-button]');
      const actions = document.querySelector('[data-profile-actions]');
      const readView = document.querySelector('[data-profile-read]');
      const editView = document.querySelector('[data-profile-edit]');
      const alerts = document.querySelector('[data-profile-alerts]');
      const loadingOverlay = document.querySelector('[data-profile-loading]');
      const cancelButtons = document.querySelectorAll('[data-profile-cancel]');
      const emptyValue = @json($empty);

      if (!editButton || !actions || !readView || !editView || !cancelButtons.length) {
        return;
      }

      const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

      const isFilled = (value) => String(value ?? '').trim() !== '';

      const formatDateDisplay = (value) => {
        if (!isFilled(value)) {
          return emptyValue;
        }

        const text = String(value).trim();
        const isoMatch = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);

        if (isoMatch) {
          return `${isoMatch[3]}/${isoMatch[2]}/${isoMatch[1]}`;
        }

        return text;
      };

      const getAvatarSrc = (alumno = {}) => {
        return alumno.foto_data_uri
          || alumno.adjuntos?.foto?.url_descarga
          || alumno.foto_url
          || '';
      };

      const getInitials = (name) => {
        const initials = String(name || 'Alumno')
          .trim()
          .split(/\s+/)
          .filter(Boolean)
          .slice(0, 2)
          .map((part) => part[0])
          .join('')
          .toUpperCase();

        return initials || 'A';
      };

      const renderAvatar = (container, src, initials) => {
        if (!container) {
          return;
        }

        if (src && src !== 'null') {
          container.innerHTML = `<img src="${escapeHtml(src)}" alt="" class="w-full h-full object-cover" onerror="this.hidden=true; this.nextElementSibling.hidden=false;"><span hidden class="text-2xl font-bold text-blue-600">${escapeHtml(initials)}</span>`;
        } else {
          container.innerHTML = `<span class="text-2xl font-bold text-blue-600">${escapeHtml(initials)}</span>`;
        }
      };

      const resetFileLabels = () => {
        document.querySelectorAll('[data-file-name]').forEach((item) => {
          item.textContent = item.dataset.default || 'Sin archivo adjunto';
        });
      };

      const resetAvatar = () => {
        const avatarBox = document.querySelector('[data-avatar-box]');
        if (!avatarBox) {
          return;
        }

        const original = avatarBox.dataset.avatarOriginal;
        const initials = avatarBox.dataset.avatarInitials || 'A';

        renderAvatar(avatarBox, original, initials);
      };

      const setEditing = (isEditing) => {
        readView.hidden = isEditing;
        editView.hidden = !isEditing;
        editButton.hidden = isEditing;
        actions.hidden = !isEditing;
        editButton.setAttribute('aria-expanded', isEditing ? 'true' : 'false');

        if (isEditing) {
          editView.querySelector('textarea, input')?.focus();
        }
      };

      const setBusy = (isBusy) => {
        if (loadingOverlay) {
          loadingOverlay.classList.toggle('hidden', !isBusy);
          loadingOverlay.classList.toggle('flex', isBusy);
        }

        document.body.classList.toggle('overflow-hidden', isBusy);
        editView.querySelectorAll('button, input, textarea, label').forEach((element) => {
          if ('disabled' in element) {
            element.disabled = isBusy;
          }
          element.classList.toggle('pointer-events-none', isBusy);
        });
        editButton.disabled = isBusy;
        editButton.classList.toggle('pointer-events-none', isBusy);
      };

      const showAlert = (type, message) => {
        if (!alerts) {
          return;
        }

        const isSuccess = type === 'success';
        const classes = isSuccess
          ? 'bg-green-50 border-green-200 text-green-700'
          : 'bg-red-50 border-red-200 text-red-700';

        alerts.innerHTML = `
          <div class="flex items-center gap-3 p-4 rounded-xl border ${classes} text-sm font-medium" role="${isSuccess ? 'status' : 'alert'}">
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              ${isSuccess
                ? '<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>'
                : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path>'
              }
            </svg>
            <span>${escapeHtml(message)}</span>
          </div>
        `;
      };

      const setToggleState = (track, knob, enabled) => {
        track?.classList.toggle('bg-blue-600', enabled);
        track?.classList.toggle('bg-slate-200', !enabled);
        knob?.classList.toggle('translate-x-5', enabled);
      };

      const setContactRequestStatus = (card, estado) => {
        const status = card.querySelector('[data-contact-request-status]');
        const actions = card.querySelector('[data-contact-request-actions]');
        const normalized = String(estado || '').toUpperCase();
        const label = normalized === 'ACEPTADA'
          ? 'Aceptada'
          : (normalized === 'RECHAZADA' ? 'Rechazada' : normalized);

        if (status) {
          status.textContent = label || 'Actualizada';
          status.className = normalized === 'ACEPTADA'
            ? 'inline-flex w-fit rounded-full bg-green-50 px-3 py-1 text-xs font-extrabold text-green-700'
            : 'inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-extrabold text-slate-700';
        }

        actions?.classList.add('hidden');
      };

      const setContactRequestBusy = (card, busy) => {
        card.querySelectorAll('[data-contact-request-action]').forEach((button) => {
          button.disabled = busy;
        });

        const status = card.querySelector('[data-contact-request-status]');
        if (status && busy) {
          status.textContent = 'Actualizando...';
          status.className = 'inline-flex w-fit rounded-full bg-amber-50 px-3 py-1 text-xs font-extrabold text-amber-700';
        }
      };

      const respondContactRequest = async (button) => {
        const card = button.closest('[data-contact-request-card]');
        const url = button.dataset.contactRequestUrl;
        const estado = button.dataset.contactRequestAction;
        const errorBox = card?.querySelector('[data-contact-request-error]');

        if (!card || !url || !estado) {
          return;
        }

        if (errorBox) {
          errorBox.textContent = '';
          errorBox.classList.add('hidden');
        }

        setContactRequestBusy(card, true);

        try {
          const response = await fetch(url, {
            method: 'PUT',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ estado }),
            credentials: 'same-origin',
          });

          const payload = await response.json().catch(() => ({}));

          if (!response.ok || payload.ok === false) {
            throw new Error(payload.message || 'No se pudo actualizar la solicitud. Intenta nuevamente.');
          }

          setContactRequestStatus(card, payload.estado || estado);
        } catch (error) {
          setContactRequestBusy(card, false);

          const status = card.querySelector('[data-contact-request-status]');
          if (status) {
            status.textContent = 'PENDIENTE';
            status.className = 'inline-flex w-fit rounded-full bg-blue-50 px-3 py-1 text-xs font-extrabold text-blue-700';
          }

          if (errorBox) {
            errorBox.textContent = error.message || 'No se pudo actualizar la solicitud. Intenta nuevamente.';
            errorBox.classList.remove('hidden');
          }
        }
      };

      const updateReadView = (alumno) => {
        if (!alumno) {
          return;
        }

        const fullName = alumno.nombre_completo || 'Alumno';
        const initials = getInitials(fullName);
        const fotoSrc = getAvatarSrc(alumno);

        renderAvatar(document.querySelector('[data-profile-read-avatar]'), fotoSrc, initials);
        document.querySelector('[data-profile-read-name]').textContent = fullName;
        document.querySelector('[data-profile-read-fecha]').textContent = formatDateDisplay(alumno.fecha_nacimiento_form || alumno.fecha_nacimiento);

        const presentation = document.querySelector('[data-profile-read-presentacion]');
        if (presentation) {
          if (isFilled(alumno.presentacion_profesional)) {
            presentation.innerHTML = escapeHtml(alumno.presentacion_profesional).replace(/\n/g, '<br>');
          } else {
            presentation.innerHTML = `<span class="text-slate-400 italic">${escapeHtml(emptyValue)}</span>`;
          }
        }

        const linkedin = document.querySelector('[data-profile-read-linkedin]');
        if (linkedin) {
          if (isFilled(alumno.linkedin_url)) {
            linkedin.innerHTML = `<a href="${escapeHtml(alumno.linkedin_url)}" target="_blank" rel="noopener" class="text-blue-600 font-semibold hover:underline">${escapeHtml(alumno.linkedin_url)}</a>`;
          } else {
            linkedin.innerHTML = `<span class="text-slate-400 italic">${escapeHtml(emptyValue)}</span>`;
          }
        }

        document.querySelector('[data-profile-read-correo]').textContent = isFilled(alumno.correo) ? alumno.correo : emptyValue;
        document.querySelector('[data-profile-read-correo-corporativo]').textContent = isFilled(alumno.correo_corporativo) ? alumno.correo_corporativo : emptyValue;
        document.querySelector('[data-profile-read-telefono]').textContent = isFilled(alumno.telefono) ? alumno.telefono : emptyValue;

        updateReadCv(alumno);
        setToggleState(
          document.querySelector('[data-profile-read-contacto-track]'),
          document.querySelector('[data-profile-read-contacto-knob]'),
          Number(alumno.contacto_publico) === 1
        );
        setToggleState(
          document.querySelector('[data-profile-read-solicitudes-track]'),
          document.querySelector('[data-profile-read-solicitudes-knob]'),
          Number(alumno.permite_solicitudes_contacto) === 1
        );
      };

      const updateReadCv = (alumno) => {
        const container = document.querySelector('[data-profile-read-cv]');
        if (!container) {
          return;
        }

        const cv = alumno.adjuntos?.cv || null;
        const hasDownload = cv && isFilled(cv.url_descarga);
        const hasExternalUrl = !hasDownload && isFilled(alumno.cv_url);
        const title = hasDownload ? (cv.nombre_original || 'CV adjunto') : (hasExternalUrl ? 'CV registrado' : 'Sin CV adjunto');
        const subtitle = hasDownload ? 'PDF adjunto' : (hasExternalUrl ? 'Enlace disponible' : 'PDF. Max. 10 MB.');
        const href = hasDownload ? cv.url_descarga : alumno.cv_url;

        container.innerHTML = `
          <div class="flex items-center gap-3 min-w-0">
            <div class="w-10 h-10 flex items-center justify-center border border-red-200 text-red-600 bg-white rounded-lg font-bold text-xs shrink-0" aria-hidden="true">PDF</div>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-slate-800 truncate">${escapeHtml(title)}</p>
              <p class="text-xs text-slate-500">${escapeHtml(subtitle)}</p>
            </div>
          </div>
          ${isFilled(href)
            ? `<a href="${escapeHtml(href)}" ${hasExternalUrl ? 'target="_blank" rel="noopener"' : ''} class="inline-flex items-center justify-center px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition shrink-0">${hasDownload ? 'Descargar CV' : 'Ver CV'}</a>`
            : ''
          }
        `;
      };

      const setFieldValue = (selector, value) => {
        const field = editView.querySelector(selector);
        if (!field) {
          return;
        }

        field.value = value || '';
        field.defaultValue = value || '';
      };

      const updateEditView = (alumno) => {
        if (!alumno) {
          return;
        }

        const fullName = alumno.nombre_completo || 'Alumno';
        const initials = getInitials(fullName);
        const fotoSrc = getAvatarSrc(alumno);
        const avatarBox = document.querySelector('[data-avatar-box]');

        if (avatarBox) {
          avatarBox.dataset.avatarOriginal = fotoSrc || '';
          avatarBox.dataset.avatarInitials = initials;
          renderAvatar(avatarBox, fotoSrc, initials);
        }

        setFieldValue('[name="fecha_nacimiento"]', alumno.fecha_nacimiento_form || '');
        setFieldValue('[name="presentacion_profesional"]', alumno.presentacion_profesional || '');
        setFieldValue('[name="linkedin_url"]', alumno.linkedin_url || '');
        setFieldValue('[name="correo"]', alumno.correo || '');
        setFieldValue('[name="correo_corporativo"]', alumno.correo_corporativo || '');
        setFieldValue('[name="telefono"]', alumno.telefono || '');

        const contacto = editView.querySelector('input[type="checkbox"][name="contacto_publico"]');
        const solicitudes = editView.querySelector('input[type="checkbox"][name="permite_solicitudes_contacto"]');
        if (contacto) {
          contacto.checked = Number(alumno.contacto_publico) === 1;
          contacto.defaultChecked = contacto.checked;
        }
        if (solicitudes) {
          solicitudes.checked = Number(alumno.permite_solicitudes_contacto) === 1;
          solicitudes.defaultChecked = solicitudes.checked;
        }

        const cvName = document.querySelector('[data-file-name="cv"]');
        const cvDefault = alumno.adjuntos?.cv?.nombre_original || (alumno.cv_url ? 'CV registrado' : 'Sin CV adjunto');
        if (cvName) {
          cvName.dataset.default = cvDefault;
          cvName.textContent = cvDefault;
        }

        editView.querySelectorAll('[data-file-input]').forEach((input) => {
          input.value = '';
        });
      };

      const firstValidationMessage = (payload) => {
        const errors = payload?.errors || {};
        const firstKey = Object.keys(errors)[0];

        if (firstKey && Array.isArray(errors[firstKey]) && errors[firstKey].length) {
          return errors[firstKey][0];
        }

        return payload?.message || 'Revisa los datos ingresados.';
      };

      document.querySelectorAll('[data-file-input]').forEach((input) => {
        input.addEventListener('change', () => {
          const file = input.files?.[0];
          const target = document.querySelector(`[data-file-name="${input.dataset.fileInput}"]`);

          if (target) {
            target.textContent = file?.name || target.dataset.default || 'Sin archivo adjunto';
          }

          if (input.dataset.fileInput === 'foto' && file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
              const avatarBox = document.querySelector('[data-avatar-box]');
              if (avatarBox) {
                avatarBox.innerHTML = `<img src="${e.target.result}" alt="" class="w-full h-full object-cover">`;
              }
            };
            reader.readAsDataURL(file);
          }
        });
      });

      editButton.addEventListener('click', () => setEditing(true));

      cancelButtons.forEach((button) => {
        button.addEventListener('click', () => {
          editView.reset();
          resetFileLabels();
          resetAvatar();
          setEditing(false);
        });
      });

      document.querySelectorAll('[data-contact-request-action]').forEach((button) => {
        button.addEventListener('click', () => respondContactRequest(button));
      });

      editView.addEventListener('submit', async (event) => {
        event.preventDefault();

        const formData = new FormData(editView);
        setBusy(true);

        try {
          const response = await fetch(editView.action, {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
            credentials: 'same-origin',
          });

          const payload = await response.json().catch(() => ({}));

          if (!response.ok || payload.ok === false) {
            showAlert('error', response.status === 422
              ? firstValidationMessage(payload)
              : (payload.message || 'No se pudo actualizar el perfil. Intenta nuevamente.'));
            setEditing(true);
            return;
          }

          updateReadView(payload.alumno);
          updateEditView(payload.alumno);
          resetFileLabels();
          setEditing(false);
          showAlert('success', payload.message || 'Perfil actualizado correctamente.');
        } catch (error) {
          showAlert('error', 'No se pudo actualizar el perfil. Intenta nuevamente.');
          setEditing(true);
        } finally {
          setBusy(false);
        }
      });
    });
  </script>
@endpush
