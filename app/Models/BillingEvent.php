<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Idempotent intake log for provider (Stripe) webhook events (Phase 24).
 *
 * `provider_event_id` is unique at the DB layer, so a replayed/duplicate event
 * cannot be recorded twice. No soft deletes — this is an append-mostly log.
 */
class BillingEvent extends Model
{
    use HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_IGNORED   = 'ignored';

    protected $fillable = [
        'event_type',
        'provider',
        'provider_event_id',
        'related_type',
        'related_id',
        'payload',
        'processed_at',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** Optional polymorphic link to the local billing record this event touched. */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function markProcessed(): void
    {
        $this->update(['status' => self::STATUS_PROCESSED, 'processed_at' => now(), 'error_message' => null]);
    }

    public function markFailed(string $message): void
    {
        $this->update(['status' => self::STATUS_FAILED, 'error_message' => $message]);
    }
}
