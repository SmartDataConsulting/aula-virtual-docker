<section class="student-panel-section">
  <div class="student-panel-heading"><div><h3>Anuncios de la sesión</h3><p>Novedades e indicaciones publicadas por tu docente.</p></div></div>
  @if(collect($announcements ?? [])->isEmpty())
    <div class="student-panel-empty"><strong>No hay anuncios nuevos para esta sesión</strong><span>Las novedades publicadas por tu docente aparecerán aquí.</span></div>
  @else
    @include('mis-cursos.partials.announcements', ['announcements' => $announcements])
  @endif
</section>
