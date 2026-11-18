<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public downloads for the site agent: the installer script and the release built by
 * edge-agent/build_release.sh (storage/app/agent-release). Only files named in the manifest
 * are served, and the installer embeds their SHA-256 so it can verify them.
 */
class AgentReleaseController extends Controller
{
    private static function dir(): string
    {
        return config('services.agent_release_dir') ?? storage_path('app/agent-release');
    }

    private static function manifest(): array
    {
        $path = self::dir().'/manifest.json';
        abort_unless(is_file($path), 503, 'Agent release not built yet: run edge-agent/build_release.sh');

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function installer(): Response
    {
        $m = self::manifest();
        $script = strtr(file_get_contents(resource_path('agent/install.sh')), [
            '@@API_URL@@' => rtrim(config('app.url'), '/'),
            '@@AGENT_VERSION@@' => $m['version'],
            '@@WHEEL@@' => $m['wheel'],
            '@@WHEEL_SHA256@@' => $m['wheel_sha256'],
            '@@CONSTRAINTS_SHA256@@' => $m['constraints_sha256'],
            '@@PYTHON_MIN@@' => $m['python_min'],
            '@@FRP_VERSION@@' => $m['frp']['version'],
            '@@FRP_SHA256_AMD64@@' => $m['frp']['sha256']['amd64'],
            '@@FRP_SHA256_ARM64@@' => $m['frp']['sha256']['arm64'],
        ]);

        return response($script, 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(string $file): BinaryFileResponse
    {
        $m = self::manifest();
        abort_unless(in_array($file, [$m['wheel'], 'constraints.txt'], true), 404);

        return response()->download(self::dir().'/'.$file, $file, [
            'Content-Type' => str_ends_with($file, '.whl') ? 'application/zip' : 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
