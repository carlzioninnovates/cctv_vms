@extends('layouts.app')
@section('title', 'Live · '.$siteName)
@section('content')
<div x-data="livePage({{ $siteId }})" x-cloak class="-mx-4 sm:mx-0">
    {{-- Toolbar --}}
    <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-3 px-4 sm:px-0">
        <div class="mr-auto min-w-0">
            <a href="{{ route('site', $siteId) }}" class="text-sm text-slate-500 hover:text-slate-700">← {{ $siteName }}</a>
            <h1 class="text-xl font-semibold sm:text-2xl">Live view</h1>
        </div>
        <div class="flex items-center gap-2">
            <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5 text-sm" role="group" aria-label="Video mode">
                <button class="rounded-md px-3 py-1.5 font-medium" :class="$store.live?.mode === 'live' ? 'bg-slate-900 text-white' : 'text-slate-600'"
                        @click="$store.live.mode = 'live'">Live</button>
                <button class="rounded-md px-3 py-1.5 font-medium" :class="$store.live?.mode === 'lowdata' ? 'bg-slate-900 text-white' : 'text-slate-600'"
                        @click="$store.live.mode = 'lowdata'" title="Still images every few seconds — uses far less mobile data">Low data</button>
            </div>
            <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5" role="group" aria-label="Grid size">
                @foreach ([1 => '<rect x="1" y="1" width="14" height="14" rx="2"/>',
                           4 => '<path d="M1 1h6v6H1zM9 1h6v6H9zM1 9h6v6H1zM9 9h6v6H9z"/>',
                           9 => '<path d="M1 1h4v4H1zM6 1h4v4H6zM11 1h4v4h-4zM1 6h4v4H1zM6 6h4v4H6zM11 6h4v4h-4zM1 11h4v4H1zM6 11h4v4H6zM11 11h4v4h-4z"/>'] as $n => $shape)
                    <button class="rounded-md px-2.5 py-1.5 text-sm font-medium" :class="$store.live?.layout === {{ $n }} ? 'bg-slate-900 text-white' : 'text-slate-600'"
                            @click="setLayout({{ $n }})" aria-label="{{ $n }} {{ $n === 1 ? 'camera' : 'cameras' }} per page">
                        <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor">{!! $shape !!}</svg>
                    </button>
                @endforeach
            </div>
        </div>
        <div class="flex w-full items-center justify-between gap-3 text-xs text-slate-500 sm:w-auto">
            <select x-show="nvrs.length > 1" x-model="nvrFilter" @change="page = 0" class="input w-auto py-1.5 text-sm">
                <option value="">All NVRs</option>
                <template x-for="[id, name] in nvrs" :key="id"><option :value="id" x-text="name"></option></template>
            </select>
            <span x-text="dataHint" title="Rough mobile data use for what's on screen"></span>
        </div>
    </div>

    <template x-if="loading"><div class="py-16 text-center text-slate-400">Loading cameras…</div></template>
    <template x-if="error"><div class="mx-4 rounded-lg bg-red-50 p-4 text-sm text-red-700 sm:mx-0" x-text="error"></div></template>
    <template x-if="!loading && !error && cameras.length === 0">
        <div class="card mx-4 px-6 py-14 text-center sm:mx-0">
            <h2 class="font-semibold">No cameras yet</h2>
            <p class="mt-1 text-sm text-slate-500">Cameras appear here once an NVR on this site is connected.</p>
            <a href="{{ route('site', $siteId) }}" class="btn-primary mt-5">Go to site setup</a>
        </div>
    </template>

    {{-- Grid --}}
    <div class="grid gap-1 sm:gap-2" :class="gridClass">
        <template x-for="cam in visible" :key="cam.id">
            <div x-data="cameraTile(cam)" class="group relative aspect-video cursor-pointer overflow-hidden bg-slate-900 sm:rounded-lg"
                 @click="focus(cam)" role="button" :aria-label="`Open ${cam.name}`">
                <video x-ref="video" class="h-full w-full object-contain" x-show="shouldStream" muted playsinline></video>
                <img x-show="!shouldStream && cam.nvr_online && !snapFailed" :src="shouldSnapshot ? snapshotSrc : null" alt=""
                     class="h-full w-full object-contain" x-on:error="snapFailed = true" x-on:load="snapFailed = false" loading="lazy">

                {{-- Placeholder states --}}
                <div x-show="!cam.nvr_online" class="absolute inset-0 flex items-center justify-center text-sm text-slate-400">NVR offline</div>
                <div x-show="cam.nvr_online && !shouldStream && snapFailed" class="absolute inset-0 flex items-center justify-center text-sm text-slate-400">No image</div>
                <div x-show="state === 'connecting'" class="absolute inset-0 flex items-center justify-center">
                    <svg class="h-7 w-7 animate-spin text-white/70" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                </div>
                <div x-show="state === 'error' && shouldStream" class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-slate-900/80 px-4 text-center">
                    <p class="text-sm font-medium text-white" x-text="errorText"></p>
                    <p class="text-xs text-slate-400" x-show="errorKind === 'codec'">Set this camera's substream to H.264 on the NVR.</p>
                    <button class="mt-1 text-xs font-semibold text-sky-300 hover:underline" x-show="retryIn > 0" @click.stop="retryNow()"
                            x-text="`Retrying in ${retryIn}s — retry now`"></button>
                </div>

                {{-- Overlay --}}
                <div class="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between bg-gradient-to-b from-black/60 to-transparent p-2">
                    <span class="truncate text-xs font-medium text-white sm:text-sm" x-text="cam.name"></span>
                    <span x-show="state === 'live'" class="badge bg-red-600 px-1.5 text-[10px] font-bold uppercase tracking-wide text-white">
                        <span class="dot bg-white"></span>Live
                    </span>
                    <span x-show="!shouldStream && cam.nvr_online" class="badge bg-black/50 px-1.5 text-[10px] text-white/80">Snapshot</span>
                </div>
                <span x-show="cam.ptz" class="pointer-events-none absolute bottom-2 left-2 badge bg-black/50 px-1.5 text-[10px] text-white/80">PTZ</span>
            </div>
        </template>
    </div>

    {{-- Pager --}}
    <div x-show="pages > 1" class="mt-4 flex items-center justify-center gap-3 text-sm">
        <button class="btn-secondary px-3 py-1.5" :disabled="page === 0" @click="page--">← Prev</button>
        <span class="text-slate-500" x-text="`Page ${Math.min(page, pages - 1) + 1} of ${pages}`"></span>
        <button class="btn-secondary px-3 py-1.5" :disabled="page >= pages - 1" @click="page++">Next →</button>
    </div>

    {{-- Focused camera --}}
    <template x-for="fcam in ($store.live?.focused ? [$store.live.focused] : [])" :key="fcam.id">
        <div x-data="cameraTile(fcam, { focus: true })" class="fixed inset-0 z-50 flex flex-col bg-black" role="dialog" aria-modal="true">
            <div class="flex items-center justify-between gap-3 px-4 py-3 text-white">
                <div class="min-w-0">
                    <div class="truncate font-semibold" x-text="cam.name"></div>
                    <div class="truncate text-xs text-white/60" x-text="cam.nvr_name + (via ? ' · ' + (via === 'webrtc' ? 'real-time' : 'HLS, few seconds delay') : '')"></div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="inline-flex rounded-lg bg-white/10 p-0.5 text-xs font-semibold">
                        <button class="rounded-md px-2.5 py-1" :class="quality === 'sub' ? 'bg-white text-black' : 'text-white/80'" @click="setQuality('sub')">SD</button>
                        <button class="rounded-md px-2.5 py-1" :class="quality === 'main' ? 'bg-white text-black' : 'text-white/80'" @click="setQuality('main')"
                                title="Higher quality, more data">HD</button>
                    </div>
                    <button class="rounded-lg p-2 hover:bg-white/10" @click="fullscreen()" aria-label="Full screen">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7V3h4M13 3h4v4M17 13v4h-4M7 17H3v-4"/></svg>
                    </button>
                    <button class="rounded-lg p-2 hover:bg-white/10" @click="$store.live.focused = null" aria-label="Close">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l10 10M15 5L5 15"/></svg>
                    </button>
                </div>
            </div>
            <div class="relative flex-1">
                <video x-ref="video" class="absolute inset-0 h-full w-full object-contain" muted playsinline></video>
                <div x-show="state === 'connecting'" class="absolute inset-0 flex items-center justify-center">
                    <svg class="h-9 w-9 animate-spin text-white/70" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                </div>
                <div x-show="state === 'error'" class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center">
                    <p class="font-medium text-white" x-text="errorText"></p>
                    <p class="text-sm text-white/60" x-show="errorKind === 'codec'">Set this camera's stream to H.264 on the NVR, or use SD.</p>
                    <button class="mt-1 text-sm font-semibold text-sky-300 hover:underline" x-show="retryIn > 0" @click="retryNow()"
                            x-text="`Retrying in ${retryIn}s — retry now`"></button>
                </div>

                {{-- PTZ pad: press and hold --}}
                <template x-if="cam.ptz && canPtz">
                    <div class="absolute bottom-4 right-4 flex select-none items-end gap-3" @pointerup.window="ptzStop()" @contextmenu.prevent>
                        <div class="grid grid-cols-3 grid-rows-3 gap-1 rounded-full bg-black/50 p-2 backdrop-blur">
                            <template x-for="[a, pos, path] in [['up','col-start-2','M10 5l5 6H5z'],['left','col-start-1 row-start-2','M5 10l6-5v10z'],['right','col-start-3 row-start-2','M15 10l-6 5V5z'],['down','col-start-2 row-start-3','M10 15l-5-6h10z']]" :key="a">
                                <button class="flex h-11 w-11 items-center justify-center rounded-full text-white hover:bg-white/20 active:bg-white/30" :class="pos"
                                        @pointerdown="ptzStart(a, $event)" @pointerleave="ptzStop()" @pointercancel="ptzStop()" :aria-label="`Move ${a}`">
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path :d="path"/></svg>
                                </button>
                            </template>
                        </div>
                        <div class="flex flex-col gap-1 rounded-full bg-black/50 p-2 backdrop-blur">
                            <button class="flex h-11 w-11 items-center justify-center rounded-full text-xl font-semibold text-white hover:bg-white/20" @pointerdown="ptzStart('zoom_in', $event)" @pointerleave="ptzStop()" aria-label="Zoom in">+</button>
                            <button class="flex h-11 w-11 items-center justify-center rounded-full text-xl font-semibold text-white hover:bg-white/20" @pointerdown="ptzStart('zoom_out', $event)" @pointerleave="ptzStop()" aria-label="Zoom out">−</button>
                        </div>
                    </div>
                </template>
            </div>
            <div class="px-4 py-2 text-center text-xs text-white/50" x-text="quality === 'main' ? focusedHint : 'SD · low data'"></div>
        </div>
    </template>
</div>
@endsection
