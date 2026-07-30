<?php

namespace App\Services\Notifications;

use App\Models\App;
use App\Models\AppUserRole;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * Turns the `to` list a workflow step declares into real destinations.
 *
 * Four forms, and no fifth: a plain address, `user:<id>`, `role:<app role
 * slug>`, and `owner`. Everything else is refused rather than guessed —
 * a notification sent to the wrong person is not a bug the recipient reports
 * back to you.
 *
 * A `user:` or `role:` reference is resolved WITHIN the app's organization. An
 * id from another tenant resolves to nothing, so an expression that happens to
 * carry a foreign id cannot address a stranger.
 */
class RecipientResolver
{
    /**
     * @param  list<string>  $references
     * @return array{recipients: list<NotificationRecipient>, unresolved: list<string>}
     */
    public function resolve(App $app, array $references): array
    {
        $recipients = [];
        $unresolved = [];

        foreach ($references as $reference) {
            $reference = trim((string) $reference);
            if ($reference === '') {
                continue;
            }

            $found = $this->resolveOne($app, $reference);
            if ($found === []) {
                $unresolved[] = $reference;

                continue;
            }

            foreach ($found as $recipient) {
                $recipients[$recipient->fingerprint()] = $recipient;
            }
        }

        return [
            'recipients' => array_values($recipients),
            'unresolved' => array_values(array_unique($unresolved)),
        ];
    }

    /**
     * @return list<NotificationRecipient>
     */
    private function resolveOne(App $app, string $reference): array
    {
        if ($reference === 'owner') {
            $owner = User::find($app->user_id);

            return $owner !== null
                ? [new NotificationRecipient($owner->email, $owner->id, $owner->name)]
                : [];
        }

        if (str_starts_with($reference, 'user:')) {
            $user = $this->memberOf($app, substr($reference, 5));

            return $user !== null
                ? [new NotificationRecipient($user->email, $user->id, $user->name)]
                : [];
        }

        if (str_starts_with($reference, 'role:')) {
            return $this->holdersOfRole($app, substr($reference, 5));
        }

        // A bare value must be a real address. An expression that resolved to
        // nothing arrives here as '' (already skipped) or as junk, and junk is
        // reported as unresolved instead of being handed to the mailer.
        return filter_var($reference, FILTER_VALIDATE_EMAIL) !== false
            ? [new NotificationRecipient($reference)]
            : [];
    }

    /**
     * A user id is only addressable if that user is in the app's organization —
     * the check that keeps a cross-tenant id from resolving to a real person.
     */
    private function memberOf(App $app, string $rawId): ?User
    {
        $id = (int) trim($rawId);
        if ($id <= 0) {
            return null;
        }

        $user = User::find($id);
        if ($user === null) {
            return null;
        }

        if ($app->organization_id === null) {
            // A personal app: only its owner is addressable.
            return $user->id === $app->user_id ? $user : null;
        }

        $isMember = OrganizationMembership::query()
            ->where('organization_id', $app->organization_id)
            ->where('user_id', $user->id)
            ->exists();

        return $isMember ? $user : null;
    }

    /**
     * Everyone holding an app role. Roles are granted per (app, user) in the
     * Access panel, so this addresses the people explicitly given that role —
     * not every member who merely inherits the default one.
     *
     * @return list<NotificationRecipient>
     */
    private function holdersOfRole(App $app, string $slug): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return [];
        }

        $userIds = AppUserRole::query()
            ->where('app_id', $app->id)
            ->where('role_slug', $slug)
            ->pluck('assigned_user_id')
            ->all();

        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (User $u): NotificationRecipient => new NotificationRecipient($u->email, $u->id, $u->name))
            ->values()
            ->all();
    }
}
