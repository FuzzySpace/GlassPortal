<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModuleLinksController extends Controller
{
    public function index(Request $request): View
    {
        $query = OrganizationModuleLink::with('organization')
            ->orderByDesc('updated_at');

        if ($request->filled('module_key')) {
            $query->where('module_key', $request->input('module_key'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $links = $query->paginate(25)->withQueryString();

        $moduleKeys = collect(config('glasshouse.launch_modules', []))
            ->keys()
            ->sort()
            ->values();

        return view('admin.module-links', [
            'links'      => $links,
            'moduleKeys' => $moduleKeys,
        ]);
    }

    public function create(): View
    {
        $organizations = Organization::orderBy('name')->get(['id', 'name']);
        $moduleKeys    = array_keys(config('glasshouse.launch_modules', []));
        sort($moduleKeys);

        return view('admin.module-link-create', [
            'organizations' => $organizations,
            'moduleKeys'    => $moduleKeys,
            'authModes'     => OrganizationModuleLink::AUTH_MODES,
            'statuses'      => OrganizationModuleLink::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id'     => 'required|exists:organizations,id',
            'module_key'          => 'required|string|max:64|in:' . implode(',', array_keys(config('glasshouse.launch_modules', []))),
            'display_name'        => 'required|string|max:255',
            'external_account_id' => 'nullable|string|max:255',
            'external_url'        => 'nullable|url|max:2048',
            'auth_mode'           => 'required|in:' . implode(',', OrganizationModuleLink::AUTH_MODES),
            'status'              => 'required|in:' . implode(',', OrganizationModuleLink::STATUSES),
            'metadata'            => 'nullable|json',
        ]);

        $validated['metadata'] = isset($validated['metadata'])
            ? json_decode($validated['metadata'], true)
            : null;

        OrganizationModuleLink::create($validated);

        return redirect()->route('admin.module-links')
            ->with('success', 'Module link created.');
    }

    public function edit(OrganizationModuleLink $moduleLink): View
    {
        $organizations = Organization::orderBy('name')->get(['id', 'name']);
        $moduleKeys    = array_keys(config('glasshouse.launch_modules', []));
        sort($moduleKeys);

        return view('admin.module-link-edit', [
            'link'          => $moduleLink,
            'organizations' => $organizations,
            'moduleKeys'    => $moduleKeys,
            'authModes'     => OrganizationModuleLink::AUTH_MODES,
            'statuses'      => OrganizationModuleLink::STATUSES,
        ]);
    }

    public function update(Request $request, OrganizationModuleLink $moduleLink): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id'     => 'required|exists:organizations,id',
            'module_key'          => 'required|string|max:64|in:' . implode(',', array_keys(config('glasshouse.launch_modules', []))),
            'display_name'        => 'required|string|max:255',
            'external_account_id' => 'nullable|string|max:255',
            'external_url'        => 'nullable|url|max:2048',
            'auth_mode'           => 'required|in:' . implode(',', OrganizationModuleLink::AUTH_MODES),
            'status'              => 'required|in:' . implode(',', OrganizationModuleLink::STATUSES),
            'metadata'            => 'nullable|json',
        ]);

        $validated['metadata'] = isset($validated['metadata'])
            ? json_decode($validated['metadata'], true)
            : null;

        $moduleLink->update($validated);

        return redirect()->route('admin.module-links')
            ->with('success', 'Module link updated.');
    }

    public function destroy(OrganizationModuleLink $moduleLink): RedirectResponse
    {
        // Soft-delete preserves the record and audit trail
        $moduleLink->delete();

        return redirect()->route('admin.module-links')
            ->with('success', 'Module link disabled.');
    }
}
