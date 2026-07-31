<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Every account on the platform, across all organizations. Search by name or email; filter by status (active, blocked, unverified, no_two_factor) or by organization; sort by newest, oldest or name. Each row carries the account\'s roles, verification and 2FA state, whether it is blocked, and its active organization. Read-only — this is the account inventory, not the per-organization roster (that is list_team_members).')]
class ListPlatformUsersTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'string', 'in:any,active,blocked,unverified,no_two_factor'],
            'organization' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'string', 'in:newest,oldest,name'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = User::query()->with(['organization:id,name,slug', 'roles:id,name']);

        if (! empty($validated['search'])) {
            $needle = '%'.strtolower($validated['search']).'%';
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(email) like ?', [$needle]);
            });
        }

        match ($validated['status'] ?? 'any') {
            'blocked' => $query->whereNotNull('blocked_at'),
            'unverified' => $query->whereNull('email_verified_at'),
            'no_two_factor' => $query->whereNull('two_factor_confirmed_at'),
            'active' => $query->whereNotNull('email_verified_at')->whereNull('blocked_at'),
            default => null,
        };

        if (! empty($validated['organization'])) {
            $organization = $validated['organization'];
            // Match the active-org pointer OR a membership, so someone who
            // belongs to the org but has another one active still shows up.
            $query->where(function ($inner) use ($organization) {
                $inner->where('organization_id', $organization)
                    ->orWhereHas('organization', fn ($o) => $o->where('slug', $organization))
                    ->orWhereHas('memberships.organization', fn ($o) => $o->where('id', $organization)->orWhere('slug', $organization));
            });
        }

        match ($validated['sort'] ?? 'newest') {
            'oldest' => $query->oldest(),
            'name' => $query->orderBy('name'),
            default => $query->latest(),
        };

        $total = (clone $query)->count();
        $users = $query->limit((int) ($validated['limit'] ?? 50))->get();

        return Response::json([
            'total_matching' => $total,
            'returned' => $users->count(),
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => match (true) {
                    $user->isBlocked() => 'blocked',
                    $user->email_verified_at === null => 'unverified',
                    default => 'active',
                },
                'roles' => $user->roles->pluck('name')->values(),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'active_organization' => $user->organization === null ? null : [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'slug' => $user->organization->slug,
                ],
                'created_at' => $user->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Match against name or email.'),
            'status' => $schema->string()->description('any | active | blocked | unverified | no_two_factor. Default any.'),
            'organization' => $schema->string()->description('Only accounts in this organization (id or slug).'),
            'sort' => $schema->string()->description('newest | oldest | name. Default newest.'),
            'limit' => $schema->integer()->description('How many to return, 1-200. Default 50.'),
        ];
    }
}
