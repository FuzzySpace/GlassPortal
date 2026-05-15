<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\ModuleLaunchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ModulesController extends Controller
{
    public function __construct(private ModuleLaunchService $launcher) {}

    public function index(): View
    {
        $user = Auth::user();
        $org  = $user->organization;

        $modules = $org
            ? $this->launcher->mergeWithRegistry($org->moduleLinks()->get())
            : $this->launcher->mergeWithRegistry([]);

        return view('portal.modules', [
            'user'    => $user,
            'org'     => $org,
            'modules' => $modules,
        ]);
    }
}
