<?php

namespace App\Models;

use App\Models\Concerns\RedactsSensitiveArrays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mirrors a Stripe invoice (Phase 24). Amounts in integer minor units (cents).
 */
class BillingInvoice extends Model
{
    use HasFactory, RedactsSensitiveArrays, SoftDeletes;

    protected $fillable = [
        'billing_customer_id',
        'stripe_invoice_id',
        'status',
        'amount_due_cents',
        'amount_paid_cents',
        'currency',
        'due_at',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_due_cents'  => 'integer',
            'amount_paid_cents' => 'integer',
            'due_at'            => 'datetime',
            'paid_at'           => 'datetime',
            'metadata'          => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
