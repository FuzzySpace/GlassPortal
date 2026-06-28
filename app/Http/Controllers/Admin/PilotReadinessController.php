<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pilot\PilotReadinessService;
use Illuminate\View\View;

/**
 * Read-only pilot/product-test readiness dashboard (Phase 29).
 *
 * Owner/admin only (stacked `role:owner,admin` on the route). Summarises whether
 * the system is ready for a controlled pilot across the readiness categories and
 * links the operator to the relevant admin areas. It executes nothing and never
 * renders secrets — the service returns presence booleans / counts only.
 */
class PilotReadinessController extends Controller
{
    public function __construct(private PilotReadinessService $readiness) {}

    public function index(): View
    {
        return view('admin.pilot-readiness', [
            'categories' => $this->readiness->categories(),
            'summary'    => $this->readiness->summary(),
            'isReady'    => $this->readiness->isReady(),
        ]);
    }
}
