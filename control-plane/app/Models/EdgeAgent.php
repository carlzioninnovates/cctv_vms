<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EdgeAgent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['enrollment_code_hash', 'cert_fingerprint'];

    /** No 0/O/1/I: installers read these codes off a screen and type them on a terminal. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected function casts(): array
    {
        return [
            'enrollment_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'cert_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'lte_failover_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->agent_uid ??= (string) Str::uuid());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function nvrs(): HasMany
    {
        return $this->hasMany(Nvr::class);
    }

    public static function hashCode(string $code): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        return hash('sha256', $c);
    }

    /** Issue a fresh one-time code. Returns the plain code (shown once). */
    public function issueEnrollmentCode(int $hours = 24): string
    {
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $this->forceFill([
            'enrollment_code_hash' => self::hashCode($raw),
            'enrollment_expires_at' => now()->addHours($hours),
        ])->save();

        return substr($raw, 0, 4).'-'.substr($raw, 4);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }
}
