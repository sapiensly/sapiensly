<?php

namespace App\Services\Slides;

use App\Ai\BuilderAgent;
use App\Ai\Tools\Slides\EditSlidesTool;
use App\Events\Slides\SlideBuilderChunk;
use App\Events\Slides\SlideBuilderComplete;
use App\Events\Slides\SlideBuilderError;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiSpendGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\Error as StreamingError;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;

/**
 * Runs one Slide Builder chat turn: streams the assistant's reply over Reverb
 * (SlideBuilderChunk/Complete/Error on `slides.builder.{documentId}`) while the
 * edit_slides tool applies deck operations atomically. The transcript lives in
 * the deck document's metadata (`builder_chat`) — a deck's builder chat is a
 * single rolling thread, not a conversation catalog.
 */
class SlideBuilderService
{
    private const MAX_TRANSCRIPT_MESSAGES = 24;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are the Slide Builder — the editing assistant for ONE presentation (deck) the user has open next to this chat. Your job: make the deck excellent, applying changes DIRECTLY with the `edit_slides` tool. The user watches the preview refresh live after each successful call.

        Editing rules:
        - Act, don't ask. Feedback like "make slide 4 punchier" or "add a chart after the metrics" maps to a small `edit_slides` call (replace/insert/remove/move, 0-based indexes). Only ask when the request is genuinely ambiguous.
        - Small surgical ops. Never rebuild the whole deck for a wording change; replace the one slide.
        - You author slide MANIFESTS, never HTML. Layouts and fields: title {title, subtitle?, meta?}; section {title, kicker?}; bullets {title, bullets: 2-5, kicker?}; two_column {title, left/right: {heading, items: 1-4}}; big_number {value, label, kicker?, delta?, context?}; metrics {title?, items: 2-4 of {value, label, delta?, value_source?}}; chart {title, chart_type: bar|line|donut, labels: 2-12, series: [{name, data}] (donut: 1 series), takeaway?, data_source?}; quote {quote, attribution?, role?}; closing {title, subtitle?, bullets?: 1-3, cta?}. Every slide accepts notes? (speaker notes).
        - Copy budgets are enforced by the validator — tight, presentation-grade copy: one message per slide, short lines, no filler. If content doesn't fit, split the slide.
        - LIVE DATA: charts accept data_source {app_slug, object, group_by, aggregation: count|sum|avg|min|max, field?, bucket?} and metric items value_source {app_slug, object, aggregation, field?} — the platform re-aggregates the user's app records on every open. Prefer live bindings when the user mentions data that lives in one of their apps.
        - Narrative craft: decks read title → sections → evidence (chart/metrics/big_number) → closing. When asked to "improve the deck", tighten copy, strengthen takeaways, and fix flow — do not pad it.
        - Reply in the user's language, briefly: say WHAT you changed and why it's better, never dump slide JSON into the chat. If a tool call fails validation, fix the issue and retry before answering.
        PROMPT;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
        private readonly DeckEditor $editor,
        private readonly DeckDataResolver $resolver,
    ) {}

    public function runTurn(Document $deck, User $user, string $messageId, string $userText): void
    {
        set_time_limit(0);

        $manifest = json_decode((string) $deck->body, true);
        if (! is_array($manifest)) {
            $this->safeBroadcast(fn () => SlideBuilderError::dispatch($deck->id, $messageId, 'The deck manifest is corrupted.'));

            return;
        }

        $transcript = array_values((array) ($deck->metadata['builder_chat'] ?? []));
        $sdkHistory = [];
        foreach (array_slice($transcript, -self::MAX_TRANSCRIPT_MESSAGES) as $m) {
            if (! is_array($m) || ! is_string($m['content'] ?? null) || $m['content'] === '') {
                continue;
            }
            $sdkHistory[] = ($m['role'] ?? '') === 'user'
                ? new UserMessage($m['content'])
                : new AssistantMessage($m['content']);
        }

        $editTool = new EditSlidesTool($deck, $this->editor);

        $system = self::SYSTEM_PROMPT
            ."\n\n## The deck right now (0-based indexes)\n"
            .json_encode(['title' => $deck->name, ...$manifest], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $agent = new BuilderAgent(
            instructions: $system,
            messages: $sdkHistory,
            tools: [$editTool],
        );

        $resolvedModel = $this->aiDefaults->model('builder');
        $agent->forModel($resolvedModel);

        $buffer = '';

        try {
            $this->providers->applyRuntimeConfig($user);
            $provider = $this->providers->resolveProviderForCatalogModel($resolvedModel, $user) ?? Lab::Anthropic;

            app(AiSpendGuard::class)->assertWithinBudget($user, $user->organization_id, $resolvedModel);

            $stream = $agent->stream($userText, provider: $provider, model: $resolvedModel);

            foreach ($stream as $event) {
                if ($event instanceof StreamingError) {
                    throw new RuntimeException(
                        trim(($event->type ?? '').': '.($event->message ?? '')) ?: 'Provider returned a stream error.'
                    );
                }
                if ($event instanceof TextDelta && $event->delta !== '') {
                    $buffer .= $event->delta;
                    $this->safeBroadcast(fn () => SlideBuilderChunk::dispatch($deck->id, $messageId, $event->delta));
                }
            }

            app(AiUsageRecorder::class)->record(
                'builder', $resolvedModel, $user, $user->organization_id, $stream->usage ?? null,
            );

            $content = trim($buffer) !== '' ? $buffer : 'Done.';

            // Persist the rolling transcript on the deck itself.
            $transcript[] = ['role' => 'user', 'content' => $userText, 'at' => now()->toIso8601String()];
            $transcript[] = ['role' => 'assistant', 'content' => $content, 'at' => now()->toIso8601String()];
            $deck->refresh()->update([
                'metadata' => array_merge((array) $deck->metadata, [
                    'builder_chat' => array_slice($transcript, -self::MAX_TRANSCRIPT_MESSAGES),
                ]),
            ]);

            $applied = $editTool->appliedManifest;
            $this->safeBroadcast(fn () => SlideBuilderComplete::dispatch(
                $deck->id,
                $messageId,
                $content,
                $applied,
                $applied !== null ? $this->resolver->resolve($applied, $user) : null,
                $applied !== null ? (string) $applied['title'] : null,
            ));
        } catch (\Throwable $e) {
            Log::error('Slide Builder turn failed', [
                'document_id' => $deck->id,
                'error' => $e->getMessage(),
            ]);
            $this->safeBroadcast(fn () => SlideBuilderError::dispatch(
                $deck->id,
                $messageId,
                'The assistant could not complete this turn: '.$e->getMessage(),
            ));
        }
    }

    private function safeBroadcast(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            Log::warning('Slide Builder broadcast failed (continuing)', ['error' => $e->getMessage()]);
        }
    }
}
