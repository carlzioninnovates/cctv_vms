<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-slate-900 antialiased">
@auth
<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-14 max-w-5xl items-center justify-between gap-4 px-4">
        <div class="flex items-center gap-6">
            <a href="{{ route('sites') }}" class="flex items-center gap-2" aria-label="{{ config('app.name') }} home">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M15 10l4.55-2.28A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.9L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </span>
                <span class="text-[15px] font-bold tracking-tight">Zi-on<span class="font-semibold text-brand-600"> VMS</span></span>
            </a>
            <nav class="flex items-center gap-1 text-sm">
                @foreach ([['sites', 'Sites', ['sites', 'site', 'live', 'nvr']], ['help', 'Help', ['help']]] as [$route, $label, $active])
                    <a href="{{ route($route) }}" @class(['rounded-md px-2.5 py-1.5 font-medium',
                        'bg-slate-100 text-slate-900' => request()->routeIs(...$active),
                        'text-slate-600 hover:text-slate-900' => ! request()->routeIs(...$active)])>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <span class="hidden text-right leading-tight text-slate-500 sm:block">
                <span class="block font-medium text-slate-700">{{ auth()->user()->name }}</span>
                <span class="text-xs">{{ auth()->user()->organization?->name }}</span>
            </span>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button class="rounded-md px-2 py-1 text-slate-600 hover:bg-slate-100">Sign out</button>
            </form>
        </div>
    </div>
</header>
@endauth
<div x-data="{ expired: false }" x-cloak x-show="expired" x-on:session-expired.window="expired = true"
     class="sticky top-14 z-20 border-b border-amber-200 bg-amber-50">
    <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-2 text-sm text-amber-900">
        <span>Your session has expired.</span>
        <form method="POST" action="{{ route('logout') }}">@csrf
            <button class="font-semibold underline">Sign in again</button>
        </form>
    </div>
</div>
<main class="mx-auto max-w-5xl px-4 py-6 sm:py-8">
    @yield('content')
</main>
</body>
</html>
