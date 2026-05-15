<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a per-organization link to an external module in the
 * Glasshouse ecosystem. Stores identity/routing metadata only — no
 * credentials, tokens, or session data are persisted here.
 *
 * auth_mode values:
 *   local          — user already has credentials in the module; link is informational
 *   standalone     — module has its own login; GlassPortal provides a launch URL only
 *   api_token      — GlassPortal uses a service-level API token (server-side only, never browser)
 *   shared_session — FUTURE: shared cookie/JWT domain SSO (not implemented)
 *   signed_launch  — FUTURE: time-limited signed URL exchange (not implemented)
 *   oauth          — FUTURE: OAuth 2.0 / OIDC (not implemented)
 */
class OrganizationModuleLink extends Model
{
    use HasFactory, SoftDeletes;

    public const AUTH_MODES = [
        'local',
        'standalone',
        'api_token',
        'shared_session',
        'signed_launch',
        'oauth',
    ];

    public const SAFE_LAUNCH_MODES = ['local', 'standalone', 'api_token'];

    public const FUTURE_SSO_MODES = ['shared_session', 'signed_launch', 'oauth'];

    public const STATUSES = ['active', 'inactive', 'pending', 'error'];

    protected $fillable = [
        'organization_id',
        'module_key',
        'display_name',
        'external_account_id',
        'external_url',
        'auth_mode',
        'status',
        'last_seen_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'     => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function launchEvents(): HasMany
    {
        return $this->hasMany(ModuleLaunchEvent::class, 'module_link_id');
    }

    // -------------------------------------------------------------------------
    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForModule(Builder $query, string $moduleKey): Builder
    {
        return $query->where('module_key', $moduleKey);
    }

    // -------------------------------------------------------------------------
    // Helpers

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSsoMode(): bool
    {
        return in_array($this->auth_mode, self::FUTURE_SSO_MODES, true);
    }

    public function isSignedLaunchMode(): bool
    {
        return $this->auth_mode === 'signed_launch';
    }

    public function isSafeLaunchMode(): bool
    {
        return in_array($this->auth_mode, self::SAFE_LAUNCH_MODES, true);
    }
}
