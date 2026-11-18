<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Site;
use App\Models\User;
use App\Services\DataPlane\DataPlaneClient;
use App\Services\Media\SnapshotUrl;
use App\Services\Media\ViewerToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveController extends Controller
{
    /** Roles allowed to move PTZ cameras. Viewers can watch only. */
    private const PTZ_ROLES = ['owner', 'admin', 'installer', 'operator'];

    /** All cameras on a site's active NVRs, with snapshot URLs for low-data mode. */
    public function cameras(Request $request, Site $site): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canView($site->organization), 403);

        $channels = Channel::query()
            ->join('nvrs', 'nvrs.id', '=', 'channels.nvr_id')
            ->where('nvrs.site_id', $site->id)->whereNull('nvrs.deleted_at')
            ->whereIn('nvrs.status', ['active', 'offline'])
            ->where('channels.enabled', true)
            ->orderBy('nvrs.name')->orderBy('channels.number')
            ->get(['channels.*', 'nvrs.name as nvr_name', 'nvrs.status as nvr_status']);

        return response()->json([
            'site' => ['id' => $site->id, 'name' => $site->name],
            'can_ptz' => in_array($user->role, self::PTZ_ROLES, true),
            'cameras' => $channels->map(fn (Channel $c) => [
                'id' => $c->id,
                'nvr_id' => $c->nvr_id,
                'nvr_name' => $c->nvr_name,
                'nvr_online' => $c->nvr_status === 'active',
                'number' => $c->number,
                'name' => $c->name ?: "Camera {$c->number}",
                'online' => $c->online,
                'ptz' => $c->ptz,
                'main_codec' => $c->main_codec,
                'sub_codec' => $c->sub_codec,
                'default_view_mode' => $c->default_view_mode,
                'snapshot_interval' => max(2, (int) $c->snapshot_interval_seconds),
                'snapshot' => SnapshotUrl::make($c->nvr_id, $c->number),
            ])->values(),
        ]);
    }

    /** Start (or reuse) a stream for one camera and return player URLs with a viewer token. */
    public function stream(Request $request, Channel $channel, DataPlaneClient $dp, ViewerToken $tokens): JsonResponse
    {
        $user = $request->user();
        $nvr = $channel->nvr()->with('site.organization')->firstOrFail();
        abort_unless($user->canView($nvr->site->organization), 403);
        abort_unless($channel->enabled, 404);
        abort_unless($nvr->status === 'active', 409, 'This NVR is offline.');
        $quality = $request->validate(['quality' => 'in:sub,main'])['quality'] ?? 'sub';
        if ($quality === 'sub' && in_array($channel->sub_codec, ['none'], true)) {
            $quality = 'main';   // no substream on this camera
        }

        $r = $dp->live($nvr->id, $channel->number, $quality);
        abort_if($r->status() === 504, 504, 'The site is not reachable right now.');
        $r->throw();
        $issued = $tokens->issue($user->id, $r->json('path'));
        $vt = 'vt='.$issued['token'];

        AuditLog::record('live.view', $channel, ['nvr_id' => $nvr->id, 'quality' => $quality]);

        return response()->json([
            'quality' => $quality,
            'codec' => $quality === 'main' ? $channel->main_codec : $channel->sub_codec,
            'webrtc_ok' => (bool) $r->json('browser_playable_webrtc'),
            'whep_url' => $r->json('whep_url').'?'.$vt,
            'hls_url' => $r->json('hls_url').'?'.$vt,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
        ]);
    }

    public function ptz(Request $request, Channel $channel, DataPlaneClient $dp): JsonResponse
    {
        $user = $request->user();
        $nvr = $channel->nvr()->with('site.organization')->firstOrFail();
        abort_unless($user->canView($nvr->site->organization), 403);
        abort_unless(in_array($user->role, self::PTZ_ROLES, true), 403, 'Your role can watch but not move cameras.');
        abort_unless($channel->ptz, 422, 'This camera has no PTZ.');
        $data = $request->validate([
            'action' => 'required|in:up,down,left,right,zoom_in,zoom_out,stop',
            'speed' => 'nullable|integer|between:1,7',
        ]);
        $dp->ptz($nvr->id, $channel->number, $data['action'], $data['speed'] ?? 4)->throw();

        return response()->json(null, 204);
    }
}
