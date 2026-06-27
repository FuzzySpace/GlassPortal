<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A billing service entitlement (Phase 25).
 *
 * GlassBilling's authoritative statement of what a customer is allowed to
 * receive, with a lifecycle status. This model owns the lifecycle state machine
 * (see {@see self::TRANSITIONS}); the BillingEntitlementService applies the
 * transitions and records events. **It never mutates infrastructure.**
 */
class BillingServiceEntitlement extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING              = 'pending';
    public const STATUS_ACTIVE               = 'active';
    public const STATUS_PAST_DUE             = 'past_due';
    public const STATUS_SUSPENDED            = 'suspended';
    public const STATUS_CANCELLED            = 'cancelled';
    public const STATUS_TERMINATED           = 'terminated';
    public const STATUS_EXPIRED              = 'expired';
    public const STATUS_PROVISIONING_PENDING = 'provisioning_pending';
    public const STATUS_PROVISIONING_FAILED  = 'provisioning_failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_SUSPENDED,
        self::STATUS_CANCELLED,
        self::STATUS_TERMINATED,
        self::STATUS_EXPIRED,
        self::STATUS_PROVISIONING_PENDING,
        self::STATUS_PROVISIONING_FAILED,
    ];

    /** Statuses from which the entitlement is no longer a live grant. */
    public const TERMINAL_STATUSES = [
        self::STATUS_CANCELLED,
        self::STATUS_TERMINATED,
        self::STATUS_EXPIRED,
    ];

    /**
     * The explicit allowed-transition map (current => [allowed next statuses]).
     * Any transition not listed here is rejected by the lifecycle service.
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING              => [self::STATUS_ACTIVE, self::STATUS_CANCELLED, self::STATUS_PROVISIONING_PENDING],
        self::STATUS_ACTIVE               => [self::STATUS_SUSPENDED, self::STATUS_CANCELLED, self::STATUS_TERMINATED, self::STATUS_PROVISIONING_PENDING, self::STATUS_PAST_DUE, self::STATUS_EXPIRED],
        self::STATUS_PAST_DUE             => [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_CANCELLED],
        self::STATUS_PROVISIONING_PENDING => [self::STATUS_ACTIVE, self::STATUS_PROVISIONING_FAILED],
        self::STATUS_PROVISIONING_FAILED  => [self::STATUS_PROVISIONING_PENDING, self::STATUS_CANCELLED],
        self::STATUS_SUSPENDED            => [self::STATUS_ACTIVE, self::STATUS_CANCELLED, self::STATUS_TERMINATED],
        self::STATUS_CANCELLED            => [self::STATUS_TERMINATED],
        self::STATUS_EXPIRED              => [self::STATUS_TERMINATED],
        self::STATUS_TERMINATED           => [],
    ];

    protected $fillable = [
        'billing_customer_id',
        'billing_subscription_id',
        'billing_product_id',
        'billing_plan_id',
        'organization_id',
        'user_id',
        'entitlement_key',
        'service_type',
        'module_key',
        'product_key',
        'name',
        'description',
        'status',
        'quantity',
        'starts_at',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'suspended_at',
        'cancelled_at',
        'terminated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity'             => 'integer',
            'starts_at'            => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end'   => 'datetime',
            'trial_ends_at'        => 'datetime',
            'suspended_at'         => 'datetime',
            'cancelled_at'         => 'datetime',
            'terminated_at'        => 'datetime',
            'metadata'             => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class, 'billing_subscription_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(BillingProduct::class, 'billing_product_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BillingServiceEntitlementEvent::class)->latest('id');
    }

    // -------------------------------------------------------------------------
    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeTerminated(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_TERMINATED);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    // -------------------------------------------------------------------------
    // State helpers

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /** True when the entitlement is in an end-of-life status. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** Whether `status` may legally transition to `$to` per the map. */
    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Eligible for provisioning (billing says yes, not suspended/terminal). */
    public function canProvision(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_ACTIVE,
            self::STATUS_PROVISIONING_PENDING,
            self::STATUS_PROVISIONING_FAILED,
        ], true);
    }

    public function canSuspend(): bool
    {
        return $this->canTransitionTo(self::STATUS_SUSPENDED);
    }

    public function canReactivate(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function canCancel(): bool
    {
        return $this->canTransitionTo(self::STATUS_CANCELLED);
    }

    public function canTerminate(): bool
    {
        return $this->canTransitionTo(self::STATUS_TERMINATED);
    }
}
