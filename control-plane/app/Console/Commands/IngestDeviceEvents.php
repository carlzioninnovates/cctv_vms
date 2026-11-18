<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Drains normalized events from the data plane's Redis stream into Postgres.
 * Bounded: runs at most --max-batches then exits. Scheduled every 5 seconds.
 * Never an endless loop inside Laravel.
 */
class IngestDeviceEvents extends Command
{
    protected $signature = 'events:ingest {--batch=500} {--max-batches=20}';

    protected $description = 'Read device events from Redis stream vms:events into the events table';

    private const STREAM = 'vms:events';
    private const GROUP = 'control-plane';

    public function handle(): int
    {
        $redis = Redis::connection();
        $consumer = gethostname().'-'.getmypid();

        // Create the consumer group if missing. executeRaw returns "BUSYGROUP" as a value instead of
        // throwing, and some servers close the connection after an error reply, so always reconnect.
        try {
            $redis->executeRaw(['XGROUP', 'CREATE', self::STREAM, self::GROUP, '0', 'MKSTREAM']);
        } catch (\Throwable) {
        }
        $redis->disconnect();

        // NVR id -> [organization_id, [channel number -> channel id]], cached per run
        $lookup = [];
        $total = 0;

        for ($i = 0; $i < (int) $this->option('max-batches'); $i++) {
            $res = $redis->executeRaw([
                'XREADGROUP', 'GROUP', self::GROUP, $consumer,
                'COUNT', (string) $this->option('batch'), 'STREAMS', self::STREAM, '>',
            ]);
            $entries = $res[0][1] ?? [];
            if (! $entries) {
                break;
            }

            $rows = [];
            $ids = [];
            foreach ($entries as [$id, $fields]) {
                $ids[] = $id;
                $f = [];
                for ($k = 0; $k + 1 < count($fields); $k += 2) {
                    $f[$fields[$k]] = $fields[$k + 1];   // Redis returns [key, value, key, value]
                }
                $e = json_decode($f['event'] ?? 'null', true);
                if (! is_array($e)) {
                    continue;
                }
                $nvrId = (int) $e['nvr_id'];
                $lookup[$nvrId] ??= $this->lookup($nvrId);
                if ($lookup[$nvrId] === null) {
                    continue; // NVR deleted since
                }
                [$orgId, $channels] = $lookup[$nvrId];
                $now = now();
                $rows[] = [
                    'event_uid' => $e['event_id'] ?? (string) Str::uuid(),
                    'organization_id' => $orgId,
                    'nvr_id' => $nvrId,
                    'channel_id' => $e['channel'] !== null ? ($channels[$e['channel']] ?? null) : null,
                    'type' => $e['type'],
                    'state' => $e['state'],
                    'raw_type' => $e['raw_type'] ?? null,
                    'occurred_at' => $e['occurred_at'],
                    'received_at' => $e['received_at'],
                    'buffered' => (bool) ($e['buffered'] ?? false),
                    'ai_verdict' => $e['ai']['verdict'] ?? null,
                    'ai_label' => $e['ai']['label'] ?? null,
                    'ai_confidence' => $e['ai']['confidence'] ?? null,
                    'snapshot_key' => $e['snapshot_key'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Idempotent: agents may resend after an outage
            if ($rows) {
                DB::table('events')->insertOrIgnore($rows);
            }
            $redis->executeRaw(array_merge(['XACK', self::STREAM, self::GROUP], $ids));
            $total += count($rows);
            // TODO: dispatch NotifyForEvents job for rows that pass the user's notification rules
        }

        if ($total) {
            $this->info("ingested {$total} events");
        }

        return self::SUCCESS;
    }

    private function lookup(int $nvrId): ?array
    {
        $nvr = DB::table('nvrs')->join('sites', 'sites.id', '=', 'nvrs.site_id')
            ->where('nvrs.id', $nvrId)->first(['sites.organization_id']);
        if (! $nvr) {
            return null;
        }
        $channels = DB::table('channels')->where('nvr_id', $nvrId)->pluck('id', 'number')->all();

        return [$nvr->organization_id, $channels];
    }
}
