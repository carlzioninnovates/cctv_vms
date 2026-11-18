@extends('layouts.app')
@section('title', 'Sites')
@section('content')
<div x-data="sitesPage" x-cloak>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Sites</h1>
            <p class="text-sm text-slate-500" x-text="sites.length + (sites.length === 1 ? ' site' : ' sites')"></p>
        </div>
        <div class="flex gap-2">
            <button class="btn-secondary" x-show="isInstallerCompany" @click="showNewClient = true; formError = null">New client</button>
            <button class="btn-primary" @click="openNewSite()" :disabled="clientOrgs.length === 0">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 4a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H5a1 1 0 110-2h4V5a1 1 0 011-1z"/></svg>
                New site
            </button>
        </div>
    </div>

    <input class="input mb-5" type="search" placeholder="Search sites…" x-model.debounce.300ms="q" @input.debounce.300ms="load()">

    <template x-if="loading"><div class="py-16 text-center text-slate-400">Loading…</div></template>
    <template x-if="error"><div class="rounded-lg bg-red-50 p-4 text-sm text-red-700" x-text="error"></div></template>

    <template x-if="!loading && !error && sites.length === 0">
        <div class="card px-6 py-14 text-center">
            <h2 class="font-semibold">No sites yet</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500" x-show="isInstallerCompany && clientOrgs.length === 0">
                Start by adding your first client, then add their site.</p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500" x-show="clientOrgs.length > 0">
                Add a site, install the agent there, then connect the NVR.</p>
            <button class="btn-primary mt-5" x-show="isInstallerCompany && clientOrgs.length === 0" @click="showNewClient = true">Add a client</button>
            <button class="btn-primary mt-5" x-show="clientOrgs.length > 0" @click="openNewSite()">Add a site</button>
        </div>
    </template>

    <div class="space-y-6">
        <template x-for="[client, list] in groups" :key="client">
            <section>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500" x-text="client"></h2>
                <div class="card divide-y divide-slate-100">
                    <template x-for="s in list" :key="s.id">
                        <a :href="`/sites/${s.id}`" class="flex items-center justify-between gap-4 px-4 py-3.5 hover:bg-slate-50">
                            <div class="min-w-0">
                                <div class="truncate font-medium" x-text="s.name"></div>
                                <div class="truncate text-sm" :class="siteHealth(s).cls" x-text="siteHealth(s).text"></div>
                            </div>
                            <div class="flex shrink-0 items-center gap-3 text-xs text-slate-500">
                                <span class="hidden sm:inline" x-text="`${s.nvrs.total} NVR`"></span>
                                <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path d="M7.3 14.7a1 1 0 010-1.4L10.6 10 7.3 6.7a1 1 0 011.4-1.4l4 4a1 1 0 010 1.4l-4 4a1 1 0 01-1.4 0z"/></svg>
                            </div>
                        </a>
                    </template>
                </div>
            </section>
        </template>
    </div>

    {{-- New client --}}
    <div x-show="showNewClient" class="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/40 sm:items-center" @keydown.escape.window="showNewClient = false">
        <form @submit.prevent="createClient()" @click.outside="showNewClient = false" class="card w-full max-w-md space-y-4 rounded-b-none p-6 sm:rounded-xl">
            <h2 class="text-lg font-semibold">New client</h2>
            <div>
                <label class="label">Business name</label>
                <input class="input" x-model="newClient.name" required placeholder="e.g. Reyes Hardware">
            </div>
            <p x-show="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="formError"></p>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="showNewClient = false">Cancel</button>
                <button class="btn-primary" :disabled="saving">Add client</button>
            </div>
        </form>
    </div>

    {{-- New site --}}
    <div x-show="showNewSite" class="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/40 sm:items-center" @keydown.escape.window="showNewSite = false">
        <form @submit.prevent="createSite()" @click.outside="showNewSite = false" class="card w-full max-w-md space-y-4 rounded-b-none p-6 sm:rounded-xl">
            <h2 class="text-lg font-semibold">New site</h2>
            <div x-show="clientOrgs.length > 1">
                <label class="label">Client</label>
                <select class="input" x-model.number="newSite.organization_id">
                    <template x-for="o in clientOrgs" :key="o.id"><option :value="o.id" x-text="o.name"></option></template>
                </select>
            </div>
            <div>
                <label class="label">Site name</label>
                <input class="input" x-model="newSite.name" required placeholder="e.g. Main branch, Quezon City">
            </div>
            <div>
                <label class="label">Address <span class="font-normal text-slate-400">(optional)</span></label>
                <input class="input" x-model="newSite.address">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label">Internet provider</label>
                    <select class="input" x-model="newSite.isp">
                        <option value="">—</option>
                        <option>PLDT</option><option>Globe</option><option>Converge</option><option>Sky</option>
                        <option>DITO</option><option>Starlink</option><option>Other</option>
                    </select>
                </div>
                <label class="mt-6 flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" x-model="newSite.has_ups" class="rounded border-slate-300 text-brand-600"> Has UPS
                </label>
            </div>
            <p x-show="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="formError"></p>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="showNewSite = false">Cancel</button>
                <button class="btn-primary" :disabled="saving">Create site</button>
            </div>
        </form>
    </div>
</div>
@endsection
