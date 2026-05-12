<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\View\View;

class ModulesController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $modules = $this->buildModuleStatus();

        return view('admin.modules', compact('modules'));
    }

    private function buildModuleStatus(): array
    {
        $config  = config('glasshouse.modules', []);
        $modules = [];

        foreach ($config as $key => $module) {
            $status = 'unconfigured';

            if (! ($module['enabled'] ?? false)) {
                $status = 'disabled';
            } elseif (empty($module['base_url'])) {
                $status = 'unconfigured';
            } else {
                // Only do live check for GlassBilling since we have a client;
                // others are stubbed until Phase 4+.
                $status = match ($key) {
                    'glassbilling' => $this->billing->health()['status'],
                    default        => 'stub',
                };
            }

            $modules[$key] = array_merge($module, ['status' => $status]);
        }

        return $modules;
    }
}
