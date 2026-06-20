<?php

namespace App\Mcp\Tools\Account;

use App\Enums\MembershipStatus;
use App\Mcp\Tools\SapiensTool;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Identity & context: who you are acting as (user + role) and the organization this connection is bound to (name, members, AI limits). Call this first to ground yourself.')]
class WhoamiTool extends SapiensTool
{
    // No ABILITY: identity is available to any valid token.

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $org = $user->organization_id !== null
            ? Organization::find($user->organization_id)
            : null;

        $membership = $org !== null
            ? OrganizationMembership::query()
                ->where('organization_id', $org->id)
                ->where('user_id', $user->id)
                ->first()
            : null;

        return Response::json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'role' => $membership?->role?->value,
                'is_owner' => $membership?->role?->value === 'owner',
            ],
            'organization' => $org === null ? null : [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'member_count' => OrganizationMembership::query()
                    ->where('organization_id', $org->id)
                    ->where('status', MembershipStatus::Active)
                    ->count(),
                'created_at' => $org->created_at?->toIso8601String(),
                'ai_limits' => $this->aiLimits($org),
            ],
        ]);
    }

    /**
     * The org's AI spend limits — the closest thing to a "plan/tier" today.
     *
     * @return array<string, mixed>|null
     */
    private function aiLimits(Organization $org): ?array
    {
        $budget = $org->aiBudget;
        if ($budget === null) {
            return null;
        }

        return [
            'system_monthly_budget' => $budget->system_monthly_budget,
            'own_monthly_budget' => $budget->own_monthly_budget,
            'enforcement_enabled' => $budget->enforcement_enabled,
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
