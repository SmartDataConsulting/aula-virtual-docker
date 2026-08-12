

@extends('mis-cursos.partials.layout')

@section('course-content')

<div class="mb-4">
    <a href="{{ route('mis-cursos.show', [
        $course->id,
        $session->id ?? null
    ]) }}">
        ← Volver
    </a>
</div>

    @include('mis-cursos.partials.announcements', [
        'course' => $course,
        'session' => $session ?? null,
        'announcements' => $announcements,
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
