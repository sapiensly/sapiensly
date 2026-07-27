<?php

namespace App\Services\Landing;

use App\Enums\ChatbotStatus;
use App\Models\App;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;

/**
 * Resolves the chatbot a landing carries into what the page needs to mount it.
 *
 * The manifest binds a chatbot BY ID; the credential the widget runs on never
 * appears there. A manifest is versioned, diffed, exported and read by the
 * builder model — a bearer token in it would live in all of those. So the token
 * is minted/read here, at render time, and only ever reaches the rendered page.
 *
 * Every check fails to null, never to an exception: a chatbot that was paused
 * or deleted after the landing was published must leave a working page minus a
 * bubble. A broken marketing page is a worse outcome than a missing widget.
 */
class LandingChatbot
{
    /**
     * What the public page needs to mount the bubble, or null when this landing
     * carries no usable chatbot.
     *
     * @return array{chatbot_id: string, token: string, position: string, greeting: string|null, appearance: array<string, mixed>}|null
     */
    public function forApp(App $app, ?array $manifest = null): ?array
    {
        $binding = $manifest['settings']['chatbot'] ?? null;
        $chatbotId = is_array($binding) ? (string) ($binding['id'] ?? '') : (string) ($app->chatbot_id ?? '');

        if ($chatbotId === '') {
            return null;
        }

        $chatbot = Chatbot::query()->find($chatbotId);

        // The ownership rail again, at render. The validator already ran it at
        // save time; this is the copy that matters if a manifest ever reaches
        // storage by another path, because the widget API binds the CHATBOT's
        // tenant scope — serving someone else's bot here would run a stranger's
        // conversation against their data from our page.
        if ($chatbot === null || ! $this->belongsToSameOwner($chatbot, $app)) {
            return null;
        }

        if ($chatbot->status !== ChatbotStatus::Active) {
            return null;
        }

        return [
            'chatbot_id' => $chatbot->id,
            'token' => $this->tokenFor($chatbot)->token,
            'position' => in_array($binding['position'] ?? null, ['left', 'right'], true)
                ? $binding['position']
                : 'right',
            'greeting' => is_string($binding['greeting'] ?? null) && trim($binding['greeting']) !== ''
                ? trim($binding['greeting'])
                : null,
            'appearance' => $chatbot->getAppearanceConfig(),
        ];
    }

    /**
     * An organization's landing may only serve that organization's bots; a
     * personal app, only its own user's.
     */
    private function belongsToSameOwner(Chatbot $chatbot, App $app): bool
    {
        return $app->organization_id !== null
            ? $chatbot->organization_id === $app->organization_id
            : $chatbot->organization_id === null && $chatbot->user_id === $app->user_id;
    }

    /**
     * The chatbot's embed token, created on first use — the same one the copy-
     * paste embed page hands out, so a landing and an external site share one
     * credential per bot rather than accumulating one per surface.
     */
    private function tokenFor(Chatbot $chatbot): ChatbotApiToken
    {
        return $chatbot->apiTokens()->first() ?? ChatbotApiToken::create([
            'chatbot_id' => $chatbot->id,
            'name' => 'Default Token',
            'token' => ChatbotApiToken::generateToken(),
            'abilities' => ['chat', 'feedback'],
        ]);
    }
}
