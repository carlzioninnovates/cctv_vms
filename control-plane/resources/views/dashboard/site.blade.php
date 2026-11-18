@extends('layouts.app')
@section('title', $siteName)
@section('content')
<div x-data="sitePage({{ $siteId }})" x-cloak>
    <a href="{{ route('sites') }}" class="text-sm text-slate-500 hover:text-slate-700">← All sites</a>

    <template x-if="loading"><div class="py-16 text-center text-slate-400">Loading…</div></template>
    <template x-if="error && !site"><div class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-700" x-text="error"></div></template>

    <template x-if="site">
    <div>
        <div class="mb-6 mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold" x-text="site.name"></h1>
                <p class="text-sm text-slate-500">
                    <span x-text="site.organization.name"></span><span x-show="site.address"> · </span><span x-text="site.address"></span>
                </p>
            </div>
            <a x-show="site.nvrs.some(n => n.status === 'active')" :href="`/sites/${site.id}/live`" class="btn-primary">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 2.84A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.27l9.34-5.89a1.5 1.5 0 000-2.54L6.3 2.84z"/></svg>
                Live view
            </a>
        </div>

        <div x-show="site.offline" class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.26 3.1c.77-1.33 2.7-1.33 3.48 0l6.28 10.9c.77 1.33-.2 3-1.74 3H3.72c-1.54 0-2.5-1.67-1.74-3L8.26 3.1zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            <div><span class="font-semibold" x-text="OFFLINE_CAUSE[site.offline?.cause] ?? 'Site offline'"></span>
                <span x-text="'· since ' + timeAgo(site.offline?.since)"></span></div>
        </div>

        {{-- Progress --}}
        <ol class="mb-6 grid grid-cols-3 gap-2 text-xs sm:text-sm" x-show="site.can_manage && step < 3">
            <template x-for="(label, i) in ['Install agent', 'Add NVR', 'Done']" :key="i">
                <li class="flex items-center gap-2 rounded-lg border px-3 py-2"
                    :class="step > i + 1 ? 'border-brand-100 bg-brand-50 text-brand-700' : (step === i + 1 ? 'border-brand-500 bg-white font-semibold text-slate-900' : 'border-slate-200 bg-white text-slate-400')">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold"
                          :class="step > i + 1 ? 'bg-brand-600 text-white' : (step === i + 1 ? 'bg-slate-900 text-white' : 'bg-slate-200 text-slate-500')"
                          x-text="step > i + 1 ? '✓' : i + 1"></span>
                    <span x-text="label"></span>
                </li>
            </template>
        </ol>

        {{-- Agent --}}
        <section class="card mb-6 p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-semibold">Site agent</h2>
                    <p class="text-sm text-slate-500">A small device or PC at the site that connects the NVR to the cloud.</p>
                </div>
                <template x-if="agent">
                    <span class="badge shrink-0" :class="agentStatus(agent.status).cls">
                        <span class="dot" :class="agentStatus(agent.status).dot"></span><span x-text="agentStatus(agent.status).label"></span>
                    </span>
                </template>
            </div>

            <template x-if="!agent && site.can_manage">
                <button class="btn-primary mt-4" @click="createAgent()" :disabled="creatingAgent">Set up site agent</button>
            </template>

            {{-- Fresh code --}}
            <template x-if="enrollment && agent && !agentReady">
                <div class="mt-4 space-y-4">
                    <div class="rounded-xl bg-slate-900 p-5 text-center text-white">
                        <div class="text-xs uppercase tracking-widest text-slate-400">Enrollment code</div>
                        <div class="mt-1 font-mono text-3xl font-bold tracking-[0.2em] sm:text-4xl" x-text="enrollment.enrollment_code"></div>
                        <div class="mt-1 text-xs text-slate-400" x-text="countdown(enrollment.expires_at) + ' · one-time use'" x-effect="now"></div>
                    </div>
                    <div>
                        <div class="label">On the agent device (Raspberry Pi or Linux PC on the same network as the NVR), run:</div>
                        <div class="flex items-stretch overflow-hidden rounded-lg border border-slate-300">
                            <code class="flex-1 overflow-x-auto whitespace-nowrap bg-slate-900 px-3 py-2.5 font-mono text-xs text-slate-100 sm:text-sm" x-text="enrollment.install_command"></code>
                            <button class="border-l border-slate-300 bg-white px-3 text-sm font-medium hover:bg-slate-50" @click="copyText('cmd', enrollment.install_command)"
                                    x-text="copied === 'cmd' ? 'Copied' : 'Copy'"></button>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Installs the agent, connects it with this code and starts it automatically on every boot. Takes 1–3 minutes.
                            Needs 64-bit Raspberry Pi OS, Debian 12+ or Ubuntu 24.04+. <a href="{{ route('help') }}#install-the-site-agent" target="_blank" class="font-medium text-brand-700 hover:underline">Installation help</a></p>
                    </div>
                    <p class="flex items-center gap-2 text-sm text-slate-500">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-sky-500"></span>
                        Waiting for the agent to connect… this page updates by itself.
                    </p>
                </div>
            </template>

            {{-- Code exists but not shown (page reloaded) --}}
            <template x-if="!enrollment && agent && agent.enrollment_pending && site.can_manage">
                <div class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                    The enrollment code is only shown once.
                    <button class="font-semibold text-brand-700 hover:underline" @click="newCode()">Show a new code</button>
                </div>
            </template>

            <template x-if="agent && agent.status !== 'pending' && !(enrollment && !agentReady)">
                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-slate-500">Device</dt><dd class="truncate font-medium" x-text="agent.hostname || '—'"></dd></div>
                    <div><dt class="text-slate-500">Version</dt><dd class="font-medium" x-text="agent.version || '—'"></dd></div>
                    <div><dt class="text-slate-500">Last seen</dt><dd class="font-medium" x-text="timeAgo(agent.last_heartbeat_at)" x-effect="now"></dd></div>
                    <div class="text-right sm:text-left" x-show="site.can_manage">
                        <dt class="text-slate-500">&nbsp;</dt>
                        <dd><button class="text-sm text-slate-500 hover:text-red-600 hover:underline" @click="newCode()">Replace agent</button></dd>
                    </div>
                </dl>
            </template>
        </section>

        {{-- NVRs --}}
        <section class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold">NVRs</h2>
                    <p class="text-sm text-slate-500" x-show="!agentReady">Available once the site agent is online.</p>
                </div>
                <div class="flex gap-2" x-show="site.can_manage && agentReady">
                    <button class="btn-secondary" @click="scan()" :disabled="scanning">
                        <svg x-show="scanning" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="4"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="4"/></svg>
                        <span x-text="scanning ? 'Scanning…' : 'Scan network'"></span>
                    </button>
                    <button class="btn-primary" @click="openAdd()">Add manually</button>
                </div>
            </div>

            <p x-show="scanError" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="scanError"></p>

            <template x-if="devices">
                <div class="mt-4 rounded-lg border border-dashed border-slate-300 p-3">
                    <div class="mb-2 text-sm font-medium text-slate-700" x-text="devices.length ? `Found ${devices.length} device(s) on the site network` : 'No ONVIF devices found.'"></div>
                    <p x-show="!devices.length" class="text-sm text-slate-500">Hikvision NVRs only answer scans when ONVIF is enabled. You can still add the NVR manually by IP.</p>
                    <ul class="divide-y divide-slate-100">
                        <template x-for="d in devices" :key="d.ip">
                            <li class="flex items-center justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <div class="font-mono text-sm" x-text="d.ip"></div>
                                    <div class="truncate text-xs text-slate-500" x-text="[BRANDS[d.brand_guess]?.label, d.hardware].filter(Boolean).join(' · ')"></div>
                                </div>
                                <template x-if="d.already_added_nvr_id"><a :href="`/nvrs/${d.already_added_nvr_id}`" class="text-sm text-slate-500 hover:underline">Already added</a></template>
                                <template x-if="!d.already_added_nvr_id"><button class="btn-secondary px-3 py-1.5" @click="openAdd(d)">Add</button></template>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>

            <ul class="mt-4 divide-y divide-slate-100" x-show="site.nvrs.length">
                <template x-for="n in site.nvrs" :key="n.id">
                    <li>
                        <a :href="`/nvrs/${n.id}`" class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-3 hover:bg-slate-50">
                            <div class="min-w-0">
                                <div class="truncate font-medium" x-text="n.name"></div>
                                <div class="truncate text-xs text-slate-500"
                                     x-text="[BRANDS[n.brand]?.label, n.lan_host, n.channels_count ? n.channels_count + ' cameras' : null].filter(Boolean).join(' · ')"></div>
                            </div>
                            <span class="badge shrink-0" :class="nvrStatus(n.status).cls">
                                <span class="dot" :class="nvrStatus(n.status).dot"></span><span x-text="nvrStatus(n.status).label"></span>
                            </span>
                        </a>
                    </li>
                </template>
            </ul>
            <p x-show="agentReady && !site.nvrs.length && !devices" class="mt-4 text-sm text-slate-500">
                No NVRs yet. Scan the network or add one by IP address.</p>
        </section>

        {{-- Add NVR --}}
        <div x-show="showAdd" class="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/40 sm:items-center" @keydown.escape.window="showAdd = false">
            <form @submit.prevent="addNvr()" class="card max-h-[94vh] w-full max-w-lg space-y-5 overflow-y-auto rounded-b-none p-6 sm:rounded-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">Add NVR</h2>
                        <p class="text-sm text-slate-500">Takes about a minute. <a href="{{ route('help') }}#add-an-nvr" target="_blank" class="font-medium text-brand-700 hover:underline">Step-by-step guide</a></p>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="showAdd = false" aria-label="Close">✕</button>
                </div>

                {{-- 1. Brand --}}
                <fieldset>
                    <legend class="label">1. Brand</legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <template x-for="(b, key) in BRANDS" :key="key">
                            <button type="button" @click="setBrand(key)"
                                    class="rounded-lg border px-2 py-2 text-sm font-medium"
                                    :class="form.brand === key ? 'border-brand-500 bg-brand-50 text-brand-700 ring-1 ring-brand-500' : 'border-slate-300 hover:bg-slate-50'"
                                    :aria-pressed="form.brand === key" x-text="b.label"></button>
                        </template>
                    </div>
                </fieldset>

                {{-- Before you start --}}
                <div class="rounded-lg border border-sky-200 bg-sky-50 text-sm text-sky-950">
                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 font-medium" @click="showPrep = !showPrep" :aria-expanded="showPrep">
                        <span x-text="`Before you start (${BRANDS[form.brand].label})`"></span>
                        <span class="text-sky-700" x-text="showPrep ? 'Hide' : 'Show'"></span>
                    </button>
                    <ol x-show="showPrep" class="list-decimal space-y-1.5 px-3 pb-3 pl-8">
                        <li><span class="font-medium">Find the NVR's IP address.</span> <span x-text="BRANDS[form.brand].ip"></span></li>
                        <li><span class="font-medium">Create a separate NVR user for the cloud</span> (recommended, e.g. <code class="rounded bg-white px-1">vmscloud</code>) so the admin password never leaves the site and the owner can remove access anytime: <span x-text="BRANDS[form.brand].account"></span></li>
                        <li x-text="BRANDS[form.brand].tip"></li>
                    </ol>
                </div>

                {{-- 2. Connection --}}
                <fieldset class="space-y-3">
                    <legend class="label">2. Connection</legend>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="text-xs font-medium text-slate-600" for="nvr-ip">NVR IP address</label>
                            <input id="nvr-ip" class="input font-mono" :class="!ipLooksValid && 'border-red-400 focus:border-red-500 focus:ring-red-500'"
                                   x-ref="firstField" x-model.trim="form.lan_host" required placeholder="192.168.1.64" inputmode="decimal" autocomplete="off">
                            <p x-show="!ipLooksValid" class="mt-1 text-xs text-red-600">That doesn't look like an IP address (e.g. 192.168.1.64).</p>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-600" for="nvr-name">Name <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="nvr-name" class="input" x-model="form.name" placeholder="e.g. Store NVR">
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-600" for="nvr-user">NVR username</label>
                            <input id="nvr-user" class="input" x-model="form.username" required autocomplete="off" placeholder="vmscloud">
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-600" for="nvr-pass">NVR password</label>
                            <div class="relative">
                                <input id="nvr-pass" class="input pr-16" :type="showPassword ? 'text' : 'password'" x-model="form.password" required autocomplete="new-password">
                                <button type="button" class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-slate-500" @click="showPassword = !showPassword" x-text="showPassword ? 'Hide' : 'Show'"></button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="text-sm font-medium text-slate-600 hover:text-slate-900" @click="showPorts = !showPorts" :aria-expanded="showPorts"
                            x-text="(showPorts ? '▾' : '▸') + ' Ports (only if changed from the default)'"></button>
                    <div x-show="showPorts" class="grid grid-cols-3 gap-3">
                        <div><label class="text-xs font-medium text-slate-600">HTTP</label><input class="input" type="number" x-model.number="form.http_port" min="1" max="65535"></div>
                        <div><label class="text-xs font-medium text-slate-600">RTSP</label><input class="input" type="number" x-model.number="form.rtsp_port" min="1" max="65535"></div>
                        <div><label class="text-xs font-medium text-slate-600">ONVIF</label><input class="input" type="number" x-model.number="form.onvif_port" min="1" max="65535"></div>
                    </div>
                </fieldset>

                {{-- 3. Authorization --}}
                <fieldset>
                    <legend class="label">3. Owner authorization</legend>
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm text-slate-700" :class="form.owner_consent && 'border-brand-500 bg-brand-50'">
                        <input type="checkbox" x-model="form.owner_consent" required class="mt-0.5 rounded border-slate-300 text-brand-600">
                        <span>The owner of this NVR has authorized <strong>{{ config('app.name') }}</strong> to view and manage these cameras remotely.
                            <span class="block text-xs text-slate-500">Required by the Data Privacy Act. Recorded with your name and the time.</span></span>
                    </label>
                </fieldset>

                <p class="flex gap-2 text-xs text-slate-500">
                    <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v3H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm2 7V5a2 2 0 10-4 0v3h4z" clip-rule="evenodd"/></svg>
                    The password is encrypted and never shown again.
                </p>
                <p x-show="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="formError"></p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="showAdd = false">Cancel</button>
                    <button class="btn-primary" :disabled="saving || !form.owner_consent || !ipLooksValid" x-text="saving ? 'Adding…' : 'Add & connect'"></button>
                </div>
            </form>
        </div>
    </div>
    </template>
</div>
@endsection
