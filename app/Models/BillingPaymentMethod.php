<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Safe display data for a Stripe payment method (Phase 24). Stores only brand /
 * last4 / expiry — NEVER full card numbers, CVC, or raw tokens.
 */
class BillingPaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'billing_customer_id',
        'stripe_payment_method_id',
        'type',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'is_default',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'exp_month'  => 'integer',
            'exp_year'   => 'integer',
            'is_default' => 'boolean',
            'metadata'   => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /** e.g. "Visa •••• 4242". */
    public function label(): string
    {
        $brand = $this->brand ? ucfirst($this->brand) : ($this->type ?: 'Card');

        return $this->last4 ? "{$brand} •••• {$this->last4}" : $brand;
    }
}
