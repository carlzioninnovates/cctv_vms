<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Media\ViewerToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * MediaMTX external auth (authMethod: http). MediaMTX POSTs here before every read.
 * Only reads with a valid viewer token for that exact path pass. Publishing is never allowed
 * from outside: MediaMTX pulls from NVRs itself.
 * Must be reachable only from the backend network — the public proxy must not route /api/internal/*.
 */
class MediaAuthController extends Controller
{
    public function __invoke(Request $request, ViewerToken $tokens): Response
    {
        $action = (string) $request->input('action');
        $path = (string) $request->input('path');
        if (! in_array($action, ['read', 'playback'], true) || $path === '') {
            return response('', 401);
        }
        parse_str((string) $request->input('query', ''), $q);
        // MediaMTX sends every field, with empty strings for unused ones (e.g. RTSP: token = "",
        // password = <what the client typed>), so take the first non-empty candidate.
        $token = collect([$q['vt'] ?? null, $request->input('token'), $request->input('password')])
            ->first(fn ($v) => is_string($v) && $v !== '');

        $ok = $tokens->verify(is_string($token) ? $token : null, $path) !== null;
        Log::debug('media auth', ['ok' => $ok, 'action' => $action, 'path' => $path, 'protocol' => $request->input('protocol'),
            'has_token' => (bool) $token, 'fields' => array_keys($request->all()),
            'query' => preg_replace('/vt=[^&]*/', 'vt=…', (string) $request->input('query'))]);

        return response('', $ok ? 200 : 401);
    }
}
