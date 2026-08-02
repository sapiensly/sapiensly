<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ComponentCatalog;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin v2 UI Components — what each module can be built out of.
 *
 * Read-only, and deliberately derived: the manifest schema decides which app
 * blocks exist, the dashboard planner decides which of them a dashboard may
 * use, and the bot-flow reference decides what a conversation is made of. This
 * page reports those, so it can never advertise a component the platform would
 * refuse to render.
 */
class AdminComponentsController extends Controller
{
    public function __construct(
        private readonly ComponentCatalog $catalog,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Components', [
            'modules' => $this->catalog->all(),
        ]);
    }
}
