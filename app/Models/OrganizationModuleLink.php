<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSsoMode(): bool
    {
        return in_array($this->auth_mode, ['shared_session', 'signed_launch', 'oauth'], true);
    }
}
