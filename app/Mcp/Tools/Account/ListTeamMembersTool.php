<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("List the members of the organization this connection is bound to, with each member's name, email, role (owner/member) and status (active/pending/inactive).")]
class ListTeamMembersTool extends SapiensTool
{
    // No ABILITY: the team roster is org-context, visible to any member.

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id === null) {
            return Response::error('This connection is not bound to an organization.');
        }

        $members = OrganizationMembership::query()
            ->where('organization_id', $user->organization_id)
            ->with('user:id,name,email')
            ->get();

        return Response::json([
            'members' => $members->map(fn (OrganizationMembership $m) => [
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role?->value,
                'status' => $m->status?->value,
                'joined_at' => $m->created_at?->toIso8601String(),
            ])
                ->sortBy([['role', 'asc'], ['name', 'asc']])
                ->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
