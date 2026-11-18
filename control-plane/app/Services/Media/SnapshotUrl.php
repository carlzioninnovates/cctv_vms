<?php

namespace App\Services\Media;

/**
 * Signed URL for the data plane's public snapshot endpoint. Snapshots go browser -> data plane
 * directly (cached there), so JPEGs never pass through PHP.
 * Must match data-plane/app/api/media.py
 */
class SnapshotUrl
{
    public static function make(int $nvrId, int $channel, int $ttlSeconds = 1800, ?int $now = null): array
    {
        $exp = ($now ?? time()) + $ttlSeconds;
        $sig = hash_hmac('sha256', "snap:{$nvrId}:{$channel}:{$exp}", (string) config('services.data_plane.secret'));
        $base = rtrim((string) config('services.data_plane.public_media_url'), '/');

        return ['url' => "{$base}/media/snapshot/{$nvrId}/{$channel}?exp={$exp}&sig={$sig}", 'expires_at' => $exp];
    }
}
