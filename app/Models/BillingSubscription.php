<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mirrors a Stripe subscription (Phase 24). The plan is nullable so a record
 * can exist before its plan is reconciled.
 */
class BillingSubscription extends Model
{
    use HasFactory, SoftDeletes;

    /** Statuses considered "live" for access purposes. */
    public const LIVE_STATUSES = ['active', 'trialing'];

    protected $fillable = [
        'billing_customer_id',
        'billing_plan_id',
        'stripe_subscription_id',
        'status',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end'   => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'metadata'             => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }
}
