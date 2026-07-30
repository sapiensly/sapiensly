<?php

namespace App\Services\Apps;

use App\Models\App;
use App\Models\PortalUser;
use InvalidArgumentException;

/**
 * Who may sign in to a portal, from the organization's side.
 *
 * This exists because `signup: "invite"` is otherwise unusable: a portal user
 * is created on their first successful link request, and an invite-only portal
 * refuses to create one for an address nobody added. Without a way to add
 * addresses, invite mode accepts every request and mails nobody — a portal that
 * looks configured and lets no one in.
 *
 * Blocking is a status rather than a delete, so a person can be turned off
 * without losing the records their id scopes: deleting them would orphan every
 * row whose row_filter compares against it.
 */
class PortalUserDirectory
{
    /**
     * Add someone who may sign in. Idempotent by address — a second invite for
     * the same person re-opens a blocked account rather than failing or
     * silently doing nothing.
     */
    public function invite(App $app, string $email, ?string $name = null): PortalUser
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("'{$email}' is not an email address.");
        }

        $portalUser = PortalUser::query()
            ->where('app_id', $app->id)
            ->where('email', $email)
            ->first();

        if ($portalUser !== null) {
            $portalUser->forceFill([
                'name' => $name ?? $portalUser->name,
                // An invite is an act of admission; it undoes a block.
                'status' => $portalUser->status === 'blocked' ? 'invited' : $portalUser->status,
            ])->save();

            return $portalUser;
        }

        return PortalUser::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'email' => $email,
            'name' => $name,
            // Invited, not active: they become active by proving they can read
            // the address, which is the whole of what the portal verifies.
            'status' => 'invited',
        ]);
    }

    /**
     * Refuse someone. Their session stops resolving on the next request and no
     * link of theirs works again, but their records keep their owner.
     */
    public function block(App $app, string $email): ?PortalUser
    {
        return $this->setStatus($app, $email, 'blocked');
    }

    public function unblock(App $app, string $email): ?PortalUser
    {
        return $this->setStatus($app, $email, 'invited');
    }

    /**
     * Remove an identity outright. Deliberately separate from blocking, and the
     * caller should prefer blocking: anything scoped by this id becomes
     * unreachable the moment the row is gone.
     */
    public function remove(App $app, string $email): bool
    {
        $portalUser = $this->find($app, $email);
        if ($portalUser === null) {
            return false;
        }

        $portalUser->delete();

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(App $app, int $limit = 100): array
    {
        return PortalUser::query()
            ->where('app_id', $app->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (PortalUser $u): array => [
                'id' => $u->id,
                'email' => $u->email,
                'name' => $u->name,
                'status' => $u->status,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'invited_at' => $u->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function setStatus(App $app, string $email, string $status): ?PortalUser
    {
        $portalUser = $this->find($app, $email);
        $portalUser?->forceFill([
            'status' => $status,
            // A blocked person's pending link dies with them; leaving it live
            // would let a link mailed a minute ago outlive the decision.
            'login_token_hash' => null,
            'login_token_expires_at' => null,
        ])->save();

        return $portalUser;
    }

    private function find(App $app, string $email): ?PortalUser
    {
        return PortalUser::query()
            ->where('app_id', $app->id)
            ->where('email', strtolower(trim($email)))
            ->first();
    }
}
