<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An approval-gated provisioning request (Phase 26).
 *
 * Records the intent to fulfill/change a billing entitlement and the lifecycle
 * of that request. This model owns the request state machine
 * ({@see self::TRANSITIONS}); ProvisioningRequestService applies transitions.
 * **It never executes infrastructure** — drivers (Phase 27+) consume requests.
 */
class ProvisioningRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_QUEUED           = 'queued';
    public const STATUS_RUNNING          = 'running';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_FAILED           = 'failed';
    public const STATUS_CANCELLED        = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    public const ACTION_PROVISION  = 'provision';
    public const ACTION_SUSPEND    = 'suspend';
    public const ACTION_REACTIVATE = 'reactivate';
    public const ACTION_CANCEL     = 'cancel';
    public const ACTION_TERMINATE  = 'terminate';
    public const ACTION_UPDATE     = 'update';
    public const ACTION_MIGRATE    = 'migrate';

    public const ACTIONS = [
        self::ACTION_PROVISION,
        self::ACTION_SUSPEND,
        self::ACTION_REACTIVATE,
        self::ACTION_CANCEL,
        self::ACTION_TERMINATE,
        self::ACTION_UPDATE,
        self::ACTION_MIGRATE,
    ];

    /** Explicit allowed-transition map (current => [allowed next statuses]). */
    public const TRANSITIONS = [
        self::STATUS_DRAFT            => [self::STATUS_PENDING_APPROVAL, self::STATUS_CANCELLED],
        self::STATUS_PENDING_APPROVAL => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED         => [self::STATUS_QUEUED, self::STATUS_CANCELLED],
        self::STATUS_QUEUED           => [self::STATUS_RUNNING, self::STATUS_CANCELLED],
        self::STATUS_RUNNING          => [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_FAILED           => [self::STATUS_QUEUED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED        => [],
        self::STATUS_REJECTED         => [],
        self::STATUS_CANCELLED        => [],
    ];

    protected $fillable = [
        'request_key',
        'billing_service_entitlement_id',
        'billing_customer_id',
        'organization_id',
        'user_id',
        'module_key',
        'product_key',
        'service_type',
        'driver_key',
        'requested_action',
        'status',
        'priority',
        'requires_approval',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'assigned_to',
        'scheduled_for',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'idempotency_key',
        'reason',
        'failure_reason',
        'payload',
        'result',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'approved_at'       => 'datetime',
            'rejected_at'       => 'datetime',
            'scheduled_for'     => 'datetime',
            'started_at'        => 'datetime',
            'completed_at'      => 'datetime',
            'failed_at'         => 'datetime',
            'cancelled_at'      => 'datetime',
            'payload'           => 'array',
            'result'            => 'array',
            'metadata'          => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BillingServiceEntitlement::class, 'billing_service_entitlement_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProvisioningRequestEvent::class)->latest('id');
    }

    // -------------------------------------------------------------------------
    // Scopes

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('requested_action', $action);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    // -------------------------------------------------------------------------
    // State helpers

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function canApprove(): bool
    {
        return $this->canTransitionTo(self::STATUS_APPROVED);
    }

    public function canReject(): bool
    {
        return $this->canTransitionTo(self::STATUS_REJECTED);
    }

    public function canQueue(): bool
    {
        return $this->canTransitionTo(self::STATUS_QUEUED);
    }

    public function canStart(): bool
    {
        return $this->canTransitionTo(self::STATUS_RUNNING);
    }

    public function canComplete(): bool
    {
        return $this->canTransitionTo(self::STATUS_COMPLETED);
    }

    public function canFail(): bool
    {
        return $this->canTransitionTo(self::STATUS_FAILED);
    }

    public function canCancel(): bool
    {
        return $this->canTransitionTo(self::STATUS_CANCELLED);
    }

    // -------------------------------------------------------------------------
    // Safe display — never surface secret-shaped values, even to admins.

    /** Key-name substrings whose values are redacted before display. */
    public const SENSITIVE_KEY_PATTERNS = [
        'token', 'secret', 'password', 'passwd', 'private_key', 'api_key', 'apikey', 'credential',
    ];

    /**
     * Recursively redact secret-shaped values from an array by key name.
     *
     * @param  array<mixed>|null  $data
     * @return array<mixed>
     */
    public static function redact(?array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && \Illuminate\Support\Str::contains(strtolower($key), self::SENSITIVE_KEY_PATTERNS)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function safePayload(): array
    {
        return self::redact($this->payload);
    }

    public function safeResult(): array
    {
        return self::redact($this->result);
    }

    public function safeMetadata(): array
    {
        return self::redact($this->metadata);
    }
}

