<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                'organization' => $request->user()?->organization,
                'memberships' => $request->user()?->memberships()
                    ->where('status', MembershipStatus::Active)
                    ->with('organization:id,name,slug')
                    ->get()
                    ->map(fn ($m) => [
                        'organization_id' => $m->organization_id,
                        'organization_name' => $m->organization->name,
                        'role' => $m->role->value,
                    ]),
                'permissions' => $request->user()
                    ? $request->user()->getAllPermissions()->pluck('name')->values()
                    : [],
                'roles' => $request->user()
                    ? $request->user()->getRoleNames()->values()
                    : [],
            ],
            'impersonating' => $request->session()->has('impersonating_from'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'locale' => app()->getLocale(),
            'availableLocales' => ['en', 'es'],
        ];
    }
}
