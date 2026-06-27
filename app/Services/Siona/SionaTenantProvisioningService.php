<?php

namespace App\Services\Siona;

use App\Models\ModuleLaunchEvent;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;

/**
 * Orchestrates SIONA tenant (workspace) provisioning and account linking for a
 * GlassPortal organization. Phase 20.
 *
 * Responsibilities:
 *  - Enforce idempotency: an org with a workspace id + active SIONA link is a
 *    no-op (already_linked) and makes no outbound call.
 *  - Gate on configuration: provisioning only proceeds when the feature is
 *    enabled and the back-channel credentials are present.
 *  - Delegate the outbound HTTP to SionaConnectorClient (no duplicate client).
 *  - Persist the returned workspace id on organizations.siona_workspace_id.
 *  - Create or update the organization_module_link with module_key=siona.
 *  - Record an audit trail in module_launch_events (the existing authoritative
 *    audit log — no parallel audit system is introduced).
 *
 * Security:
 *  - This service never reads or handles SIONA_API_TOKEN; only the connector
 *    client does. Nothing token-bearing is ever written to the audit metadata.
 */
class SionaTenantProvisioningService
{
    public const MODULE_KEY = 'siona';

    // Audit event types written to module_launch_events.
    public const EVENT_REQUESTED      = 'siona_provision_requested';
    public const EVENT_SUCCEEDED      = 'siona_provision_succeeded';
    public const EVENT_FAILED         = 'siona_provision_failed';
    public const EVENT_ALREADY_LINKED = 'siona_already_linked';
    public const EVENT_LINK_CREATED   = 'siona_module_link_created';
    public const EVENT_LINK_UPDATED   = 'siona_module_link_updated';

    public function __construct(private SionaConnectorClient $client) {}

    /**
     * Provision (or repair the link for) a SIONA workspace for an organization.
     *
     * @param array{ip?: string, user_agent?: string} $context Request context for the audit trail.
     */
    public function provisionForOrganization(
        Organization $organization,
        ?User $actor = null,
        array $context = [],
    ): SionaTenantProvisioningResult {
        $authMode = $this->defaultAuthMode();

        // Always record that a provisioning attempt was requested.
        $this->audit($organization, $actor, self::EVENT_REQUESTED, null, $authMode, $context);

        $existingLink = $organization->sionaModuleLink();

        // Idempotency: workspace mapped AND an active link already exists.
        if ($organization->hasSionaWorkspace() && $existingLink !== null && $existingLink->isActive()) {
            $this->audit($organization, $actor, self::EVENT_ALREADY_LINKED, $existingLink, $authMode, $context, null, [
                'workspace_id' => $organization->siona_workspace_id,
            ]);

            return SionaTenantProvisioningResult::alreadyLinked(
                $organization->siona_workspace_id,
                $existingLink->id,
                'SIONA workspace already provisioned and linked for this organization.',
            );
        }

        // Configuration gate.
        if (! $this->client->isProvisioningConfigured()) {
            $this->audit($organization, $actor, self::EVENT_FAILED, $existingLink, $authMode, $context, 'unconfigured');

            return SionaTenantProvisioningResult::unconfigured(
                'SIONA tenant provisioning is not configured. Set SIONA_ENABLED=true, '
                . 'SIONA_PROVISIONING_ENABLED=true, SIONA_API_URL, and SIONA_API_TOKEN.',
            );
        }

        // Reuse an already-known workspace id if one is recorded anywhere, else
        // ask SIONA to create a tenant.
        $workspaceId = $this->knownWorkspaceId($organization, $existingLink);

        if ($workspaceId === null || $workspaceId === '') {
            $result = $this->client->provisionTenant($this->buildPayload($organization));

            if (! $result['ok']) {
                $this->audit($organization, $actor, self::EVENT_FAILED, $existingLink, $authMode, $context, $result['status'], [
                    'http_status' => $result['http_status'],
                ]);

                return SionaTenantProvisioningResult::failed($result['message']);
            }

            $workspaceId = $result['workspace_id'];
        }

        // Persist the workspace id as the source of truth on the organization.
        if ($organization->siona_workspace_id !== $workspaceId) {
            $organization->forceFill(['siona_workspace_id' => $workspaceId])->save();
        }

        // Create or update the SIONA module link.
        [$link, $created] = $this->upsertModuleLink($organization, $existingLink, $workspaceId, $authMode);

        $this->audit(
            $organization,
            $actor,
            $created ? self::EVENT_LINK_CREATED : self::EVENT_LINK_UPDATED,
            $link,
            $authMode,
            $context,
            null,
            ['workspace_id' => $workspaceId],
        );

        $this->audit($organization, $actor, self::EVENT_SUCCEEDED, $link, $authMode, $context, null, [
            'workspace_id' => $workspaceId,
        ]);

        return SionaTenantProvisioningResult::provisioned(
            $workspaceId,
            $link->id,
            'SIONA workspace provisioned and linked.',
        );
    }

    // -------------------------------------------------------------------------

    private function defaultAuthMode(): string
    {
        $mode = (string) config('siona.provisioning.default_auth_mode', 'signed_launch');

        return in_array($mode, OrganizationModuleLink::AUTH_MODES, true) ? $mode : 'signed_launch';
    }

    /**
     * Find a workspace id already recorded for this org, in precedence order:
     * the dedicated column, then the link's external_account_id, then the
     * Phase 19 metadata key. Returns null when none is known.
     */
    private function knownWorkspaceId(Organization $organization, ?OrganizationModuleLink $link): ?string
    {
        $candidates = [
            $organization->siona_workspace_id,
            $link?->external_account_id,
            $link?->metadata['siona_workspace_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Create the SIONA module link, or update the existing one in place.
     *
     * @return array{0: OrganizationModuleLink, 1: bool}  [link, wasCreated]
     */
    private function upsertModuleLink(
        Organization $organization,
        ?OrganizationModuleLink $existing,
        string $workspaceId,
        string $authMode,
    ): array {
        $launchUrl = $existing?->external_url ?: ((string) config('siona.launch_url', '') ?: null);

        $attributes = [
            'display_name'        => $existing?->display_name ?: 'SIONA',
            'external_account_id' => $workspaceId,
            'external_url'        => $launchUrl,
            'auth_mode'           => $authMode,
            'status'              => 'active',
            'metadata'            => array_merge(
                (array) ($existing?->metadata ?? []),
                ['siona_workspace_id' => $workspaceId],
            ),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return [$existing->refresh(), false];
        }

        $link = OrganizationModuleLink::create(array_merge($attributes, [
            'organization_id' => $organization->id,
            'module_key'      => self::MODULE_KEY,
        ]));

        return [$link, true];
    }

    /**
     * Build the (credential-free) payload sent to SIONA's tenant API.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Organization $organization): array
    {
        return [
            'source'       => 'glassportal',
            'organization' => [
                'external_id'   => (string) $organization->id,
                'name'          => $organization->name,
                'slug'          => $organization->slug,
                'billing_email' => $organization->billing_email,
            ],
        ];
    }

    /**
     * Append a provisioning event to the authoritative module_launch_events log.
     *
     * Only safe fields are stored — never tokens, response bodies, or secrets.
     *
     * @param array{ip?: string, user_agent?: string} $context
     * @param array<string, mixed>                     $metadata
     */
    private function audit(
        Organization $organization,
        ?User $actor,
        string $eventType,
        ?OrganizationModuleLink $link,
        string $authMode,
        array $context,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        ModuleLaunchEvent::create([
            'organization_id' => $organization->id,
            'user_id'         => $actor?->id,
            'module_link_id'  => $link?->id,
            'module_key'      => self::MODULE_KEY,
            'auth_mode'       => $authMode,
            'event_type'      => $eventType,
            'reason'          => $reason,
            'ip_address'      => ($context['ip'] ?? '') ?: null,
            'user_agent'      => ($context['user_agent'] ?? '') ?: null,
            'metadata'        => $metadata !== [] ? $metadata : null,
        ]);
    }
}
