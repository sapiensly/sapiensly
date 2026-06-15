<?php

namespace App\Services\Runtime;

use App\Ai\ChatAgent;
use App\Events\Runtime\RuntimeAgentStreamChunk;
use App\Events\Runtime\RuntimeAgentStreamComplete;
use App\Events\Runtime\RuntimeAgentStreamError;
use App\Models\App;
use App\Models\RuntimeAgentConversation;
use App\Models\RuntimeAgentMessage;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;

/**
 * Runs the embedded runtime agent for a built app (builder power #3, read slice):
 * an end-user message → the model with the read tools auto-derived from the
 * manifest (RuntimeAgentToolset) → a streamed answer over the app's own data
 * (internal + connected, source-agnostic). Mirrors BuilderAiService's streaming,
 * minus the manifest-editing/propose loop — reads only.
 *
 * The write slice adds the propose-* tools and the approval gate; nothing here
 * mutates the system of record.
 */
class RuntimeAgentService
{
    private const MAX_HISTORY_MESSAGES = 30;

    public function __construct(
        private AppManifestService $manifestService,
        private RuntimeAgentToolset $toolset,
        private AiProviderService $providers,
        private AiDefaults $aiDefaults,
    ) {}

    public function startConversation(App $app, User $user): RuntimeAgentConversation
    {
        return RuntimeAgentConversation::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    /**
     * Stream one assistant turn into the placeholder message, broadcasting
     * deltas over Reverb. Invoked from RunRuntimeAgentJob. The user turn and the
     * streaming placeholder are already persisted by the controller.
     */
    public function streamMessage(RuntimeAgentMessage $placeholder, string $userText): RuntimeAgentMessage
    {
        set_time_limit(0);

        $conversation = $placeholder->conversation;
        $app = $conversation->app;
        $manifest = $this->manifestService->getActiveManifest($app);

        if ($manifest === null || ! ($manifest['agent']['enabled'] ?? false)) {
            return $this->fail($placeholder, 'This app has no active agent.');
        }

        $tools = $this->toolset->readTools($app, $manifest);

        $history = $this->buildHistory($conversation, $placeholder->id);
        $promptText = $this->popLastUserPrompt($history, $userText);

        $sdkAgent = new ChatAgent(
            instructions: $this->systemPrompt($app, $manifest),
            messages: $history,
            tools: $tools,
        );

        $startedAt = microtime(true);
        $buffer = '';

        try {
            $user = $conversation->user;
            if ($user !== null) {
                $this->providers->applyRuntimeConfig($user);
                $provider = $this->providers->resolveProvider($this->aiDefaults->model('chat'), $user);
            } else {
                $provider = Lab::Anthropic;
            }
            $resolvedModel = $this->aiDefaults->model('chat');

            $stream = $sdkAgent->stream($promptText, provider: $provider, model: $resolvedModel);

            $sawText = false;
            foreach ($stream as $event) {
                if ($event instanceof TextStart) {
                    $separator = ($sawText && $buffer !== '' && ! str_ends_with($buffer, "\n")) ? "\n\n" : '';
                    if ($separator !== '') {
                        $buffer .= $separator;
                        $this->safeBroadcast(fn () => RuntimeAgentStreamChunk::dispatch($conversation->id, $placeholder->id, $separator));
                    }

                    continue;
                }

                if ($event instanceof TextDelta && $event->delta !== '') {
                    $sawText = true;
                    $buffer .= $event->delta;
                    $this->safeBroadcast(fn () => RuntimeAgentStreamChunk::dispatch($conversation->id, $placeholder->id, $event->delta));
                }
            }

            $placeholder->update(['content' => $buffer, 'status' => 'none']);
            $this->safeBroadcast(fn () => RuntimeAgentStreamComplete::dispatch($placeholder->refresh()));

            return $placeholder;
        } catch (\Throwable $e) {
            Log::error('Runtime agent stream failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $placeholder->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'error' => $e->getMessage(),
            ]);

            return $this->fail($placeholder, 'Sorry — the request failed: '.mb_substr($e->getMessage(), 0, 1500));
        }
    }

    private function fail(RuntimeAgentMessage $placeholder, string $message): RuntimeAgentMessage
    {
        $placeholder->update(['content' => $message, 'status' => 'error']);
        $this->safeBroadcast(fn () => RuntimeAgentStreamError::dispatch(
            $placeholder->conversation_id,
            $placeholder->id,
            $message,
        ));

        return $placeholder;
    }

    /**
     * @return array<UserMessage|AssistantMessage>
     */
    private function buildHistory(RuntimeAgentConversation $conversation, string $excludeMessageId): array
    {
        $messages = $conversation->messages()
            ->where('id', '!=', $excludeMessageId)
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $sdk = [];
        foreach ($messages as $m) {
            $content = (string) ($m->content ?? '');
            if ($content === '') {
                continue;
            }
            $sdk[] = $m->role === 'user' ? new UserMessage($content) : new AssistantMessage($content);
        }

        return $sdk;
    }

    /**
     * Pop the trailing user turn off the history (the SDK takes it as the prompt
     * argument), falling back to the raw text if the tail isn't a user message.
     *
     * @param  array<UserMessage|AssistantMessage>  $history
     */
    private function popLastUserPrompt(array &$history, string $fallback): string
    {
        if (! empty($history)) {
            $tail = end($history);
            if ($tail instanceof UserMessage) {
                array_pop($history);

                return $tail->content ?? $fallback;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function systemPrompt(App $app, array $manifest): string
    {
        $agent = $manifest['agent'] ?? [];
        $name = $agent['name'] ?? 'Assistant';
        $instructions = trim((string) ($agent['instructions'] ?? ''));

        return <<<PROMPT
You are {$name}, the assistant embedded in the "{$app->name}" app. You help the people who use this app.

{$instructions}

How you work:
- Call `describe_capabilities` first to see what data you can read.
- Use `query_object` and `aggregate_object` to answer with REAL data from this app — never invent records or numbers.
- Some objects are connected to external systems; you read them the same way. A read may fail (the external system is down) — if a tool returns an error, say so plainly, don't fabricate.
- You can only READ in this version. If the user asks you to create, change, or delete something, say you can't do that yet.
- Reply in the same language as the user. Keep answers short and concrete.
PROMPT;
    }

    /**
     * Broadcasts go to Reverb over HTTP; a dead broadcaster must not crash the
     * job (the message is already persisted, a refresh recovers it).
     */
    private function safeBroadcast(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            static $lastWarn = 0;
            if (microtime(true) - $lastWarn > 1) {
                Log::warning('Runtime agent broadcast failed (continuing)', ['error' => $e->getMessage()]);
                $lastWarn = microtime(true);
            }
        }
    }
}
