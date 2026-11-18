<?php

namespace App\Console\Commands;

use App\Enums\OfflineCause;
use App\Models\EdgeAgent;
use App\Models\OfflineIncident;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * Applies agent online/offline changes from data-plane stream vms:agent-status:
 * updates edge_agents.status and opens/closes offline_incidents (with the cause).
 * Bounded run, scheduled every 5 seconds.
 */
class SyncAgentStatus extends Command
{
    protected $signature = 'agents:sync-status {--batch=500} {--max-batches=10}';

    protected $description = 'Apply agent online/offline changes from the data plane';

    private const STREAM = 'vms:agent-status';
    private const GROUP = 'control-plane';

    public function handle(): int
    {
        $redis = Redis::connection();
        // Create the consumer group if missing. executeRaw returns "BUSYGROUP" as a value instead of
        // throwing, and some servers close the connection after an error reply, so always reconnect.
        try {
            $redis->executeRaw(['XGROUP', 'CREATE', self::STREAM, self::GROUP, '0', 'MKSTREAM']);
        } catch (\Throwable) {
        }
        $redis->disconnect();
        $consumer = gethostname().'-'.getmypid();

        for ($i = 0; $i < (int) $this->option('max-batches'); $i++) {
            $res = $redis->executeRaw(['XREADGROUP', 'GROUP', self::GROUP, $consumer,
                'COUNT', (string) $this->option('batch'), 'STREAMS', self::STREAM, '>']);
            $entries = $res[0][1] ?? [];
            if (! $entries) {
                break;
            }
            $ids = [];
            foreach ($entries as [$id, $fields]) {
                $ids[] = $id;
                $f = [];
                for ($k = 0; $k + 1 < count($fields); $k += 2) {
                    $f[$fields[$k]] = $fields[$k + 1];
                }
                $e = json_decode($f['event'] ?? 'null', true);
                if (is_array($e)) {
                    $this->apply($e);
                }
            }
            $redis->executeRaw(array_merge(['XACK', self::STREAM, self::GROUP], $ids));
        }

        return self::SUCCESS;
    }

    /** Public for tests. */
    public function apply(array $e): void
    {
        $agent = EdgeAgent::where('agent_uid', $e['agent_id'] ?? '')->first();
        if (! $agent || $agent->revoked_at) {
            return;
        }
        $at = Carbon::createFromTimestamp((float) $e['at']);

        if (($e['status'] ?? null) === 'offline') {
            $agent->forceFill(['status' => 'offline'])->save();
            OfflineIncident::firstOrCreate(
                ['site_id' => $agent->site_id, 'nvr_id' => null, 'ended_at' => null],
                ['cause' => OfflineCause::tryFrom($e['cause'] ?? '') ?? OfflineCause::SiteUnreachable,
                    'started_at' => Carbon::createFromTimestamp((float) ($e['offline_since'] ?? $e['at']))],
            );

            return;
        }

        $agent->forceFill(['status' => 'online', 'last_heartbeat_at' => $at])->save();
        $open = OfflineIncident::where('site_id', $agent->site_id)->whereNull('nvr_id')->whereNull('ended_at')->first();
        if ($open) {
            $final = OfflineCause::tryFrom($e['cause'] ?? '');
            $open->forceFill(array_filter([
                'ended_at' => $at,
                'cause' => $final,
                'reclassified_at' => $final && $final !== $open->cause ? $at : null,
            ]))->save();
        }
    }
}
