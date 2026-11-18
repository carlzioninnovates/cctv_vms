<?php

namespace App\Models;

use App\Enums\OfflineCause;
use Illuminate\Database\Eloquent\Model;

class OfflineIncident extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cause' => OfflineCause::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'reclassified_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
