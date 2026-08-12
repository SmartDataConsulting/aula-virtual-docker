  {{-- Layout principal de la aplicacion --}}
  <!doctype html>
  <html lang="es">
  <head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @php
    $defaultMetaDescription = 'Plataforma de aprendizaje Smart Data para gestionar cursos, evaluaciones, encuestas y calificaciones.';
    $metaDescription = trim(preg_replace('/\s+/', ' ', strip_tags($__env->yieldContent('meta-description', $defaultMetaDescription))));
    $metaDescription = mb_substr($metaDescription !== '' ? $metaDescription : $defaultMetaDescription, 0, 160);
  @endphp
  <meta name="description" content="{{ $metaDescription }}">
  <title>@yield('title','Aula Virtual')</title>

  @vite([
  'resources/css/app.css',
  'resources/js/app.js'
])


  @stack('styles')
</head>
  @php
    $hideChrome = trim($__env->yieldContent('hide-app-chrome')) === '1';
  @endphp
  <body class="@yield('body-class','')">
    <div class="page-loader" data-page-loader hidden aria-hidden="true">
      <span></span>
    </div>
    <x-global-loading />
    <div class="min-h-screen flex relative">
      @unless($hideChrome)
      <div id="mobileBackdrop" class="mobile-backdrop" aria-hidden="true"></div>

      <nav id="mobileNav" class="mobile-nav" aria-hidden="true" aria-label="Menu principal movil" inert>
        <div class="flex items-center justify-between mb-6">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-indigo-700 rounded-md flex items-center justify-center text-white font-bold">SD</div>
            <div>
              <div class="font-semibold" style="color:var(--brand-dark)">Smart Data</div>
              <div class="text-xs" style="color:var(--gray-dark)">Aula Virtual</div>
            </div>
          </div>
          <button id="mobileClose" type="button" aria-label="Cerrar menu" title="Cerrar menu" class="mobile-close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <ul class="space-y-2">
          @foreach(\App\Support\Navigation::mainMenu() as $item)
            @php
              $isActive = !empty($item['active']) && request()->routeIs($item['active']);
              $href = '#';
              if (!empty($item['route']) && Route::has($item['route'])) {
                  $href = route($item['route'], $item['params'] ?? []);
              } elseif (!empty($item['url'])) {
                  $href = $item['url'];
              }
            @endphp
            <li>
              <a href="{{ $href }}"
                 class="block px-3 py-2 rounded-md {{ $isActive ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-700' }}"
                 @if(!empty($item['aria_label'])) aria-label="{{ $item['aria_label'] }}" @endif>{{ $item['label'] }}</a>
            </li>
          @endforeach
        </ul>
      </nav>
      @endunless

      <main class="flex-1 p-0">
        @unless($hideChrome)
        <header class="topbar" role="banner">
          <div class="topbar-inner">
            <button id="mobileToggle" type="button" class="mobile-toggle" aria-label="Abrir menu" aria-controls="mobileNav" aria-expanded="false">
              <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M3 6h14M3 10h14M3 14h14" stroke="#0A2540" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>

            <div class="brand" aria-label="Smart Data">
              <div class="brand-badge">
                <img
                    src="{{ asset('images/logo-small.png') }}"
                    alt="Smart Data"
                    class="brand-logo"
                    width="88"
                    height="50"
                    decoding="async"
                >
              </div>
              <span>Smart Data</span>
            </div>

            @include('partials.main-menu')

            <div class="user-menu">
              <button id="userToggle" class="user-avatar" aria-expanded="false" aria-haspopup="true" aria-controls="userDropdown">
                {{ strtoupper(substr(session('user_name', session('user_email', 'U')), 0, 1)) }}
              </button>
              <div id="userDropdown" class="user-dropdown" role="menu" aria-hidden="true" inert>
                <div class="user-dropdown-header">
                  <div class="user-dropdown-name">{{ session('user_name', 'Usuario') }}</div>
                  <div class="user-dropdown-email">{{ session('user_email', '') }}</div>
                </div>

                @if(session(\App\Support\AuthSessionKeys::USER_ROLE) === 'alumno')
                  <a href="{{ route('alumno.perfil.show') }}" class="user-dropdown-link" role="menuitem">
                    Ver mi perfil
                  </a>
                @endif

                <form method="POST" action="{{ route('logout') }}" style="margin:0;">
                  @csrf
                  <button type="submit">Cerrar sesion</button>
                </form>
              </div>
            </div>
          </div>
        </header>
        @endunless

        @yield('content')
      </main>
    </div>

    <x-confirm-modal />
    @stack('scripts')
  </body>
  </html>
