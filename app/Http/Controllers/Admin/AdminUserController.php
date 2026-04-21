<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteUserRequest;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Models\User;
use App\Services\Admin\UserDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin v2 user management. Serves the new `/admin2/users` screen and the
 * actions wired into its UserDetailSheet (block/unblock, resend verification,
 * reset 2FA, destroy). The old `AdminUserController` stays live for the
 * legacy /admin/users pages until cutover.
 */
class AdminUserController extends Controller
{
    public function __construct(
        protected UserDeletionService $deletionService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->extractFilters($request);

        $query = User::query()
            ->with(['organization:id,name', 'roles:id,name']);

        if ($filters['q']) {
            $needle = '%'.strtolower($filters['q']).'%';
            // Use lower()/like rather than ilike so the query works on both
            // Postgres (prod) and SQLite (test suite in memory).
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(email) like ?', [$needle]);
            });
        }

        if ($filters['role'] && $filters['role'] !== 'any') {
            $role = $filters['role'];
            $query->whereHas('roles', fn ($r) => $r->where('name', $role));
        }

        if ($filters['status'] && $filters['status'] !== 'any') {
            match ($filters['status']) {
                'blocked' => $query->whereNotNull('blocked_at'),
                'unverified' => $query->whereNull('email_verified_at'),
                'active' => $query->whereNotNull('email_verified_at')
                    ->whereNull('blocked_at'),
                default => null,
            };
        }

        [$column, $direction] = $this->parseSort($filters['sort']);
        $query->orderBy($column, $direction);

        $paginator = $query->paginate(20)->appends($request->only([
            'q', 'role', 'status', 'sort',
        ]));

        return Inertia::render('admin/Users/Index', [
            'users' => [
                'data' => $paginator->getCollection()
                    ->map(fn (User $u) => $this->transform($u))
                    ->values(),
                'meta' => [
                    'total' => $paginator->total(),
                    'perPage' => $paginator->perPage(),
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                ],
            ],
            'summary' => [
                'accountsTotal' => User::query()->count(),
                'organizationsTotal' => User::query()
                    ->whereNotNull('organization_id')
                    ->distinct('organization_id')
                    ->count('organization_id'),
            ],
            'filters' => $filters,
        ]);
    }

    public function invite(InviteUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'] ?? Str::before($validated['email'], '@'),
            'email' => $validated['email'],
            // Unusable password — the invitee sets one via the verification
            // email's "finish setup" flow.
            'password' => bcrypt(Str::random(64)),
        ]);

        $user->assignRole($validated['role']);
        $user->sendEmailVerificationNotification();

        return back()->with('success', __('Invite sent to :email.', ['email' => $user->email]));
    }

    public function block(Request $request, User $user): RedirectResponse
    {
        if ($this->isSelf($request, $user)) {
            return back()->withErrors(['user' => __('You cannot block yourself.')]);
        }

        $user->update(['blocked_at' => now()]);

        return back()->with('success', __('User blocked.'));
    }

    public function unblock(Request $request, User $user): RedirectResponse
    {
        $user->update(['blocked_at' => null]);

        return back()->with('success', __('User unblocked.'));
    }

    public function resendVerification(User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return back()->withErrors(['user' => __('This user is already verified.')]);
        }

        $user->sendEmailVerificationNotification();

        return back()->with('success', __('Verification email sent.'));
    }

    public function resetTwoFactor(User $user): RedirectResponse
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with('success', __('Two-factor authentication reset.'));
    }

    public function destroy(DeleteUserRequest $request, User $user): RedirectResponse
    {
        if ($this->isSelf($request, $user)) {
            return back()->withErrors(['email_confirmation' => __('You cannot delete yourself.')]);
        }

        $branch = $this->deletionService->delete($user);

        $message = $branch === 'transferred'
            ? __('User deleted — resources reassigned to the organization owner.')
            : __('User deleted — owned resources removed.');

        return to_route('admin.users.index')->with('success', $message);
    }

    /**
     * Normalise a User model into the AdminUser shape the admin-v2 frontend
     * expects (lib/admin/types.ts).
     *
     * @return array<string, mixed>
     */
    protected function transform(User $user): array
    {
        $roleName = $user->roles->first()?->name ?? 'member';

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),
            'role' => $roleName,
            'status' => match (true) {
                $user->isBlocked() => 'blocked',
                $user->email_verified_at === null => 'unverified',
                default => 'active',
            },
            'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
            // last_seen_at isn't tracked yet — surface null so the UI shows
            // an em-dash until we add the column + middleware.
            'lastSeenAt' => null,
            'createdAt' => $user->created_at->toIso8601String(),
            'org' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
            ] : null,
        ];
    }

    /**
     * @return array{q: ?string, role: ?string, status: ?string, sort: ?string}
     */
    protected function extractFilters(Request $request): array
    {
        return [
            'q' => $request->string('q')->toString() ?: null,
            'role' => $request->string('role')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'sort' => $request->string('sort')->toString() ?: null,
        ];
    }

    /**
     * Map the frontend sort keys to (column, direction). Unknown values
     * fall back to "newest first" so a stale URL can't 500 the page.
     *
     * @return array{0: string, 1: 'asc' | 'desc'}
     */
    protected function parseSort(?string $sort): array
    {
        // last_seen_at doesn't exist yet — fall back to updated_at as a
        // rough activity proxy without breaking the public sort API.
        return match ($sort) {
            'name' => ['name', 'asc'],
            '-name' => ['name', 'desc'],
            'lastSeen' => ['updated_at', 'asc'],
            '-lastSeen' => ['updated_at', 'desc'],
            'createdAt' => ['created_at', 'asc'],
            '-createdAt' => ['created_at', 'desc'],
            default => ['created_at', 'desc'],
        };
    }

    protected function isSelf(Request $request, User $target): bool
    {
        return $request->user()?->id === $target->id;
    }
}
