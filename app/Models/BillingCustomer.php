<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GlassBilling account record (Phase 24). Optionally mapped to a portal
 * organization/user and to a Stripe customer. Stores no card data or secrets.
 */
class BillingCustomer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'user_id',
        'stripe_customer_id',
        'name',
        'email',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(BillingPaymentMethod::class);
    }

    public function serviceEntitlements(): HasMany
    {
        return $this->hasMany(BillingServiceEntitlement::class);
    }

    public function provisioningRequests(): HasMany
    {
        return $this->hasMany(ProvisioningRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeLinkedToStripe(Builder $query): Builder
    {
        return $query->whereNotNull('stripe_customer_id');
    }

    public function isLinkedToStripe(): bool
    {
        return $this->stripe_customer_id !== null && $this->stripe_customer_id !== '';
    }
}
