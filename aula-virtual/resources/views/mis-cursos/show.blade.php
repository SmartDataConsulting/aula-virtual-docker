@extends('mis-cursos.partials.layout')

@section('title', ($course->title ?? 'Curso').' - Contenido')

@section('course-content')
  @include('mis-cursos.partials.session')
@endsection

@push('scripts')
  @vite([
    'resources/js/ui.js',
    'resources/js/chat.js',
    'resources/js/materials.js',
    'resources/js/announcements.js',
    'resources/js/mis-cursos-notes.js',
    'resources/js/video.js',
    'resources/js/course-workspace.js'
  ])
@endpush
