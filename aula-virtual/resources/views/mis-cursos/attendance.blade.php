@extends('layouts.main')
@section('title', 'Mi asistencia | Aula Virtual')
@section('content')
<div class="student-attendance-page">
  <nav aria-label="Migas de pan"><a href="{{ route('mis-cursos.show', $courseId) }}">Volver al curso</a><span>/</span><span>Mi asistencia</span></nav>
  <header><div><span>Seguimiento personal</span><h1>Mi asistencia</h1><p>La asistencia se confirma cuando Zoom registra tu ingreso. No se aplican tardanzas ni tiempo mínimo.</p></div><div class="student-attendance-summary"><strong>{{ $items->where('status','asistio')->count() }}</strong><span>sesiones asistidas</span></div></header>
  @if($error)<div class="attendance-flash is-error" role="alert">{{ $error }}</div>@endif
  <div class="student-attendance-list">
    @forelse($items as $item)
      <article><div><span>Sesión {{ $item->session_number }}</span><strong>{{ $item->date ? \Carbon\Carbon::parse($item->date)->locale('es')->isoFormat('D [de] MMMM') : 'Fecha pendiente' }}</strong></div><span class="attendance-status is-{{ $item->status }}">{{ match($item->status) {'asistio'=>'Asistió','falta'=>'No asistió','justificada'=>'Justificada','no_aplica'=>'No aplica',default=>'Pendiente'} }}</span></article>
    @empty<div class="attendance-empty">Aún no hay sesiones conciliadas para este curso.</div>@endforelse
  </div>
</div>
@endsection
