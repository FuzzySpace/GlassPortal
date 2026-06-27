<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit record of a provisioning request transition (Phase 26).
 */
class ProvisioningRequestEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'provisioning_request_id',
        'event_type',
        'previous_status',
        'new_status',
        'actor_type',
        'actor_id',
        'message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProvisioningRequest::class, 'provisioning_request_id');
    }
}
