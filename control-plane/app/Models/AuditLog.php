<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only (RA 10173). Use AuditLog::record(). */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public static function record(string $action, ?Model $subject = null, array $context = [], ?User $user = null): self
    {
        $user ??= auth()->user();
        $req = request();

        return self::create([
            'organization_id' => $user?->organization_id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'context' => $context ?: null,
            'ip' => $req?->ip(),
            'user_agent' => substr((string) $req?->userAgent(), 0, 255) ?: null,
        ]);
    }
}
