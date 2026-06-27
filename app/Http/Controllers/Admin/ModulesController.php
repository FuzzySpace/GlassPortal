<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationModuleLink;
use App\Services\GlassBilling\GlassBillingClient;
use App\Services\Siona\SionaConnectorClient;
use App\Services\Sso\ModuleSecretResolver;
use Illuminate\View\View;

class ModulesController extends Controller
{
    public function __construct(
        private GlassBillingClient $billing,
        private SionaConnectorClient $sionaClient,
        private ModuleSecretResolver $secretResolver,
    ) {}

    public function index(): View
    {
        $modules       = $this->buildModuleStatus();
        $launchModules = config('glasshouse.launch_modules', []);
        $linkCounts    = $this->buildLinkCounts(array_keys($launchModules));
        $sionaHealth   = $this->sionaClient->health();
        $sionaSigning  = $this->sionaSigningStatus();

        return view('admin.modules', compact('modules', 'launchModules', 'linkCounts', 'sionaHealth', 'sionaSigning'));
    }

    /**
     * Safe signing-secret status for the SIONA admin panel.
     * Returns only a state + human label — never the secret value.
     *
     * @return array{state: string, label: string}
     */
    private function sionaSigningStatus(): array
    {
        if ($this->secretResolver->hasPerModuleSecret('siona')) {
            return ['state' => 'dedicated', 'label' => 'Dedicated SIONA signing secret configured'];
        }

        $hasFallback = $this->secretResolver->activeKeyInfo() !== null
            || (string) config('glasshouse_sso.signing_secret', '') !== '';

        return $hasFallback
            ? ['state' => 'fallback', 'label' => 'Using global fallback secret']
            : ['state' => 'missing', 'label' => 'Missing signing secret'];
    }

    private function buildModuleStatus(): array
    {
        $config  = config('glasshouse.modules', []);
        $modules = [];

        foreach ($config as $key => $module) {
            if (! ($module['enabled'] ?? false)) {
                $status = 'disabled';
            } elseif (empty($module['base_url'])) {
                $status = 'unconfigured';
            } else {
                $status = match ($key) {
                    'glassbilling' => $this->billing->health()['status'],
                    'siona'        => $this->sionaClient->health()['status'],
                    default        => 'stub',
                };
            }

            $modules[$key] = array_merge($module, ['status' => $status]);
        }

        return $modules;
    }

    private function buildLinkCounts(array $moduleKeys): array
    {
        $counts = [];
        foreach ($moduleKeys as $key) {
            $counts[$key] = [
                'total'  => OrganizationModuleLink::where('module_key', $key)->count(),
                'active' => OrganizationModuleLink::where('module_key', $key)->where('status', 'active')->count(),
            ];
        }
        return $counts;
    }
}
