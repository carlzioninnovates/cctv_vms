<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OfflineIncident;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgIds = Organization::visibleTo($request->user())->pluck('id');
        $sites = Site::whereIn('organization_id', $orgIds)
            ->with(['organization:id,name', 'edgeAgents:id,site_id,status', 'nvrs:id,site_id,status'])
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')->limit(500)->get();
        $offline = OfflineIncident::whereIn('site_id', $sites->pluck('id'))->whereNull('ended_at')
            ->pluck('cause', 'site_id');

        return response()->json(['sites' => $sites->map(fn (Site $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'address' => $s->address,
            'organization' => ['id' => $s->organization->id, 'name' => $s->organization->name],
            'agents' => ['total' => $s->edgeAgents->count(), 'online' => $s->edgeAgents->where('status', 'online')->count()],
            'nvrs' => [
                'total' => $s->nvrs->count(),
                'active' => $s->nvrs->where('status', 'active')->count(),
                'failed' => $s->nvrs->where('status', 'failed')->count(),
                'onboarding' => $s->nvrs->where('status', 'onboarding')->count(),
            ],
            'offline_cause' => $offline[$s->id]?->value ?? null,
        ])->values()]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($request->user()->canManage($organization), 403);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'address' => 'nullable|string|max:255',
            'isp' => 'nullable|string|max:32',
            'has_ups' => 'boolean',
            'timezone' => 'nullable|timezone',
        ]);
        $site = $organization->sites()->create(array_filter($data, fn ($v) => $v !== null));
        AuditLog::record('site.created', $site);

        return response()->json(['id' => $site->id, 'name' => $site->name], 201);
    }

    public function show(Request $request, Site $site): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canView($site->organization), 403);
        // parent_id is needed: canManage() walks up the org tree (reseller -> client)
        $site->load(['organization:id,name,parent_id', 'edgeAgents','nvrs' => fn ($q) => $q->withCount('channels')->orderBy('name')]);
        $incident = OfflineIncident::where('site_id', $site->id)->whereNull('nvr_id')->whereNull('ended_at')->first();

        return response()->json([
            'id' => $site->id,
            'name' => $site->name,
            'address' => $site->address,
            'isp' => $site->isp,
            'has_ups' => $site->has_ups,
            'timezone' => $site->timezone,
            'organization' => ['id' => $site->organization->id, 'name' => $site->organization->name],
            'can_manage' => $user->canManage($site->organization),
            'offline' => $incident ? ['cause' => $incident->cause->value, 'since' => $incident->started_at->toIso8601String()] : null,
            'agents' => $site->edgeAgents->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'status' => $a->status,
                'hostname' => $a->hostname,
                'version' => $a->version,
                'last_heartbeat_at' => $a->last_heartbeat_at?->toIso8601String(),
                'enrollment_pending' => $a->enrollment_code_hash !== null,
                'enrollment_expires_at' => $a->enrollment_expires_at?->toIso8601String(),
            ])->values(),
            'nvrs' => $site->nvrs->map(fn ($n) => [
                'id' => $n->id,
                'name' => $n->name,
                'brand' => $n->brand->value,
                'lan_host' => $n->lan_host,
                'model' => $n->model,
                'status' => $n->status,
                'error_code' => $n->onboarding_error_code,
                'channels_count' => $n->channels_count,
                'warnings_count' => count($n->probe_warnings ?? []),
            ])->values(),
        ]);
    }
}
