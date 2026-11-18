<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Channel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'online' => 'boolean',
            'ptz' => 'boolean',
            'recording' => 'boolean',
            'analytics' => 'array',
        ];
    }

    public function nvr(): BelongsTo
    {
        return $this->belongsTo(Nvr::class);
    }
}
