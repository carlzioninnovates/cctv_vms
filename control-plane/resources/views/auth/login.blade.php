@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<div class="mx-auto mt-10 max-w-sm sm:mt-20">
    <div class="mb-6 text-center">
        <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-white">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 10l4.55-2.28A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.9L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
        </div>
        <h1 class="text-2xl font-bold tracking-tight">Zi-on<span class="text-brand-600"> VMS</span></h1>
        <p class="mt-1 text-sm text-slate-500">Watch and manage your CCTV from anywhere</p>
    </div>
    <form method="POST" action="/login" class="card space-y-4 p-6">
        @csrf
        <div>
            <label class="label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        </div>
        <div>
            <label class="label" for="password">Password</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        @error('email')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>@enderror
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" class="rounded border-slate-300 text-brand-600"> Keep me signed in
        </label>
        <button class="btn-primary w-full">Sign in</button>
    </form>
</div>
@endsection
