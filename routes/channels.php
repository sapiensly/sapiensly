<?php

use App\Models\BuilderConversation;
use App\Models\Chat;
use App\Models\Conversation;
use App\Models\Debate;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

Broadcast::channel('conversation.{conversationId}', function ($user, string $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation && $conversation->user_id === $user->id;
});

// Builder AI chat stream. Each App-Builder conversation is private to the
// user who opened it.
Broadcast::channel('builder.conversation.{conversationId}', function ($user, string $conversationId) {
    $conv = BuilderConversation::find($conversationId);

    return $conv && $conv->user_id === $user->id;
});

// General Chat stream. Each chat is private to the user who owns it.
Broadcast::channel('chat.conversation.{chatId}', function ($user, string $chatId) {
    $chat = Chat::find($chatId);

    return $chat && $chat->user_id === $user->id;
});

// IA Debate stream. Each debate is private to the user who started it.
Broadcast::channel('debate.{debateId}', function ($user, string $debateId) {
    $debate = Debate::find($debateId);

    return $debate && $debate->user_id === $user->id;
});

Broadcast::channel('knowledge-base.{knowledgeBaseId}', function ($user, string $knowledgeBaseId) {
    $kb = KnowledgeBase::find($knowledgeBaseId);

    return $kb && $kb->isVisibleTo($user);
});

// Admin V2 dashboard — sysadmins only. Events (health snapshot changes, audit
// rows, stat recomputes) will be broadcast onto this channel by the dashboard
// step; for now only the authorization gate is registered.
Broadcast::channel('admin.dashboard', fn ($user) => $user?->hasRole('sysadmin') ?? false);

/*
 * Document generation / refinement LLM streams. Each stream has a
 * one-off ULID that the controller stores in cache alongside the user id
 * that owns it. Authorization here verifies the caller is the same user,
 * so another authenticated user can't snoop on an active stream by
 * guessing the id.
 */
Broadcast::channel('documents.stream.{streamId}', function ($user, string $streamId) {
    // Redis serializes ints as strings on roundtrip — compare as strings
    // so "1" (from Redis) matches int(1) (from the User model).
    $ownerId = Cache::get("document-stream:{$streamId}");

    return $ownerId !== null && (string) $ownerId === (string) $user->id;
});
