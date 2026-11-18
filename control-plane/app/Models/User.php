<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Roles allowed to add sites, agents and NVRs. */
    public const MANAGE_ROLES = ['owner', 'admin', 'installer'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',
        'role',
        'phone',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** True when $org is the user's org or below it (a reseller manages its clients). */
    public function canManage(Organization $org): bool
    {
        return $this->organization_id !== null
            && in_array($this->role, self::MANAGE_ROLES, true)
            && in_array($this->organization_id, $org->lineageIds(), true);
    }

    public function canView(Organization $org): bool
    {
        return $this->organization_id !== null && in_array($this->organization_id, $org->lineageIds(), true);
    }
}
