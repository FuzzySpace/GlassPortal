<?php

namespace App\Services;

use App\Models\ModuleLaunchEvent;
use App\Models\OrganizationModuleLink;
use App\Models\User;

/**
 * Resolves safe launch metadata and orchestrates audited launch attempts.
 *
 * Security boundaries:
 * - Never exposes credentials, tokens, or session cookies to the browser.
 * - Launch URLs are only returned for auth modes that do not require
 *   server-side token exchange (local, standalone, api_token).
 * - SSO auth modes (shared_session, signed_launch, oauth) are reserved for
 *   Phase 8+. They return a stubbed response with no URL.
 * - Every launch attempt — regardless of outcome — creates a ModuleLaunchEvent.
 */
class ModuleLaunchService
{
    /** Auth modes that may produce a browser-safe launch URL. */
    private const SAFE_LAUNCH_MODES = OrganizationModuleLink::SAFE_LAUNCH_MODES;

    /** Auth modes reserved for future SSO implementation. */
    private const FUTURE_SSO_MODES = OrganizationModuleLink::FUTURE_SSO_MODES;

    // =========================================================================
    // Launch attempt (audited)
    // =========================================================================

    /**
     * Attempt a module launch on behalf of a user.
     *
     * Validates organization ownership, active status, and auth mode, then
     * records a ModuleLaunchEvent for every outcome.
     *
     * @return array{
     *   outcome: 'allowed'|'denied'|'stubbed',
     *   redirect_url: string|null,
     *   auth_mode: string,
     *   reason: string|null,
     * }
     */
    public function attemptLaunch(
        OrganizationModuleLink $link,
        User $user,
        string $ip = '',
        string $userAgent = '',
    ): array {
        // Defense-in-depth: verify org ownership even if controller already checked
        if ((int) $link->organization_id !== (int) $user->organization_id) {
            $this->recordEvent($link, $user, 'denied', 'Organization mismatch', $ip, $userAgent);
            return $this->denied('You do not have access to this module link.');
        }

        // Must be active
        if (! $link->isActive()) {
            $this->recordEvent($link, $user, 'denied', "Link is {$link->status}", $ip, $userAgent);
            return $this->denied("This module link is {$link->status} and cannot be launched.");
        }

        // SSO modes — safe stub, no redirect
        if ($link->isSsoMode()) {
            $this->recordEvent($link, $user, 'stubbed', "SSO mode: {$link->auth_mode}", $ip, $userAgent);
            return [
                'outcome'      => 'stubbed',
                'redirect_url' => null,
                'auth_mode'    => $link->auth_mode,
                'reason'       => ucfirst(str_replace('_', ' ', $link->auth_mode))
                    . ' authentication is not yet available. This feature is planned for a future release.',
            ];
        }

        // Safe mode — issue redirect URL if configured
        $url = $link->external_url ?? null;
        if (empty($url)) {
            $this->recordEvent($link, $user, 'denied', 'No external URL configured', $ip, $userAgent);
            return $this->denied('No launch URL is configured for this module link. Contact your administrator.');
        }

        $this->recordEvent($link, $user, 'allowed', null, $ip, $userAgent);
        return [
            'outcome'      => 'allowed',
            'redirect_url' => $url,
            'auth_mode'    => $link->auth_mode,
            'reason'       => null,
        ];
    }

    // =========================================================================
    // Read-only metadata (no audit trail — display only)
    // =========================================================================

    /**
     * Build safe launch metadata for a single module link (no audit event).
     *
     * @return array{
     *   module_key: string,
     *   display_name: string,
     *   status: string,
     *   auth_mode: string,
     *   launch_url: string|null,
     *   setup_required: bool,
     *   warnings: string[],
     *   link_id: int|null,
     * }
     */
    public function getLaunchData(OrganizationModuleLink $link): array
    {
        $warnings = [];
        $launchUrl = $this->safeLaunchUrl($link, $warnings);

        return [
            'module_key'     => $link->module_key,
            'display_name'   => $link->display_name,
            'status'         => $link->status,
            'auth_mode'      => $link->auth_mode,
            'launch_url'     => $launchUrl,
            'setup_required' => $this->isSetupRequired($link),
            'warnings'       => $warnings,
            'link_id'        => $link->id,
        ];
    }

    /**
     * Build launch metadata for every link belonging to an organization.
     *
     * @param  iterable<OrganizationModuleLink>  $links
     * @return array<int, array>
     */
    public function getLaunchDataForAll(iterable $links): array
    {
        $result = [];
        foreach ($links as $link) {
            $result[] = $this->getLaunchData($link);
        }
        return $result;
    }

    /**
     * Merge config-registered modules with the org's persisted links to
     * produce a unified module list. Modules with no link record are shown
     * with status = 'not_linked'.
     *
     * @param  iterable<OrganizationModuleLink>  $links
     * @return array<string, array>  keyed by module_key
     */
    public function mergeWithRegistry(iterable $links): array
    {
        $registry = config('glasshouse.launch_modules', []);
        $linked   = [];

        foreach ($links as $link) {
            $linked[$link->module_key] = $this->getLaunchData($link);
        }

        $merged = [];
        foreach ($registry as $key => $meta) {
            $merged[$key] = array_merge(
                [
                    'module_key'     => $key,
                    'display_name'   => $meta['display_name'],
                    'description'    => $meta['description'] ?? '',
                    'status'         => 'not_linked',
                    'auth_mode'      => 'standalone',
                    'launch_url'     => null,
                    'setup_required' => true,
                    'warnings'       => [],
                    'link_id'        => null,
                ],
                $linked[$key] ?? []
            );
        }

        return $merged;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function recordEvent(
        OrganizationModuleLink $link,
        User $user,
        string $eventType,
        ?string $reason,
        string $ip,
        string $userAgent,
    ): void {
        ModuleLaunchEvent::create([
            'organization_id' => $link->organization_id,
            'user_id'         => $user->id,
            'module_link_id'  => $link->id,
            'module_key'      => $link->module_key,
            'auth_mode'       => $link->auth_mode,
            'event_type'      => $eventType,
            'reason'          => $reason,
            'ip_address'      => $ip ?: null,
            'user_agent'      => $userAgent ?: null,
            'metadata'        => null,
        ]);
    }

    private function denied(string $reason): array
    {
        return [
            'outcome'      => 'denied',
            'redirect_url' => null,
            'auth_mode'    => '',
            'reason'       => $reason,
        ];
    }

    private function safeLaunchUrl(OrganizationModuleLink $link, array &$warnings): ?string
    {
        if ($link->status !== 'active') {
            $warnings[] = "Module is {$link->status} — launch unavailable.";
            return null;
        }

        if (in_array($link->auth_mode, self::FUTURE_SSO_MODES, true)) {
            $warnings[] = ucfirst(str_replace('_', ' ', $link->auth_mode))
                . ' authentication is not yet implemented (Phase 8+).';
            return null;
        }

        if (empty($link->external_url)) {
            if (! in_array($link->auth_mode, ['local'], true)) {
                $warnings[] = 'No launch URL configured for this module link.';
            }
            return null;
        }

        return $link->external_url;
    }

    private function isSetupRequired(OrganizationModuleLink $link): bool
    {
        if ($link->status !== 'active') {
            return true;
        }

        if (in_array($link->auth_mode, self::FUTURE_SSO_MODES, true)) {
            return true;
        }

        if ($link->auth_mode !== 'local' && empty($link->external_url)) {
            return true;
        }

        return false;
    }
}
