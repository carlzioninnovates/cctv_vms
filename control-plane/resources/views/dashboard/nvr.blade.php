@extends('layouts.app')
@section('title', $nvrName)
@section('content')
<div x-data="nvrPage({{ $nvrId }})" x-cloak>
    <template x-if="loading"><div class="py-16 text-center text-slate-400">Loading…</div></template>
    <template x-if="error && !nvr"><div class="rounded-lg bg-red-50 p-4 text-sm text-red-700" x-text="error"></div></template>

    <template x-if="nvr">
    <div>
        <a :href="`/sites/${nvr.site_id}`" class="text-sm text-slate-500 hover:text-slate-700" x-text="'← ' + nvr.site_name"></a>
        <div class="mb-6 mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold" x-text="nvr.name"></h1>
                <p class="text-sm text-slate-500" x-text="[BRANDS[nvr.brand]?.label, nvr.model, nvr.lan_host].filter(Boolean).join(' · ')"></p>
            </div>
            <span class="badge text-sm" :class="nvrStatus(nvr.status).cls">
                <span class="dot" :class="nvrStatus(nvr.status).dot"></span><span x-text="nvrStatus(nvr.status).label"></span>
            </span>
        </div>

        {{-- Connecting: live progress --}}
        <template x-if="nvr.status === 'onboarding'">
            <section class="card mb-6 p-6">
                <h2 class="font-semibold">Connecting to the NVR</h2>
                <p class="mt-1 text-sm text-slate-500">Usually 10–40 seconds. You can leave this page; it keeps going.</p>
                <ol class="mt-5 space-y-3">
                    <template x-for="s in ONBOARDING_STEPS" :key="s.key">
                        <li class="flex items-center gap-3 text-sm">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                  :class="{ done: 'bg-brand-600 text-white', active: 'bg-sky-100', waiting: 'bg-slate-100', todo: 'bg-slate-100' }[stepState(s.key)]">
                                <template x-if="stepState(s.key) === 'done'"><svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 011.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg></template>
                                <template x-if="['active','waiting'].includes(stepState(s.key))"><svg class="h-4 w-4 animate-spin text-sky-600" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg></template>
                            </span>
                            <span :class="{ done: 'text-slate-500', active: 'font-medium text-slate-900', waiting: 'text-slate-700', todo: 'text-slate-400' }[stepState(s.key)]"
                                  x-text="s.label + (stepState(s.key) === 'waiting' ? ' (queued…)' : '')"></span>
                        </li>
                    </template>
                </ol>
                <p x-show="slow" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">Taking longer than usual — slow site internet can do this.</p>
            </section>
        </template>

        {{-- Failed --}}
        <template x-if="nvr.status === 'failed' && fix">
            <section class="mb-6 rounded-xl border border-red-200 bg-red-50 p-5">
                <h2 class="font-semibold text-red-800" x-text="fix.title"></h2>
                <p class="mt-1 text-sm text-red-700" x-text="nvr.error.message"></p>
                <a href="{{ route('help') }}#if-adding-an-nvr-fails" target="_blank" class="mt-1 inline-block text-sm font-medium text-red-800 underline">Troubleshooting guide</a>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-red-900">
                    <template x-for="s in fix.steps"><li x-text="s"></li></template>
                </ul>

                <template x-if="fix.action === 'credentials'">
                    <form @submit.prevent="saveCredentials()" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <div><label class="label">Username</label><input class="input bg-white" x-model="creds.username" required></div>
                        <div>
                            <label class="label">Password</label>
                            <div class="relative">
                                <input class="input bg-white pr-14" :type="showPassword ? 'text' : 'password'" x-model="creds.password" required autocomplete="new-password">
                                <button type="button" class="absolute inset-y-0 right-0 px-3 text-xs text-slate-500" @click="showPassword = !showPassword" x-text="showPassword ? 'Hide' : 'Show'"></button>
                            </div>
                        </div>
                        <button class="btn-primary" :disabled="busy">Save &amp; retry</button>
                    </form>
                </template>
                <template x-if="fix.action === 'retry'">
                    <button class="btn-primary mt-4" @click="retry()" :disabled="busy">Retry</button>
                </template>
                <p x-show="actionError" class="mt-3 text-sm font-medium text-red-800" x-text="actionError"></p>
            </section>
        </template>

        {{-- Advisories --}}
        <template x-if="nvr.advisories.length">
            <section class="mb-6 rounded-xl border border-red-200 bg-white p-5">
                <h2 class="flex items-center gap-2 font-semibold text-red-700">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1.94l7 3.11v4.48c0 4.23-2.88 8.12-7 9.53-4.12-1.41-7-5.3-7-9.53V5.05l7-3.11zM10 6a1 1 0 00-1 1v3a1 1 0 002 0V7a1 1 0 00-1-1zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                    Firmware update needed
                </h2>
                <ul class="mt-2 space-y-2 text-sm">
                    <template x-for="a in nvr.advisories" :key="a.cve">
                        <li><span class="font-mono font-semibold" x-text="a.cve"></span>
                            <span class="badge ml-1 bg-red-100 uppercase text-red-700" x-text="a.severity"></span>
                            <span class="text-slate-700" x-text="' — ' + a.summary"></span>
                            <a x-show="a.reference_url" :href="a.reference_url" target="_blank" rel="noopener" class="text-brand-700 hover:underline">Vendor notice</a></li>
                    </template>
                </ul>
            </section>
        </template>

        {{-- Warnings --}}
        <template x-if="nvr.warnings.length">
            <section class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
                <h2 class="font-semibold text-amber-900">Worth fixing</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                    <template x-for="w in nvr.warnings"><li x-text="w"></li></template>
                </ul>
            </section>
        </template>

        {{-- Device --}}
        <template x-if="nvr.status === 'active' || nvr.probed_at">
            <section class="card mb-6 p-5">
                <h2 class="mb-3 font-semibold">Device</h2>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-slate-500">Model</dt><dd class="truncate font-medium" x-text="nvr.model || '—'"></dd></div>
                    <div><dt class="text-slate-500">Serial</dt><dd class="truncate font-mono text-xs" x-text="nvr.serial || '—'"></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Firmware</dt><dd class="truncate font-medium" x-text="nvr.firmware || '—'"></dd></div>
                    <div><dt class="text-slate-500">Clock</dt>
                        <dd class="font-medium" :class="Math.abs(nvr.clock_drift_seconds ?? 0) > 30 ? 'text-amber-700' : ''"
                            x-text="nvr.clock_drift_seconds === null ? '—' : (Math.abs(nvr.clock_drift_seconds) <= 30 ? 'In sync' : (nvr.clock_drift_seconds > 0 ? '+' : '') + nvr.clock_drift_seconds + 's off')"></dd></div>
                    <div><dt class="text-slate-500">Checked</dt><dd class="font-medium" x-text="timeAgo(nvr.probed_at)"></dd></div>
                </dl>
                <div class="mt-4 flex flex-wrap gap-2" x-show="nvr.features">
                    <template x-for="[k, label] in [['playback','Playback'],['events','Alerts'],['hdd_health','HDD health'],['onvif','ONVIF / PTZ']]" :key="k">
                        <span class="badge" :class="nvr.features?.[k] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500 line-through'" x-text="label"></span>
                    </template>
                </div>
            </section>
        </template>

        {{-- Cameras --}}
        <template x-if="nvr.channels.length">
            <section class="card overflow-hidden">
                <h2 class="px-5 pt-5 font-semibold" x-text="`Cameras (${nvr.channels.filter(c => c.enabled).length})`"></h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-2 font-medium">#</th><th class="px-3 py-2 font-medium">Name</th>
                                <th class="px-3 py-2 font-medium">Main</th><th class="px-3 py-2 font-medium">Sub</th>
                                <th class="px-3 py-2 font-medium">Grid view</th><th class="px-5 py-2 font-medium"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="c in nvr.channels" :key="c.id">
                                <tr :class="c.enabled ? '' : 'text-slate-400'">
                                    <td class="px-5 py-2.5 font-mono" x-text="c.number"></td>
                                    <td class="px-3 py-2.5" x-text="c.name || '—'"></td>
                                    <td class="px-3 py-2.5"><span :class="c.main_codec === 'h265' ? 'text-amber-700' : ''" x-text="codecLabel(c.main_codec)"></span></td>
                                    <td class="px-3 py-2.5" x-text="codecLabel(c.sub_codec)"></td>
                                    <td class="whitespace-nowrap px-3 py-2.5" x-text="viewModeLabel(c.default_view_mode)"></td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-right">
                                        <span x-show="c.ptz" class="badge bg-slate-100 text-slate-600">PTZ</span>
                                        <span x-show="!c.online && c.enabled" class="badge bg-amber-50 text-amber-800">No signal</span>
                                        <span x-show="!c.enabled" class="badge bg-slate-100 text-slate-500">Removed</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
    </template>
</div>
@endsection
