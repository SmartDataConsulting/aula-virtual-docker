{{-- Layout base para vistas de autenticacion --}}
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  @php
    $defaultMetaDescription = 'Ingresa al Aula Virtual Smart Data para acceder a tus cursos y actividades.';
    $metaDescription = trim(preg_replace('/\s+/', ' ', strip_tags($__env->yieldContent('meta-description', $defaultMetaDescription))));
    $metaDescription = mb_substr($metaDescription !== '' ? $metaDescription : $defaultMetaDescription, 0, 160);
  @endphp
  <meta name="description" content="{{ $metaDescription }}">
  <title>@yield('title','Aula Virtual')</title>

  <!-- Fuente de Google + Bootstrap -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  @vite(['resources/css/auth.css', 'resources/js/app.js'])


  @stack('head')
</head>
<body>
  <x-global-loading />
  <main class="auth-wrapper">
    <div class="auth-card">
      @yield('content')
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
  @stack('scripts')
</body>
</html>
