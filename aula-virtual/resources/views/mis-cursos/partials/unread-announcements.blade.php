
{{-- =========================================
   ANUNCIO DE SESIÓN NO LEÍDO
========================================= --}}

@if(!empty($anuncioSesionNoLeido['pendiente']))

  @php $anuncio = $anuncioSesionNoLeido['pendiente']; @endphp

  <section class="announcement">
      <div class="announcement-card announcement-sesion">

          <div class="announcement-header">
              <span class="announcement-badge">
                  [ SESIÓN ] - {{ strtoupper($anuncio->title) }}
              </span>
          </div>

          <div class="announcement-content js-announcement-content">
            {{ $anuncio->content }}
          </div>

          <div class="announcement-actions js-announcement-actions">
            <a href="#"
            data-toggle-announcement-expand
            data-mark-announcement-read-id="{{ $anuncio->id }}">
                Detalles ›
            </a>
        </div>
      </div>
  </section>

@endif






 
