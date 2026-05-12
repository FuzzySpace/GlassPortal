@extends('layouts.public')

@section('title', 'Sign In')

@push('styles')
<style>
    .login-wrap {
        min-height: calc(100vh - 60px);
        display: flex; align-items: center; justify-content: center;
        padding: 2rem 1rem;
    }
    .login-card {
        width: 100%; max-width: 380px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 0.5rem; padding: 2rem;
    }
    .login-title { font-size: 1.1rem; font-weight: 700; color: var(--text-h); margin-bottom: 1.5rem; }
    .form-group { margin-bottom: 1rem; }
    label { display: block; font-size: 0.8rem; font-weight: 500; color: var(--text-dim); margin-bottom: 0.35rem; }
    input[type="email"], input[type="password"] {
        width: 100%; padding: 0.55rem 0.75rem; border-radius: 0.375rem;
        border: 1px solid var(--border); background: var(--bg);
        color: var(--text-h); font-size: 0.9rem; outline: none;
        transition: border-color 0.15s;
    }
    input:focus { border-color: var(--accent); }
    .error-msg { font-size: 0.75rem; color: var(--danger); margin-top: 0.3rem; }
    .remember { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; color: var(--text-dim); }
    .submit-btn {
        width: 100%; margin-top: 1.25rem; padding: 0.6rem;
        border-radius: 0.375rem; border: none;
        background: var(--accent-d); color: #fff; font-weight: 600;
        font-size: 0.9rem; cursor: pointer; transition: background 0.15s;
    }
    .submit-btn:hover { background: var(--accent); }
</style>
@endpush

@section('content')
<div class="login-wrap">
    <div class="login-card">
        <div class="login-title">Sign in to GlassPortal</div>

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="form-group">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email"
                    value="{{ old('email') }}"
                    autocomplete="email" autofocus required>
                @error('email')
                    <div class="error-msg">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                    autocomplete="current-password" required>
                @error('password')
                    <div class="error-msg">{{ $message }}</div>
                @enderror
            </div>

            <label class="remember">
                <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}>
                Remember me
            </label>

            <button type="submit" class="submit-btn">Sign in</button>
        </form>
    </div>
</div>
@endsection
