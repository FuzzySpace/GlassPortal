<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationModuleLink;
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

        $links = $query->paginate(25);

        $moduleKeys = collect(config('glasshouse.launch_modules', []))
            ->keys()
            ->sort()
            ->values();

        return view('admin.module-links', [
            'links'      => $links,
            'moduleKeys' => $moduleKeys,
        ]);
    }
}
