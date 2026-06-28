<?php

namespace App\Models;

use App\Models\Concerns\RedactsSensitiveArrays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer-submitted billing change request (Phase 28).
 *
 * This is a **workflow record only**. A customer requests a billing change
 * (cancel, change plan, billing support, pause/resume, update details); staff
 * review and act through the existing approval layers. The model owns the
 * request lifecycle state machine ({@see self::TRANSITIONS}); the
 * BillingChangeRequestService applies transitions. It NEVER calls Stripe,
 * mutates subscriptions/entitlements/provisioning, or touches infrastructure.
 */
class BillingChangeRequest extends Model
{
    use HasFactory, RedactsSensitiveArrays, SoftDeletes;

    // Lifecycle statuses.
    public const STATUS_SUBMITTED    = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED     = 'approved';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_COMPLETED    = 'completed';
    public const STATUS_CANCELLED    = 'cancelled';

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Requests no longer awaiting any action. */
    public const TERMINAL_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    // Request types a customer may submit.
    public const TYPE_CANCEL_SUBSCRIPTION   = 'cancel_subscription';
    public const TYPE_CHANGE_PLAN           = 'change_plan';
    public const TYPE_UPDATE_BILLING_DETAILS = 'update_billing_details';
    public const TYPE_BILLING_SUPPORT       = 'billing_support';
    public const TYPE_PAUSE_SERVICE         = 'pause_service';
    public const TYPE_RESUME_SERVICE        = 'resume_service';

    public const TYPES = [
        self::TYPE_CANCEL_SUBSCRIPTION,
        self::TYPE_CHANGE_PLAN,
        self::TYPE_UPDATE_BILLING_DETAILS,
        self::TYPE_BILLING_SUPPORT,
        self::TYPE_PAUSE_SERVICE,
        self::TYPE_RESUME_SERVICE,
    ];

    /**
     * Explicit allowed-transition map (current => [allowed next]). Anything not
     * listed is rejected by the workflow service.
     */
    public const TRANSITIONS = [
        self::STATUS_SUBMITTED    => [self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED     => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED     => [],
        self::STATUS_COMPLETED    => [],
        self::STATUS_CANCELLED    => [],
    ];

    protected $fillable = [
        'request_key',
        'organization_id',
        'user_id',
        'billing_subscription_id',
        'billing_plan_id',
        'requested_plan_id',
        'request_type',
        'status',
        'reason',
        'customer_message',
        'admin_notes',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'completed_at',
        'cancelled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at'  => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata'     => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class, 'billing_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'requested_plan_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // -------------------------------------------------------------------------
    // Scopes

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    // -------------------------------------------------------------------------
    // State helpers

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * A customer may withdraw their own request only while it is still
     * untouched by staff (status `submitted`). Once review starts it is locked
     * to the admin workflow.
     */
    public function isCustomerCancellable(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function typeLabel(): string
    {
        return match ($this->request_type) {
            self::TYPE_CANCEL_SUBSCRIPTION    => 'Cancel subscription',
            self::TYPE_CHANGE_PLAN            => 'Change plan',
            self::TYPE_UPDATE_BILLING_DETAILS => 'Update billing details',
            self::TYPE_BILLING_SUPPORT        => 'Billing support',
            self::TYPE_PAUSE_SERVICE          => 'Pause service',
            self::TYPE_RESUME_SERVICE         => 'Resume service',
            default                           => ucfirst(str_replace('_', ' ', (string) $this->request_type)),
        };
    }
}
