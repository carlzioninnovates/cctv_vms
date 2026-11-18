<?php

namespace App\Models;

use App\Enums\OrganizationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => OrganizationType::class];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** Ids of this org and its ancestors (tree is at most 3 deep: platform > reseller > client). */
    public function lineageIds(): array
    {
        $ids = [];
        $org = $this;
        while ($org && count($ids) < 5) {
            $ids[] = $org->id;
            $org = $org->parent;
        }

        return $ids;
    }

    /** The user's org and everything below it (max depth 3). */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $root = $user->organization_id;
        if ($root === null) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(fn (Builder $w) => $w
            ->where('organizations.id', $root)
            ->orWhere('organizations.parent_id', $root)
            ->orWhereIn('organizations.parent_id', self::query()->select('id')->where('parent_id', $root)));
    }
}
