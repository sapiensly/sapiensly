<?php

namespace App\Policies;

use App\Models\Chatbot;
use App\Models\User;

class ChatbotPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Chatbot $chatbot): bool
    {
        return $chatbot->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Chatbot $chatbot): bool
    {
        return $chatbot->isOwnedBy($user);
    }

    public function delete(User $user, Chatbot $chatbot): bool
    {
        return $chatbot->isOwnedBy($user);
    }
}
