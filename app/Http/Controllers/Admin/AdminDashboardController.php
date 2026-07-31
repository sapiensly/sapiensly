<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformDashboard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin overview. Serves the `DashboardProps` contract in
 * `resources/js/lib/admin/types.ts` from real measurements —
 * {@see PlatformDashboard} explains what each number counts and which sections
 * return null when the platform records nothing for them.
 */
class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly PlatformDashboard $dashboard,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Dashboard', $this->dashboard->props());
    }
}
