<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit record of every module launch attempt.
 * Never soft-deleted — this is the authoritative audit trail.
 * Denormalized module_key and auth_mode are preserved so the record
 * remains meaningful even if the parent link is soft-deleted.
 *
 * event_type values:
 *   allowed — launch URL was issued to the user
 *   denied  — launch was blocked (wrong org, inactive link, etc.)
 *   stubbed — SSO mode placeholder response returned (Phase 7+)
 *   failed  — unexpected error prevented launch
 */
class ModuleLaunchEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'user_id',
        'module_link_id',
        'module_key',
        'auth_mode',
        'event_type',
        'reason',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moduleLink(): BelongsTo
    {
        return $this->belongsTo(OrganizationModuleLink::class, 'module_link_id');
    }
}
