<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\Agent;
use App\Models\App;
use App\Models\Chatbot;
use App\Models\McpAccessToken;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Ai\AiUsageReport;
use App\Services\Platform\PlatformInventory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Everything about ONE organization, from the platform side: its members with roles and status, what it has built (apps, agents, chatbots, knowledge bases, records, documents), its AI spend for the period against whatever budget or platform cap applies, its SSO connection, its MCP tokens, and its Brandbook state. Accepts an organization id or slug. Read-only — this is the "who is this tenant and what are they doing" call before you change anything about them.')]
class InspectOrganizationTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'max:120'],
            'days' => ['sometimes', 'integer', 'in:7,30,90'],
        ]);

        $organization = $this->resolveOrganization($validated['organization']);

        if ($organization === null) {
            return Response::error("No organization matches '{$validated['organization']}' (pass its id or slug).");
        }

        $days = (int) ($validated['days'] ?? 30);
        $inventory = app(PlatformInventory::class);
        $spend = app(AiUsageReport::class)->forOrganization($organization->id, $days);
        $budget = $organization->aiBudget;

        $members = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,name,email,blocked_at,email_verified_at,two_factor_confirmed_at')
            ->get();

        return Response::json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'created_at' => $organization->created_at?->toIso8601String(),
                'deleted_at' => $organization->deleted_at?->toIso8601String(),
                'mcp_url' => url("mcp/{$organization->slug}/v1"),
            ],
            'members' => $members->map(fn (OrganizationMembership $membership) => [
                'user_id' => $membership->user_id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
                'role' => $membership->role?->value,
                'status' => $membership->status?->value,
                'blocked' => $membership->user?->blocked_at !== null,
                'verified' => $membership->user?->email_verified_at !== null,
                'two_factor' => $membership->user?->two_factor_confirmed_at !== null,
                'joined_at' => $membership->created_at?->toIso8601String(),
            ])->sortBy([['role', 'asc'], ['name', 'asc']])->values(),
            'built' => [
                'apps' => App::query()->where('organization_id', $organization->id)->count(),
                'agents' => Agent::query()->where('organization_id', $organization->id)->count(),
                'chatbots' => Chatbot::query()->where('organization_id', $organization->id)->count(),
                'knowledge_bases' => $inventory->tenantCount('knowledge_bases', $organization->id),
                'documents' => $inventory->tenantCount('documents', $organization->id),
                'records' => $inventory->tenantCount('records', $organization->id),
                'widget_conversations' => $inventory->tenantCount('widget_conversations', $organization->id),
            ],
            'spend' => [
                'range_days' => $days,
                'totals' => $spend['totals'] ?? null,
                'by_source' => $spend['by_source'] ?? null,
                'top_services' => array_slice($spend['by_service'] ?? [], 0, 5),
            ],
            'budget' => $budget === null ? null : [
                'system_monthly_budget' => $budget->system_monthly_budget,
                'own_monthly_budget' => $budget->own_monthly_budget,
                'platform_system_cap' => $budget->platform_system_cap,
                'alert_threshold_pct' => $budget->alert_threshold_pct,
                'reset_day' => $budget->reset_day,
                'enforcement_enabled' => (bool) $budget->enforcement_enabled,
                'effective_system_limit' => $budget->effectiveLimit('system'),
            ],
            'sso' => $organization->ssoConnection === null ? null : [
                'configured' => true,
                'id' => $organization->ssoConnection->id,
            ],
            'mcp_tokens' => McpAccessToken::query()
                ->where('organization_id', $organization->id)
                ->with('user:id,name,email')
                ->get()
                ->map(fn (McpAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'owner' => $token->user?->email,
                    'abilities' => $token->abilities ?: 'all tenant abilities',
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expired' => $token->isExpired(),
                ])->values(),
            'brand' => [
                'configured' => ! empty($organization->brand),
                'accent_color' => $organization->brandbook()->accentColor ?? null,
            ],
        ]);
    }

    private function resolveOrganization(string $identifier): ?Organization
    {
        return Organization::query()
            ->withTrashed()
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization id (org_…) or slug.')->required(),
            'days' => $schema->integer()->description('Spend window: 7, 30 or 90. Default 30.'),
        ];
    }
}
