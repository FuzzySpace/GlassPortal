<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit record of an entitlement lifecycle transition (Phase 25).
 * Records previous/new status, the acting party, and a reason.
 */
class BillingServiceEntitlementEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'billing_service_entitlement_id',
        'event_type',
        'previous_status',
        'new_status',
        'actor_type',
        'actor_id',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BillingServiceEntitlement::class, 'billing_service_entitlement_id');
    }
}
