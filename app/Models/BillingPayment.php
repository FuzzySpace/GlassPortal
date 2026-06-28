<?php

namespace App\Models;

use App\Models\Concerns\RedactsSensitiveArrays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mirrors a Stripe PaymentIntent (Phase 24). The invoice is nullable. Amounts
 * in integer minor units (cents).
 */
class BillingPayment extends Model
{
    use HasFactory, RedactsSensitiveArrays, SoftDeletes;

    protected $fillable = [
        'billing_customer_id',
        'billing_invoice_id',
        'stripe_payment_intent_id',
        'status',
        'amount_cents',
        'currency',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_at'      => 'datetime',
            'metadata'     => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', 'succeeded');
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
