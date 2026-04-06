<?php

namespace App\Policies;

use App\Models\Chatbot;
use App\Models\User;

class ChatbotPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('chatbots.view');
    }

    public function view(User $user, Chatbot $chatbot): bool
    {
        if (! $chatbot->isVisibleTo($user)) {
            return false;
        }

        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('chatbots.view');
    }

    public function create(User $user): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        return $user->hasPermissionTo('chatbots.create');
    }

    public function update(User $user, Chatbot $chatbot): bool
    {
        if (! $user->organization_id) {
            return $chatbot->isOwnedBy($user);
        }

        if (! $chatbot->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('chatbots.update') && $chatbot->isOwnedBy($user);
    }

    public function delete(User $user, Chatbot $chatbot): bool
    {
        if (! $user->organization_id) {
            return $chatbot->isOwnedBy($user);
        }

        if (! $chatbot->isVisibleTo($user)) {
            return false;
        }

        return $user->hasPermissionTo('chatbots.delete') && $chatbot->isOwnedBy($user);
    }
}
