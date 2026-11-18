<?php

namespace App\Models;

use App\Enums\ConnectionMode;
use App\Enums\DeviceBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nvr extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['credential_ref'];

    protected function casts(): array
    {
        return [
            'brand' => DeviceBrand::class,
            'connection_mode' => ConnectionMode::class,
            'capabilities' => 'array',
            'probe_warnings' => 'array',
            'tunnel_ports' => 'array',
            'probed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'owner_consent_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function edgeAgent(): BelongsTo
    {
        return $this->belongsTo(EdgeAgent::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class)->orderBy('number');
    }

    public function advisories(): BelongsToMany
    {
        return $this->belongsToMany(FirmwareAdvisory::class, 'nvr_firmware_advisory')->withPivot(['notified_at', 'dismissed_at']);
    }
}
