<?php

namespace App\Services;

use App\Models\OrganizationModuleLink;

/**
 * Returns safe launch metadata for a module link.
 *
 * Security boundaries:
 * - Never exposes credentials, tokens, or session cookies.
 * - Launch URLs are only returned for auth modes that do not require
 *   server-side token exchange (local, standalone).
 * - SSO auth modes (shared_session, signed_launch, oauth) are reserved for
 *   future implementation. They return a null launch_url and a warning.
 * - api_token mode: the token lives server-side only; the launch URL may be
 *   returned but no token is included in the browser-facing response.
 */
class ModuleLaunchService
{
    /** Auth modes that may produce a browser-safe launch URL in Phase 6. */
    private const SAFE_LAUNCH_MODES = ['local', 'standalone', 'api_token'];

    /** Auth modes reserved for future SSO implementation. */
    private const FUTURE_SSO_MODES = ['shared_session', 'signed_launch', 'oauth'];

    /**
     * Build safe launch metadata for a single module link.
     *
     * @return array{
     *   module_key: string,
     *   display_name: string,
     *   status: string,
     *   auth_mode: string,
     *   launch_url: string|null,
     *   setup_required: bool,
     *   warnings: string[],
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
                ],
                $linked[$key] ?? []
            );
        }

        return $merged;
    }

    // -------------------------------------------------------------------------

    private function safeLaunchUrl(OrganizationModuleLink $link, array &$warnings): ?string
    {
        if ($link->status !== 'active') {
            $warnings[] = "Module is {$link->status} — launch unavailable.";
            return null;
        }

        if (in_array($link->auth_mode, self::FUTURE_SSO_MODES, true)) {
            $warnings[] = ucfirst(str_replace('_', ' ', $link->auth_mode))
                . ' authentication is not yet implemented (Phase 7+).';
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
