<?php

namespace App\Services\Media;

/**
 * Short-lived token that lets one user read one MediaMTX path. Checked by MediaMTX through
 * MediaAuthController on every session (WebRTC) or request (HLS / RTSP).
 *
 * Format: "<userId>.<expiresUnix>.<sig>", sig = first 128 bits of HMAC-SHA256 over
 * "<userId>|<expires>|<path>", base64url. The path is signed but not embedded: MediaMTX tells
 * us which path is being read. Kept short on purpose — RTSP clients cap credentials
 * (ffmpeg: 127 bytes for "user:password"), and a longer token gets silently truncated.
 */
class ViewerToken
{
    public function __construct(private readonly string $key) {}

    public static function fromConfig(): self
    {
        return new self(hash_hmac('sha256', 'media-viewer-token', (string) config('app.key')));
    }

    public function issue(int $userId, string $path, int $ttlSeconds = 1800): array
    {
        $exp = time() + $ttlSeconds;

        return ['token' => "{$userId}.{$exp}.".$this->sig($userId, $exp, $path), 'expires_at' => $exp];
    }

    /** @return int|null user id when valid for this path */
    public function verify(?string $token, string $path, ?int $now = null): ?int
    {
        if (! $token || ! preg_match('/^(\d{1,12})\.(\d{1,12})\.([A-Za-z0-9_-]{22})$/', $token, $m)) {
            return null;
        }
        [, $userId, $exp, $sig] = $m;
        if ((int) $exp < ($now ?? time()) || ! hash_equals($this->sig((int) $userId, (int) $exp, $path), $sig)) {
            return null;
        }

        return (int) $userId;
    }

    private function sig(int $userId, int $exp, string $path): string
    {
        $mac = substr(hash_hmac('sha256', "{$userId}|{$exp}|{$path}", $this->key, true), 0, 16);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
