<?php

use App\Models\Conversation;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversationId}', function ($user, string $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation && $conversation->user_id === $user->id;
});

Broadcast::channel('knowledge-base.{knowledgeBaseId}', function ($user, string $knowledgeBaseId) {
    $kb = KnowledgeBase::find($knowledgeBaseId);

    return $kb && $kb->isVisibleTo($user);
});
