<?php

namespace App\Jobs;

use App\Enums\DocumentType;
use App\Events\DocumentStreamChunk;
use App\Events\DocumentStreamComplete;
use App\Events\DocumentStreamError;
use App\Models\User;
use App\Services\AiProviderService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Streams an LLM generation or refinement for a document to the browser
 * via Reverb. The frontend subscribes to `documents.stream.{streamId}`
 * and accumulates DocumentStreamChunk deltas into the artifact body;
 * DocumentStreamComplete carries the authoritative final body and
 * DocumentStreamError surfaces failures.
 *
 * One job handles both modes (generate / refine) — refine passes prior
 * turns + the current HTML as context; generate starts from a clean
 * system prompt.
 */
class StreamDocumentLlmJob implements ShouldQueue
{
    use Queueable;

    // Generous — the whole point of streaming is to work past the
    // synchronous cURL timeout, but we still cap the job itself.
    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  'generate'|'refine'  $mode
     * @param  array<int, array{role: 'user'|'assistant', content: string}>  $history
     */
    public function __construct(
        public int $userId,
        public string $streamId,
        public string $mode,
        public string $type,
        public string $instruction,
        public ?string $currentBody = null,
        public array $history = [],
        public ?string $modelId = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(AiProviderService $aiProviderService): void
    {
        try {
            $user = User::find($this->userId);
            if (! $user) {
                throw new \RuntimeException('User not found for document stream.');
            }

            // `applyRuntimeConfig` registers every provider the user owns
            // in Laravel AI's config, so the Lab resolver can pick the
            // right credentials for whichever model the caller asked for.
            $aiProviderService->applyRuntimeConfig($user);

            $modelId = $this->modelId;
            if ($modelId === null || $modelId === '') {
                $default = $aiProviderService->getDefaultProvider($user);
                if (! $default) {
                    throw new \RuntimeException(__('No AI provider is configured. Configure one in System → AI providers.'));
                }

                $modelId = $default->getChatModels()[0]['id'] ?? null;
                if (! $modelId) {
                    throw new \RuntimeException(__('The configured AI provider has no chat models enabled.'));
                }
            }

            $type = DocumentType::tryFrom($this->type);
            if (! $type || ! $type->isInlineAuthorable()) {
                throw new \RuntimeException(__('Unsupported document type for AI generation.'));
            }

            $systemPrompt = $this->systemPromptFor($type);

            // Build the prior-turn history the SDK expects. For `refine`
            // we prepend an assistant turn carrying the current HTML so
            // the model has concrete state to reason about when the
            // user says "make the button blue".
            $messages = [];
            if ($this->mode === 'refine' && $this->currentBody !== null) {
                $messages[] = new AssistantMessage("Current HTML:\n\n".$this->currentBody);
            }
            foreach ($this->history as $turn) {
                $messages[] = $turn['role'] === 'assistant'
                    ? new AssistantMessage($turn['content'])
                    : new UserMessage($turn['content']);
            }

            $agent = new AnonymousAgent($systemPrompt, $messages, []);
            // Catalog-aware resolution: accepts any chat model in the
            // driver catalog as long as the user has a provider with
            // credentials for that driver — not just the models the
            // tenant toggled on in their stored provider config.
            $lab = $aiProviderService->resolveProviderForCatalogModel($modelId, $user)
                ?? $aiProviderService->resolveProvider($modelId, $user);

            $stream = $agent->stream(
                $this->instruction,
                provider: $lab,
                model: $modelId,
                // Even with streaming we set a generous ceiling — the SDK
                // cares about total elapsed time for the connection, not
                // per-chunk wait.
                timeout: 540,
            );

            $fullBody = '';
            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    $fullBody .= $event->delta;
                    $this->sendBroadcast(new DocumentStreamChunk($this->streamId, $event->delta));
                }
            }

            if ($fullBody === '') {
                throw new \RuntimeException(__('The AI returned an empty response. Try rephrasing.'));
            }

            $this->sendBroadcast(new DocumentStreamComplete($this->streamId));
        } catch (\Throwable $e) {
            Log::channel('daily')->error('documents.stream: job failed', [
                'user_id' => $this->userId,
                'stream_id' => $this->streamId,
                'mode' => $this->mode,
                'error' => $e->getMessage(),
            ]);

            $this->sendBroadcast(new DocumentStreamError(
                $this->streamId,
                $e->getMessage() ?: __('Generation failed.'),
            ));
        }
    }

    private function systemPromptFor(DocumentType $type): string
    {
        if ($this->mode === 'refine') {
            return 'You refine self-contained HTML artifacts iteratively. '
                .'Each turn, the user describes a change and you respond with the COMPLETE updated HTML document — doctype through </html>, nothing else. '
                .'No preamble, no closing remarks, no code fences. Preserve every part the user did not ask to change. '
                .'Inline any new CSS or JavaScript inside the document so it stays runnable in a sandboxed iframe.';
        }

        return match ($type) {
            DocumentType::Md => 'You write clean, well-structured Markdown documents. '
                .'Respond with the document content only — no preamble, no closing remarks, no code fences around the whole response. '
                .'Use standard Markdown (headings, lists, tables, fenced code blocks for code). Keep a consistent heading level hierarchy.',
            DocumentType::Artifact => 'You write self-contained HTML artifacts that render as a single page. '
                .'Respond with a complete HTML document starting with <!doctype html> and nothing else — no preamble, no code fences. '
                .'Inline all CSS and JavaScript inside the document. Keep it self-contained and runnable.',
            default => 'You write clean, focused plain-text documents. '
                .'Respond with the document body only — no preamble, no closing remarks, no formatting syntax.',
        };
    }

    private function sendBroadcast(object $event): void
    {
        $broadcaster = app(BroadcastManager::class);
        $attempts = 0;

        while ($attempts < 2) {
            $attempts++;
            try {
                $broadcaster->event($event);

                return;
            } catch (\Throwable $e) {
                // OpenSSL 3 flags Reverb's abrupt TLS close as an error
                // even though the POST already landed on the server, so
                // retry once before logging. Keeps transient handshake
                // hiccups from dropping stream chunks on the floor.
                if ($attempts >= 2) {
                    Log::channel('daily')->error('documents.stream: broadcast failed', [
                        'event' => get_class($event),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
