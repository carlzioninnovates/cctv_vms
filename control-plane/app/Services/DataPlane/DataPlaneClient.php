<?php

namespace App\Services\DataPlane;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only way Laravel talks to hardware. Thin, short-timeout HTTP calls to the
 * Python data plane; anything slow runs there, not here.
 *
 * Signature: X-VMS-Signature: t=<unix>,v1=hex(hmac_sha256(secret, "<t>.<body>"))
 * Must match data-plane/app/core/security.py
 */
class DataPlaneClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.data_plane.url'),
            config('services.data_plane.secret'),
            (int) config('services.data_plane.timeout', 15),
        );
    }

    public function probe(int $nvrId, array $payload): Response
    {
        // Probing walks several endpoints on a slow tunnel; allow longer.
        return $this->send('POST', "/internal/nvrs/{$nvrId}/probe", $payload, 60);
    }

    public function live(int $nvrId, int $channel, string $stream = 'sub'): Response
    {
        return $this->send('POST', "/internal/nvrs/{$nvrId}/channels/{$channel}/live", ['stream' => $stream]);
    }

    public function playback(int $nvrId, int $channel, \DateTimeInterface $start, \DateTimeInterface $end): Response
    {
        return $this->send('POST', "/internal/nvrs/{$nvrId}/channels/{$channel}/playback", [
            'start' => $start->format(DATE_ATOM),
            'end' => $end->format(DATE_ATOM),
        ]);
    }

    public function ptz(int $nvrId, int $channel, string $action, int $speed = 4): Response
    {
        return $this->send('POST', "/internal/nvrs/{$nvrId}/channels/{$channel}/ptz", compact('action', 'speed'));
    }

    public function health(int $nvrId): Response
    {
        return $this->send('GET', "/internal/nvrs/{$nvrId}/health");
    }

    // ---- onboarding ----
    public function issueCertificate(string $agentUid, string $csrPem): Response
    {
        return $this->send('POST', "/internal/agents/{$agentUid}/certificate", ['csr_pem' => $csrPem]);
    }

    public function revokeCertificate(string $agentUid): Response
    {
        return $this->send('DELETE', "/internal/agents/{$agentUid}/certificate");
    }

    /** The password passes through here once and is stored only in the data plane, encrypted. */
    public function storeCredentials(string $username, string $password): Response
    {
        return $this->send('POST', '/internal/credentials', compact('username', 'password'));
    }

    public function deleteCredentials(string $ref): Response
    {
        return $this->send('DELETE', "/internal/credentials/{$ref}");
    }

    public function discover(string $agentUid): Response
    {
        return $this->send('POST', "/internal/agents/{$agentUid}/discover", null, 20);
    }

    /** @param array<int, array{port:int, kind:string}> $ports */
    public function expose(string $agentUid, string $lanHost, array $ports): Response
    {
        return $this->send('POST', "/internal/agents/{$agentUid}/expose", ['lan_host' => $lanHost, 'ports' => $ports], 40);
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function send(string $method, string $path, ?array $payload = null, ?int $timeout = null): Response
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->request($timeout)
            ->withHeaders(['X-VMS-Signature' => self::sign($this->secret, time(), $body)])
            ->withBody($body, 'application/json')
            ->send($method, $path);
    }

    private function request(?int $timeout): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout($timeout ?? $this->timeoutSeconds);
    }
}
