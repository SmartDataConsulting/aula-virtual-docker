{{-- Vista de inicio de sesion --}}
@extends('layouts.app')

@section('title','Iniciar sesión')

@section('content')
  <div class="text-center">
      <div class="auth-badge">
    <img src="{{ asset('images/logo.png') }}" alt="Smart Data" class="auth-logo">
  </div>
    <h1 class="auth-title">Aula Virtual</h1>
    <p class="auth-sub">Ingresa a tu cuenta personal</p>
  </div>

  @if ($errors->any())
    <div class="alert alert-danger small">
      <ul class="mb-0">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <form method="POST" action="{{ route('auth.login.post') }}">
    @csrf

    <div class="mb-3">
      <label class="form-label small">Usuario</label>
      <div class="input-group">
        <span class="input-group-text input-icon">
          <!-- icono mail (inline svg) -->
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8.25v8.25A2.25 2.25 0 005.25 19.5h13.5A2.25 2.25 0 0021 17.25V8.25" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 6.75a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6.75V6l9 5.25L21 6v.75z" />
          </svg>
        </span>
        <input id="username" name="username" type="text" class="form-control" placeholder="alumnoprueba" required value="{{ old('username') }}">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label small">Contraseña</label>
      <div class="input-group">
        <span class="input-group-text input-icon">
          <!-- icono lock -->
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <rect x="3" y="11" width="18" height="10" rx="2"></rect>
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 11V8a5 5 0 0110 0v3"></path>
          </svg>
        </span>
        <input id="password" name="password" type="password" class="form-control" placeholder="••••••••" @unless(filter_var(env('WP_AUTH_BYPASS', false), FILTER_VALIDATE_BOOLEAN)) required @endunless>
      </div>
    </div>

    <div class="d-grid mt-3">
      <button type="submit" class="btn btn-login">Iniciar Sesión</button>
    </div>

 
  </form>
@endsection
