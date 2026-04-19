<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return true; // Channels are viewable by any org member; mutations are scoped below.
    }

    public function view(User $user, Channel $channel): bool
    {
        if (! $channel->isVisibleTo($user) && $channel->visibility !== Visibility::Global) {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Channel $channel): bool
    {
        if ($channel->visibility === Visibility::Global) {
            return $user->hasRole('sysadmin');
        }

        if (! $user->organization_id) {
            return $channel->isOwnedBy($user);
        }

        return $channel->isVisibleTo($user) && $channel->isOwnedBy($user);
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $this->update($user, $channel);
    }
}
