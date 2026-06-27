<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'billing_email',
        'status',
        'glassbilling_customer_id',
        'siona_workspace_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function moduleLinks(): HasMany
    {
        return $this->hasMany(OrganizationModuleLink::class);
    }

    public function billingServiceEntitlements(): HasMany
    {
        return $this->hasMany(BillingServiceEntitlement::class);
    }

    public function provisioningRequests(): HasMany
    {
        return $this->hasMany(ProvisioningRequest::class);
    }

    public function billingCheckoutSessions(): HasMany
    {
        return $this->hasMany(BillingCheckoutSession::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * True when this organization has been provisioned a SIONA workspace.
     */
    public function hasSionaWorkspace(): bool
    {
        return $this->siona_workspace_id !== null && $this->siona_workspace_id !== '';
    }

    /**
     * The active SIONA module link for this organization, if one exists.
     * Used by the provisioning service to enforce idempotency.
     */
    public function sionaModuleLink(): ?OrganizationModuleLink
    {
        return $this->moduleLinks()
            ->where('module_key', 'siona')
            ->orderByDesc('id')
            ->first();
    }
}
