<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationModuleLink;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\View\View;

class ModulesController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $modules       = $this->buildModuleStatus();
        $launchModules = config('glasshouse.launch_modules', []);
        $linkCounts    = $this->buildLinkCounts(array_keys($launchModules));

        return view('admin.modules', compact('modules', 'launchModules', 'linkCounts'));
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
