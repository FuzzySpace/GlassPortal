<?php

namespace App\Services;

use App\Models\ModuleLaunchEvent;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\BackChannelLaunchService;
use App\Services\Sso\SignedLaunchTokenService;

/**
 * Resolves safe launch metadata and orchestrates audited launch attempts.
 *
 * Security boundaries:
 * - Never exposes credentials, tokens, or session cookies to the browser.
 * - Phase 8: signed_launch is operational — generates HMAC-signed tokens.
 *   The signing secret is server-side only and NEVER included in any response.
 * - shared_session and oauth remain stubs (Phase 9+).
 * - Every launch attempt — regardless of outcome — creates a ModuleLaunchEvent.
 * - Audit metadata stores only: jti, expires_at, module_key, auth_mode. Never the token.
 */
class ModuleLaunchService
{
    /** Auth modes where a plain external URL is safe to issue directly. */
    private const SAFE_LAUNCH_MODES = OrganizationModuleLink::SAFE_LAUNCH_MODES;

    /** Auth modes still reserved for future implementation. */
    private const STUB_SSO_MODES = ['shared_session', 'oauth'];

    // =========================================================================
    // Audited launch attempt
    // =========================================================================

    /**
     * Attempt a module launch on behalf of a user.
     *
     * Validates organization ownership, active status, and auth mode, then
     * records a ModuleLaunchEvent for every outcome. Never leaks secrets.
     *
     * @return array{
     *   outcome: 'allowed'|'signed_launch'|'backchannel_launch'|'denied'|'stubbed',
     *   redirect_url: string|null,
     *   token: string|null,
     *   launch_code: string|null,
     *   auth_mode: string,
     *   reason: string|null,
     *   jti: string|null,
     *   expires_at: int|null,
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

        // Phase 8: signed_launch is now operational
        if ($link->isSignedLaunchMode()) {
            return $this->handleSignedLaunch($link, $user, $ip, $userAgent);
        }

        // Phase 11: back-channel launch
        if ($link->isBackChannelLaunchMode()) {
            return $this->handleBackChannelLaunch($link, $user, $ip, $userAgent);
        }

        // Remaining stub SSO modes (shared_session, oauth → Phase 9+)
        if (in_array($link->auth_mode, self::STUB_SSO_MODES, true)) {
            $this->recordEvent($link, $user, 'stubbed', "SSO stub: {$link->auth_mode}", $ip, $userAgent);
            return [
                'outcome'      => 'stubbed',
                'redirect_url' => null,
                'token'        => null,
                'launch_code'  => null,
                'auth_mode'    => $link->auth_mode,
                'reason'       => ucfirst(str_replace('_', ' ', $link->auth_mode))
                    . ' authentication is not yet available. This feature is planned for a future release.',
                'jti'          => null,
                'expires_at'   => null,
            ];
        }

        // Safe external URL modes
        $url = $link->external_url ?? null;
        if (empty($url)) {
            $this->recordEvent($link, $user, 'denied', 'No external URL configured', $ip, $userAgent);
            return $this->denied('No launch URL is configured for this module link. Contact your administrator.');
        }

        $this->recordEvent($link, $user, 'allowed', null, $ip, $userAgent);
        return [
            'outcome'      => 'allowed',
            'redirect_url' => $url,
            'token'        => null,
            'launch_code'  => null,
            'auth_mode'    => $link->auth_mode,
            'reason'       => null,
            'jti'          => null,
            'expires_at'   => null,
        ];
    }

    // =========================================================================
    // Read-only display metadata (no audit trail)
    // =========================================================================

    /**
     * Build safe display metadata for a single module link.
     * Does NOT generate tokens — that happens only on actual launch attempt.
     *
     * @return array{
     *   module_key: string,
     *   display_name: string,
     *   status: string,
     *   auth_mode: string,
     *   launch_url: string|null,
     *   setup_required: bool,
     *   can_launch: bool,
     *   warnings: string[],
     *   link_id: int|null,
     * }
     */
    public function getLaunchData(OrganizationModuleLink $link): array
    {
        $warnings  = [];
        $launchUrl = $this->safeLaunchUrl($link, $warnings);
        $canLaunch = $this->canLaunch($link);

        return [
            'module_key'     => $link->module_key,
            'display_name'   => $link->display_name,
            'status'         => $link->status,
            'auth_mode'      => $link->auth_mode,
            'launch_url'     => $launchUrl,
            'setup_required' => $this->isSetupRequired($link),
            'can_launch'     => $canLaunch,
            'warnings'       => $warnings,
            'link_id'        => $link->id,
        ];
    }

    /**
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
     * Merge config-registered modules with the org's persisted links.
     * Unlinked modules appear with status = 'not_linked'.
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
                    'can_launch'     => false,
                    'warnings'       => [],
                    'link_id'        => null,
                ],
                $linked[$key] ?? []
            );
        }

        return $merged;
    }

    // =========================================================================
    // Rate limit audit (Phase 9)
    // =========================================================================

    /**
     * Record a rate_limited event for a user who exceeded the launch throttle.
     * Called by the controller before the service's normal launch flow.
     */
    public function recordRateLimited(
        OrganizationModuleLink $link,
        User $user,
        string $ip = '',
        string $userAgent = '',
    ): void {
        $this->recordEvent($link, $user, 'rate_limited', 'Rate limit exceeded', $ip, $userAgent);
    }

    // =========================================================================
    // Signed launch (Phase 8)
    // =========================================================================

    private function handleSignedLaunch(
        OrganizationModuleLink $link,
        User $user,
        string $ip,
        string $userAgent,
    ): array {
        if (empty($link->external_url)) {
            $this->recordEvent($link, $user, 'failed', 'No launch endpoint configured', $ip, $userAgent);
            return $this->denied(
                'No launch endpoint is configured for this module link. Contact your administrator.'
            );
        }

        $secret = config('glasshouse_sso.signing_secret', '');
        if ($secret === '') {
            $this->recordEvent($link, $user, 'failed', 'Signing secret not configured', $ip, $userAgent);
            return $this->denied(
                'Signed launch is not available — system configuration is incomplete. Contact your administrator.'
            );
        }

        try {
            $tokenService = app(SignedLaunchTokenService::class);
            $result       = $tokenService->generate($link, $user);

            // Audit only safe fields — never the token value or signing secret
            $this->recordEvent($link, $user, 'signed_launch_issued', null, $ip, $userAgent, [
                'jti'        => $result['jti'],
                'expires_at' => $result['expires_at'],
            ]);

            return [
                'outcome'      => 'signed_launch',
                'redirect_url' => $link->external_url,
                'token'        => $result['token'],
                'launch_code'  => null,
                'auth_mode'    => 'signed_launch',
                'reason'       => null,
                'jti'          => $result['jti'],
                'expires_at'   => $result['expires_at'],
            ];
        } catch (\Throwable $e) {
            $this->recordEvent($link, $user, 'failed', 'Token generation error', $ip, $userAgent);
            return $this->denied(
                'Signed launch could not be completed. Contact your administrator.'
            );
        }
    }

    // =========================================================================
    // Back-channel launch (Phase 11)
    // =========================================================================

    private function handleBackChannelLaunch(
        OrganizationModuleLink $link,
        User $user,
        string $ip,
        string $userAgent,
    ): array {
        if (empty($link->external_url)) {
            $this->recordEvent($link, $user, 'failed', 'No launch endpoint configured', $ip, $userAgent);
            return $this->denied(
                'No launch endpoint is configured for this module link. Contact your administrator.'
            );
        }

        if (! config('glasshouse_sso.backchannel.enabled', false)) {
            $this->recordEvent($link, $user, 'failed', 'Back-channel SSO not enabled', $ip, $userAgent);
            return $this->denied(
                'Back-channel launch is not enabled. Contact your administrator.'
            );
        }

        try {
            $service = app(BackChannelLaunchService::class);
            $result  = $service->issueCode($link, $user);

            if (! $result->ok) {
                $this->recordEvent($link, $user, 'failed', "Code issue failed: {$result->reason}", $ip, $userAgent);
                return $this->denied(
                    'Back-channel launch could not be completed. Contact your administrator.'
                );
            }

            // Audit: never log the raw code — only timing data
            $this->recordEvent($link, $user, 'backchannel_code_issued', null, $ip, $userAgent, [
                'expires_at' => $result->expiresAt,
            ]);

            return [
                'outcome'      => 'backchannel_launch',
                'redirect_url' => $link->external_url,
                'token'        => null,
                'launch_code'  => $result->code,
                'auth_mode'    => 'backchannel_launch',
                'reason'       => null,
                'jti'          => null,
                'expires_at'   => $result->expiresAt,
            ];
        } catch (\Throwable $e) {
            $this->recordEvent($link, $user, 'failed', 'Back-channel code generation error', $ip, $userAgent);
            return $this->denied(
                'Back-channel launch could not be completed. Contact your administrator.'
            );
        }
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
        ?array $metadata = null,
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
            'metadata'        => $metadata,
        ]);
    }

    private function denied(string $reason): array
    {
        return [
            'outcome'      => 'denied',
            'redirect_url' => null,
            'token'        => null,
            'launch_code'  => null,
            'auth_mode'    => '',
            'reason'       => $reason,
            'jti'          => null,
            'expires_at'   => null,
        ];
    }

    /**
     * Returns a direct browser-safe launch URL (standalone/local/api_token only).
     * signed_launch is handled separately — the URL is constructed at launch time.
     */
    private function safeLaunchUrl(OrganizationModuleLink $link, array &$warnings): ?string
    {
        if ($link->status !== 'active') {
            $warnings[] = "Module is {$link->status} — launch unavailable.";
            return null;
        }

        // signed_launch: no static launch URL — token is generated on demand
        if ($link->isSignedLaunchMode()) {
            if (empty($link->external_url)) {
                $warnings[] = 'No launch endpoint configured for this signed launch link.';
            } elseif (empty(config('glasshouse_sso.signing_secret', ''))) {
                $warnings[] = 'Signed launch is not configured on this portal — contact your administrator.';
            }
            return null;
        }

        // backchannel_launch: no static launch URL — code is generated on demand
        if ($link->isBackChannelLaunchMode()) {
            if (empty($link->external_url)) {
                $warnings[] = 'No launch endpoint configured for this back-channel launch link.';
            } elseif (! config('glasshouse_sso.backchannel.enabled', false)) {
                $warnings[] = 'Back-channel SSO is not enabled on this portal — contact your administrator.';
            }
            return null;
        }

        // Stub SSO modes
        if (in_array($link->auth_mode, self::STUB_SSO_MODES, true)) {
            $warnings[] = ucfirst(str_replace('_', ' ', $link->auth_mode))
                . ' authentication is not yet implemented (Phase 9+).';
            return null;
        }

        if (empty($link->external_url)) {
            if ($link->auth_mode !== 'local') {
                $warnings[] = 'No launch URL configured for this module link.';
            }
            return null;
        }

        return $link->external_url;
    }

    /**
     * True when the module can be launched now (no setup required).
     * For signed_launch: requires external_url AND signing_secret.
     * For standalone: requires external_url.
     */
    private function canLaunch(OrganizationModuleLink $link): bool
    {
        if ($link->status !== 'active') {
            return false;
        }

        if ($link->isSignedLaunchMode()) {
            return ! empty($link->external_url)
                && ! empty(config('glasshouse_sso.signing_secret', ''));
        }

        if ($link->isBackChannelLaunchMode()) {
            return ! empty($link->external_url)
                && (bool) config('glasshouse_sso.backchannel.enabled', false);
        }

        if (in_array($link->auth_mode, self::STUB_SSO_MODES, true)) {
            return false;
        }

        // Safe modes: can launch if URL is present (or local which needs no URL)
        if ($link->auth_mode === 'local') {
            return true;
        }

        return ! empty($link->external_url);
    }

    private function isSetupRequired(OrganizationModuleLink $link): bool
    {
        if ($link->status !== 'active') {
            return true;
        }

        if ($link->isSignedLaunchMode()) {
            return empty($link->external_url)
                || empty(config('glasshouse_sso.signing_secret', ''));
        }

        if ($link->isBackChannelLaunchMode()) {
            return empty($link->external_url)
                || ! config('glasshouse_sso.backchannel.enabled', false);
        }

        if (in_array($link->auth_mode, self::STUB_SSO_MODES, true)) {
            return true;
        }

        if ($link->auth_mode !== 'local' && empty($link->external_url)) {
            return true;
        }

        return false;
    }
}
