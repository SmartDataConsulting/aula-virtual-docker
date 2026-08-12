@extends('backoffice.courses.partials.layout')

@push('scripts')
    @vite([
        'resources/js/ui.js',
        'resources/js/announcements.js'
    ])
@endpush

@section('course-content')

    <div class="mb-4">
        <a href="{{ route('backoffice.courses.show', [
            $course->id,
            $session->id ?? null
        ]) }}">
            ← Volver
        </a>
    </div>

    <x-announcements-list 
        :course="$course"
        :announcements="$announcements"
        mode="edit" />
@endsection
