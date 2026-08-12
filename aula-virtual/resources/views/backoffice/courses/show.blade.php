@extends('backoffice.courses.partials.layout')

@section('course-content')
    @include('backoffice.courses.partials.session', [
        'course'   => $course,
        'sessions' => $sessions,
        'session'  => $session
    ])
@endsection

@section('course-side')
    <span hidden>Comunidad disponible</span>
@endsection

@push('scripts')
@vite([
    'resources/js/ui.js',
    'resources/js/materials.js',
    'resources/js/announcements.js',
    'resources/js/video.js',
    'resources/js/evaluations.js',
    'resources/js/course-workspace.js'
])
@endpush
