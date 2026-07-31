<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Admin\UserDeletionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Throwable;

#[Description('Account lifecycle. Actions: block (refuse sign-in, keeps everything they own), unblock, reset_two_factor (clears their authenticator so they can enrol again — do this only after verifying identity out of band, it removes a second factor), resend_verification, delete (PERMANENT). Delete requires confirm_email set to the account\'s exact address; it reassigns everything the person owns to their organization\'s other owner, or deletes it outright when there is nobody to inherit — inspect_platform_user tells you which branch applies before you run it. You cannot act on your own account. Audited.')]
class ManagePlatformUserTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'user' => ['required', 'string', 'max:320'],
            'action' => ['required', 'string', 'in:block,unblock,reset_two_factor,resend_verification,delete'],
            'confirm_email' => ['sometimes', 'nullable', 'string', 'max:320'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $user = $this->resolveUser($validated['user']);

        if ($user === null) {
            return Response::error("No account matches '{$validated['user']}' (pass an email or a user id).");
        }

        $action = $validated['action'];

        if ($user->id === $actor->id) {
            return Response::error("You cannot {$action} your own account.");
        }

        return match ($action) {
            'block' => $this->block($user, $actor),
            'unblock' => $this->unblock($user, $actor),
            'reset_two_factor' => $this->resetTwoFactor($user, $actor),
            'resend_verification' => $this->resendVerification($user, $actor),
            'delete' => $this->delete($user, $actor, $validated['confirm_email'] ?? null),
        };
    }

    private function block(User $user, User $actor): Response
    {
        if ($user->isBlocked()) {
            return Response::error("{$user->email} is already blocked.");
        }

        $user->update(['blocked_at' => now()]);

        $this->audit(
            actor: $actor,
            summary: "Blocked {$user->email}",
            organizationId: $user->organization_id,
            targetType: 'user',
            targetId: (string) $user->id,
            targetLabel: $user->email,
        );

        return Response::json([
            'action' => 'block',
            'user' => ['id' => $user->id, 'email' => $user->email, 'status' => 'blocked'],
            'note' => 'They can no longer sign in. Everything they own is untouched, and their existing sessions are not revoked by this alone.',
        ]);
    }

    private function unblock(User $user, User $actor): Response
    {
        if (! $user->isBlocked()) {
            return Response::error("{$user->email} is not blocked.");
        }

        $user->update(['blocked_at' => null]);

        $this->audit(
            actor: $actor,
            summary: "Unblocked {$user->email}",
            organizationId: $user->organization_id,
            targetType: 'user',
            targetId: (string) $user->id,
            targetLabel: $user->email,
        );

        return Response::json([
            'action' => 'unblock',
            'user' => ['id' => $user->id, 'email' => $user->email, 'status' => 'active'],
        ]);
    }

    private function resetTwoFactor(User $user, User $actor): Response
    {
        $had = $user->two_factor_confirmed_at !== null;

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit(
            actor: $actor,
            summary: "Reset two-factor for {$user->email}",
            meta: ['had_two_factor' => $had],
            organizationId: $user->organization_id,
            targetType: 'user',
            targetId: (string) $user->id,
            targetLabel: $user->email,
        );

        return Response::json([
            'action' => 'reset_two_factor',
            'user' => ['id' => $user->id, 'email' => $user->email, 'two_factor' => false],
            'note' => $had
                ? 'Their authenticator no longer works and they sign in with a password alone until they enrol again.'
                : 'They had no confirmed second factor; nothing changed in practice.',
        ]);
    }

    private function resendVerification(User $user, User $actor): Response
    {
        if ($user->hasVerifiedEmail()) {
            return Response::error("{$user->email} is already verified.");
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            return Response::error('The verification email could not be sent: '.$e->getMessage());
        }

        $this->audit(
            actor: $actor,
            summary: "Resent verification to {$user->email}",
            organizationId: $user->organization_id,
            targetType: 'user',
            targetId: (string) $user->id,
            targetLabel: $user->email,
        );

        return Response::json([
            'action' => 'resend_verification',
            'user' => ['id' => $user->id, 'email' => $user->email],
            'note' => 'Verification email sent.',
        ]);
    }

    private function delete(User $user, User $actor, ?string $confirmEmail): Response
    {
        if ($confirmEmail === null || strtolower(trim($confirmEmail)) !== strtolower($user->email)) {
            return Response::error(
                "Deleting an account is permanent. Re-run with confirm_email set to exactly '{$user->email}'. "
                .'Run inspect_platform_user first to see what they own and whether they are the sole owner of an organization.'
            );
        }

        $email = $user->email;
        $id = $user->id;
        $organizationId = $user->organization_id;
        $memberships = OrganizationMembership::query()->where('user_id', $user->id)->count();

        try {
            $branch = app(UserDeletionService::class)->delete($user);
        } catch (Throwable $e) {
            $this->audit(
                actor: $actor,
                summary: "FAILED to delete {$email}",
                meta: ['error' => $e->getMessage()],
                organizationId: $organizationId,
                targetType: 'user',
                targetId: (string) $id,
                targetLabel: $email,
                result: PlatformAuditLog::RESULT_FAILED,
            );

            return Response::error('The account could not be deleted: '.$e->getMessage());
        }

        $this->audit(
            actor: $actor,
            summary: "Deleted {$email} (owned resources {$branch})",
            meta: ['branch' => $branch, 'memberships' => $memberships],
            organizationId: $organizationId,
            targetType: 'user',
            targetId: (string) $id,
            targetLabel: $email,
        );

        return Response::json([
            'action' => 'delete',
            'user' => ['id' => $id, 'email' => $email],
            'branch' => $branch,
            'note' => $branch === 'transferred'
                ? "Account deleted. Everything they owned was reassigned to their organization's owner."
                : 'Account deleted. They had no organization to inherit their work, so everything they owned was deleted with them.',
        ]);
    }

    private function resolveUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::whereRaw('lower(email) = ?', [strtolower($identifier)])->first();
        }

        return User::find($identifier);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user' => $schema->string()->description('Email address or user id.')->required(),
            'action' => $schema->string()->description('block | unblock | reset_two_factor | resend_verification | delete')->required(),
            'confirm_email' => $schema->string()->description("Required for delete: the account's exact address, as a deliberate second step."),
        ];
    }
}
