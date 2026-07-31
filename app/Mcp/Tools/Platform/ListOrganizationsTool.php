<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\App;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Platform\PlatformInventory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Every organization on the platform, with member and app counts, whether it has a spend cap or SSO configured, and when it was created. Search by name or slug, include soft-deleted ones, and sort by size or age. This is the tenant inventory — the counts here span organizations you are not a member of. Read-only; drill into one with inspect_organization.')]
class ListOrganizationsTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'include_deleted' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:newest,oldest,name,members,apps'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Organization::query()->withCount(['memberships']);

        if (! empty($validated['include_deleted'])) {
            $query->withTrashed();
        }

        if (! empty($validated['search'])) {
            $needle = '%'.strtolower($validated['search']).'%';
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(slug) like ?', [$needle]);
            });
        }

        match ($validated['sort'] ?? 'newest') {
            'oldest' => $query->oldest(),
            'name' => $query->orderBy('name'),
            'members' => $query->orderByDesc('memberships_count'),
            'apps' => $query->orderByDesc('created_at'),
            default => $query->latest(),
        };

        $total = (clone $query)->count();
        $organizations = $query->limit((int) ($validated['limit'] ?? 50))->get();

        $ids = $organizations->pluck('id')->all();

        $appCounts = App::query()
            ->whereIn('organization_id', $ids)
            ->selectRaw('organization_id, count(*) as aggregate')
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id');

        $activeMembers = OrganizationMembership::query()
            ->whereIn('organization_id', $ids)
            ->where('status', 'active')
            ->selectRaw('organization_id, count(*) as aggregate')
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id');

        $recordCounts = app(PlatformInventory::class)->tenantCountsByOrganization('records');

        $rows = $organizations->map(fn (Organization $org) => [
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'members' => (int) $org->memberships_count,
            'active_members' => (int) ($activeMembers[$org->id] ?? 0),
            'apps' => (int) ($appCounts[$org->id] ?? 0),
            'records' => (int) ($recordCounts[$org->id] ?? 0),
            'created_at' => $org->created_at?->toIso8601String(),
            'deleted_at' => $org->deleted_at?->toIso8601String(),
        ]);

        if (($validated['sort'] ?? null) === 'apps') {
            $rows = $rows->sortByDesc('apps')->values();
        }

        return Response::json([
            'total' => $total,
            'returned' => $rows->count(),
            'organizations' => $rows->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Match against name or slug.'),
            'include_deleted' => $schema->boolean()->description('Include soft-deleted organizations. Default false.'),
            'sort' => $schema->string()->description('newest | oldest | name | members | apps. Default newest.'),
            'limit' => $schema->integer()->description('How many to return, 1-200. Default 50.'),
        ];
    }
}
