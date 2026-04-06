<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->with('organization:id,name')
            ->withCount('memberships')
            ->latest()
            ->paginate(20);

        return Inertia::render('admin/Users', [
            'users' => $users,
        ]);
    }
}
