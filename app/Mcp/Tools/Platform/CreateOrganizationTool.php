<?php

namespace App\Mcp\Tools\Platform;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mcp\Tools\SysadminTool;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a new organization (a tenant) and give it an owner. Pass owner_email to seat an EXISTING account as its owner — the person must already have an account; this tool does not create users (use invite_platform_user first). The slug is derived from the name unless you pass one, and it is permanent in practice: it is the organization\'s MCP URL and the prefix of everything it publishes. Audited.')]
class CreateOrganizationTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'owner_email' => ['sometimes', 'nullable', 'string', 'email', 'max:320'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        if ($replay = $this->idempotentReplay($actor, $validated['idempotency_key'] ?? null)) {
            return Response::json($replay);
        }

        $slug = $validated['slug'] ?? Str::slug($validated['name']);

        if ($slug === '') {
            return Response::error('That name does not produce a usable slug — pass `slug` explicitly.');
        }

        if (Organization::slugIsReserved($slug)) {
            return Response::error("'{$slug}' is reserved by a platform route — an organization holding it would be unreachable over MCP. Pass a different `slug`.");
        }

        if (Organization::withTrashed()->where('slug', $slug)->exists()) {
            return Response::error("The slug '{$slug}' is already taken (slugs stay reserved after deletion). Pass a different one.");
        }

        $owner = null;
        if (! empty($validated['owner_email'])) {
            $owner = User::whereRaw('lower(email) = ?', [strtolower($validated['owner_email'])])->first();

            if ($owner === null) {
                return Response::error("No account with the address '{$validated['owner_email']}'. Create it with invite_platform_user first, then run this again.");
            }
        }

        $organization = Organization::create([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        if ($owner !== null) {
            OrganizationMembership::create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'role' => MembershipRole::Owner,
                'status' => MembershipStatus::Active,
            ]);
        }

        $this->audit(
            actor: $actor,
            summary: "Created organization '{$organization->name}'".($owner !== null ? " owned by {$owner->email}" : ' with no owner yet'),
            meta: ['slug' => $slug, 'owner_email' => $owner?->email],
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            targetLabel: $organization->name,
        );

        $payload = [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'mcp_url' => url("mcp/{$organization->slug}/v1"),
            ],
            'owner' => $owner === null ? null : ['user_id' => $owner->id, 'email' => $owner->email],
            'note' => $owner === null
                ? 'No owner seated. Nobody can administer this organization until you add one with manage_organization_membership.'
                : 'The owner keeps their existing active organization; they switch to this one from the app.',
        ];

        $this->rememberIdempotent($actor, $validated['idempotency_key'] ?? null, $payload);

        return Response::json($payload);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Display name of the organization.')->required(),
            'slug' => $schema->string()->description('URL slug (lowercase, hyphenated). Derived from the name when omitted.'),
            'owner_email' => $schema->string()->description('Existing account to seat as owner.'),
            'idempotency_key' => $schema->string()->description('Replay-safe key: an identical retry returns the first result instead of creating a second organization.'),
        ];
    }
}
