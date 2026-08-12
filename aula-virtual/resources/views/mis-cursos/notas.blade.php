@extends('mis-cursos.partials.layout')

@section('title', ($course->title ?? 'Curso').' - Mis Notas')

@section('course-content')
    @include('mis-cursos.partials.notas', [
        'courseId' => $courseId,
    ])
@endsection

@push('scripts')
    @vite([
        'resources/js/ui.js',
        'resources/js/announcements.js',
        'resources/js/mis-cursos-notes.js',
        'resources/js/mis-cursos-session-detail.js'
    ])
@endpush
