<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A priced offering of a billing product (Phase 24), mapped to a Stripe price.
 * Amounts are stored in integer minor units (cents).
 */
class BillingPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'billing_product_id',
        'plan_key',
        'stripe_price_id',
        'name',
        'amount_cents',
        'currency',
        'interval',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'metadata'     => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(BillingProduct::class, 'billing_product_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class);
    }

    public function serviceEntitlements(): HasMany
    {
        return $this->hasMany(BillingServiceEntitlement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Display-only price label, e.g. "$49.00/mo". */
    public function priceLabel(): string
    {
        $currency = $this->currency ?: 'USD';
        $amount   = number_format($this->amount_cents / 100, 2);
        $price    = $currency === 'USD' ? "\${$amount}" : "{$amount} {$currency}";

        $suffix = match ($this->interval) {
            'month'    => '/mo',
            'year'     => '/yr',
            'week'     => '/wk',
            'day'      => '/day',
            'one_time' => ' one-time',
            null, ''   => '',
            default    => "/{$this->interval}",
        };

        return "{$price}{$suffix}";
    }
}
