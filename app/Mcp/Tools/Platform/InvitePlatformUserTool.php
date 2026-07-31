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
use Throwable;

#[Description('Create an account and email the person an invitation. They receive a verification link and set their own password — no password is ever set here or shown to you. Optionally seat them in an organization at the same time with organization + membership_role. The platform role defaults to member; sysadmin is deliberately NOT grantable here (grant it explicitly afterwards, so nobody becomes a super-admin as a side effect of an invite). Audited.')]
class InvitePlatformUserTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'in:owner,member'],
            'organization' => ['sometimes', 'nullable', 'string', 'max:120'],
            'membership_role' => ['sometimes', 'string', 'in:owner,member'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        if ($replay = $this->idempotentReplay($actor, $validated['idempotency_key'] ?? null)) {
            return Response::json($replay);
        }

        $email = strtolower(trim($validated['email']));

        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            return Response::error("An account with '{$email}' already exists. Inspect it with inspect_platform_user.");
        }

        $organization = null;
        if (! empty($validated['organization'])) {
            $organization = Organization::query()
                ->where('id', $validated['organization'])
                ->orWhere('slug', $validated['organization'])
                ->first();

            if ($organization === null) {
                return Response::error("No organization matches '{$validated['organization']}' — nothing was created.");
            }
        }

        $user = User::create([
            'name' => $validated['name'] ?? Str::before($email, '@'),
            'email' => $email,
            // Unusable by design: the invitee sets a real one through the
            // verification email. Nothing here knows a password.
            'password' => bcrypt(Str::random(64)),
            'organization_id' => $organization?->id,
        ]);

        $user->assignRole($validated['role'] ?? 'member');

        if ($organization !== null) {
            OrganizationMembership::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => MembershipRole::from($validated['membership_role'] ?? 'member'),
                'status' => MembershipStatus::Active,
            ]);
        }

        $mailed = true;
        $mailError = null;
        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            // The account exists either way; say so rather than implying the
            // person has been notified when the mailer refused.
            $mailed = false;
            $mailError = $e->getMessage();
        }

        $this->audit(
            actor: $actor,
            summary: "Invited {$user->email}".($organization !== null ? " into '{$organization->name}'" : ''),
            meta: [
                'role' => $validated['role'] ?? 'member',
                'organization' => $organization?->slug,
                'membership_role' => $organization !== null ? ($validated['membership_role'] ?? 'member') : null,
                'invitation_sent' => $mailed,
            ],
            organizationId: $organization?->id,
            targetType: 'user',
            targetId: (string) $user->id,
            targetLabel: $user->email,
        );

        $payload = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $validated['role'] ?? 'member',
            ],
            'organization' => $organization === null ? null : [
                'id' => $organization->id,
                'name' => $organization->name,
                'membership_role' => $validated['membership_role'] ?? 'member',
            ],
            'invitation_sent' => $mailed,
            'note' => $mailed
                ? 'Verification email sent. The account cannot sign in until they follow it and set a password.'
                : 'The account was created but the invitation email FAILED to send: '.$mailError.' Resend with manage_platform_user action=resend_verification.',
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
            'email' => $schema->string()->description('Address to invite.')->required(),
            'name' => $schema->string()->description('Display name. Defaults to the part before the @.'),
            'role' => $schema->string()->description('Platform role: owner | member. Default member.'),
            'organization' => $schema->string()->description('Seat them in this organization (id or slug) on creation.'),
            'membership_role' => $schema->string()->description('Their role inside that organization: owner | member. Default member.'),
            'idempotency_key' => $schema->string()->description('Replay-safe key: an identical retry returns the first result instead of inviting twice.'),
        ];
    }
}
