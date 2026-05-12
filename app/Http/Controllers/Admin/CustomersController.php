<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\View\View;

class CustomersController extends Controller
{
    public function index(): View
    {
        $organizations = Organization::withCount('users')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('admin.customers', compact('organizations'));
    }
}
