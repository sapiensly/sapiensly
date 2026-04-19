<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\User;
use App\Models\WhatsAppConnection;

/**
 * Authorization for WhatsApp connections. Delegates the tenant check to the
 * parent Channel so visibility and permission semantics stay consistent across
 * channel types.
 */
class WhatsAppConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('whatsapp-connections.view');
    }

    public function view(User $user, WhatsAppConnection $connection): bool
    {
        $channel = $connection->channel;
        if (! $channel) {
            return false;
        }
        if (! $channel->isVisibleTo($user) && $channel->visibility !== Visibility::Global) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('whatsapp-connections.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('whatsapp-connections.create');
    }

    public function update(User $user, WhatsAppConnection $connection): bool
    {
        $channel = $connection->channel;
        if (! $channel) {
            return false;
        }

        if ($channel->visibility === Visibility::Global) {
            return $user->hasRole('sysadmin');
        }

        if (! $user->organization_id) {
            return $channel->isOwnedBy($user);
        }

        if (! $channel->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('whatsapp-connections.update') && $channel->isOwnedBy($user);
    }

    public function delete(User $user, WhatsAppConnection $connection): bool
    {
        return $this->update($user, $connection);
    }

    public function reply(User $user, WhatsAppConnection $connection): bool
    {
        $channel = $connection->channel;
        if (! $channel || ! $channel->isVisibleTo($user) && $channel->visibility !== Visibility::Global) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('whatsapp-connections.reply');
    }
}
