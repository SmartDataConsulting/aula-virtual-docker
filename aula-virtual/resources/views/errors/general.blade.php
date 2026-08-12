{{-- Vista de error generico --}}
@extends('layouts.main')

@section('title','Error')

@section('content')
  <div class="page-shell" style="padding:40px 24px;">
    <div class="bg-white p-6 rounded-xl card-shadow">
      <h1 class="text-xl font-semibold">Ocurrio un problema</h1>
      <p class="text-sm text-gray-600 mt-2">{{ $message }}</p>
      @if(!empty($correlation_id))
        <p class="text-xs text-gray-500 mt-4">Correlation ID: {{ $correlation_id }}</p>
      @endif
    </div>
  </div>
@endsection
