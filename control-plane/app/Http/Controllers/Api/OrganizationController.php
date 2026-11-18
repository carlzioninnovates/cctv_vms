<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgs = Organization::visibleTo($request->user())->withCount('sites')->orderBy('name')->get();

        return response()->json(['organizations' => $orgs->map(fn ($o) => [
            'id' => $o->id, 'name' => $o->name, 'type' => $o->type->value,
            'parent_id' => $o->parent_id, 'sites_count' => $o->sites_count,
        ])->values()]);
    }

    /** A reseller (installer company) adds a client business. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $parent = $user->organization;
        abort_unless($parent && $user->canManage($parent), 403);
        abort_unless(in_array($parent->type, [OrganizationType::Reseller, OrganizationType::Platform], true), 403,
            'Only installer companies can add clients.');
        $data = $request->validate(['name' => 'required|string|max:120']);

        $org = Organization::create([
            'type' => $parent->type === OrganizationType::Platform ? OrganizationType::Reseller : OrganizationType::Client,
            'parent_id' => $parent->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
        ]);
        AuditLog::record('organization.created', $org);

        return response()->json(['id' => $org->id, 'name' => $org->name, 'type' => $org->type->value], 201);
    }
}
