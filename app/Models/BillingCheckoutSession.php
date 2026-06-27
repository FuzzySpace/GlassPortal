<?php

namespace App\Models;

use App\Models\Concerns\RedactsSensitiveArrays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Local mirror of a Stripe Checkout Session (Phase 27). Created when a customer
 * starts checkout; the webhook marks it complete and links the resulting
 * records. Provider payload is redacted on display.
 */
class BillingCheckoutSession extends Model
{
    use HasFactory, RedactsSensitiveArrays, SoftDeletes;

    public const STATUS_OPEN     = 'open';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_EXPIRED  = 'expired';

    protected $fillable = [
        'billing_customer_id',
        'billing_product_id',
        'billing_plan_id',
        'billing_subscription_id',
        'organization_id',
        'user_id',
        'provider',
        'provider_session_id',
        'provider_customer_id',
        'provider_subscription_id',
        'mode',
        'status',
        'payment_status',
        'currency',
        'amount_total',
        'success_url',
        'cancel_url',
        'expires_at',
        'completed_at',
        'payload',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_total' => 'integer',
            'expires_at'   => 'datetime',
            'completed_at' => 'datetime',
            'payload'      => 'array',
            'metadata'     => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(BillingProduct::class, 'billing_product_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class, 'billing_subscription_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------------------------
    // Scopes + helpers

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETE);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expires_at !== null && $this->expires_at->isPast() && ! $this->isComplete());
    }
}
