<?php

namespace App\Services\Chat;

use App\Ai\ChatAgent;
use App\Ai\Tools\DynamicTool;
use App\Ai\Tools\McpServerTool;
use App\Ai\Tools\RuntimeToolFactory;
use App\Enums\ToolType;
use App\Events\Chat\ChatStreamChunk;
use App\Events\Chat\ChatStreamComplete;
use App\Events\Chat\ChatStreamError;
use App\Events\Chat\ChatToolCall;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Tool;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\RetrievalService;
use App\Services\ToolConfigService;
use App\Services\ToolExecutionService;
use App\Services\Tools\McpClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;

/**
 * Streams a chat turn from the configured LLM provider, broadcasting each
 * token over Reverb. Mirrors BuilderAiService but is generic (no manifest /
 * tool-calling concerns) and resolves the model per-message so the user can
 * switch models mid-conversation.
 */
class ChatAiService
{
    /** Cache key prefix for the cooperative stop flag the worker polls. */
    public const STOP_CACHE_PREFIX = 'chat-stop:';

    private const MAX_HISTORY_MESSAGES = 30;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a helpful, knowledgeable AI assistant in a chat application. Answer clearly and concisely, and use Markdown (headings, lists, tables, fenced code blocks) when it improves readability. Match the language of the user.

        Rely only on the messages actually present in this conversation. Never invent or assume earlier exchanges, and never claim the user said something they did not (for example, do not assert they "confused you with" another assistant). If there is no prior context, simply answer the current message.
        PROMPT;

    private const ARTIFACTS_INSTRUCTIONS = <<<'PROMPT'
        ## Artifacts
        When you produce a substantial, self-contained deliverable the user will likely keep, reuse, edit, or run — a full code file or program, a complete HTML page, an SVG, or a long document (roughly 15+ lines) — wrap ONLY that deliverable in an artifact tag and keep your conversational reply outside it:

        <artifact title="Short title" type="code|html|markdown|svg" language="python">
        ...the full content...
        </artifact>

        Rules: use type="html" for standalone web pages, type="svg" for SVG, type="markdown" for long prose/documents, otherwise type="code" with the appropriate language. Put the complete, runnable content inside — never truncate or use placeholders like "rest unchanged". Use at most one artifact per reply unless the user asks for several. Do NOT wrap short snippets, quick examples, or inline answers in an artifact — keep those as normal Markdown. Briefly introduce the artifact in your reply text.
        PROMPT;

    public function __construct(
        private readonly AiProviderService $providers,
        private readonly AiDefaults $aiDefaults,
    ) {}

    public function streamMessage(ChatMessage $placeholder, string $userText, ?string $modelOverride = null, bool $webSearch = false, array $toolIds = []): ChatMessage
    {
        set_time_limit(0);

        $chat = $placeholder->chat;
        $user = $chat->user;

        // History excludes the placeholder we're about to fill and any other
        // non-complete rows. We want the most recent N turns in chronological
        // order: reorder() clears the relation's default created_at ASC sort
        // (otherwise orderByDesc would only append a no-op tiebreaker, leaving
        // the query ascending and selecting the OLDEST N), then we take the
        // newest N and reverse back to chronological order.
        $history = $chat->messages()
            ->where('id', '!=', $placeholder->id)
            ->where('status', 'complete')
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $sdkHistory = [];
        foreach ($history as $m) {
            $content = (string) ($m->content ?? '');
            if ($content === '') {
                continue;
            }
            $sdkHistory[] = $m->role === 'user'
                ? new UserMessage($content)
                : new AssistantMessage($content);
        }

        // Drop the last user turn — stream() takes it as a separate argument.
        $promptText = $userText;
        if (! empty($sdkHistory)) {
            $tail = end($sdkHistory);
            if ($tail instanceof UserMessage) {
                $promptText = $tail->content ?? $userText;
                array_pop($sdkHistory);
            }
        }

        // A selected agent (chat.agent_id) runs the turn as that agent: its
        // model, prompt, knowledge bases and tools. Otherwise it's a plain model
        // chat using the project's instructions/KBs and the composer's tools.
        $agent = $chat->agent_id !== null ? Agent::find($chat->agent_id) : null;
        if ($agent !== null && ($user === null || ! $agent->isVisibleTo($user))) {
            $agent = null;
        }

        if ($agent !== null) {
            $instructions = trim((string) $agent->prompt_template) !== ''
                ? (string) $agent->prompt_template
                : self::SYSTEM_PROMPT;
            $resolvedModel = $this->aiDefaults->model('chat', $agent->model ?: ($modelOverride ?? $chat->model));
            $ragKbIds = $agent->knowledgeBases()->pluck('knowledge_bases.id')->all();
            $toolIds = $agent->tools()->where('status', 'active')->pluck('tools.id')->all();
        } else {
            $instructions = self::SYSTEM_PROMPT;
            if ($chat->project?->custom_instructions) {
                $instructions .= "\n\n## Project instructions\n".$chat->project->custom_instructions;
            }
            $resolvedModel = $this->aiDefaults->model('chat', $modelOverride ?? $placeholder->model ?? $chat->model);
            $ragKbIds = $chat->project
                ? $chat->project->knowledgeBases()->pluck('knowledge_bases.id')->all()
                : [];
        }

        $instructions .= "\n\n".self::ARTIFACTS_INSTRUCTIONS;

        $startedAt = microtime(true);
        $buffer = '';

        try {
            $provider = Lab::Anthropic;
            if ($user !== null) {
                $this->providers->applyRuntimeConfig($user);
                $provider = $this->providers->resolveProviderForCatalogModel($resolvedModel, $user) ?? Lab::Anthropic;
            }

            // Knowledge (RAG): retrieve relevant chunks (from the agent's or the
            // project's knowledge bases) and fold them into the instructions.
            // Best-effort — never let a retrieval hiccup break the chat.
            $instructions .= $this->retrieveContext($ragKbIds, $promptText, $chat->id);

            $tools = $this->buildChatTools($toolIds, $user, $webSearch);

            $sdkAgent = new ChatAgent(
                instructions: $instructions,
                messages: $sdkHistory,
                tools: $tools,
            );

            $attachments = $this->buildAttachments($placeholder);

            $placeholder->update(['status' => 'streaming', 'model' => $resolvedModel]);

            Log::info('Chat AI streaming', [
                'chat_id' => $chat->id,
                'message_id' => $placeholder->id,
                'model' => $resolvedModel,
                'attachments' => count($attachments),
            ]);

            $stream = $sdkAgent->stream(
                $promptText,
                attachments: $attachments,
                provider: $provider,
                model: $resolvedModel,
            );

            $stopKey = self::STOP_CACHE_PREFIX.$placeholder->id;
            $sawText = false;
            $deltaCount = 0;
            foreach ($stream as $event) {
                // Cooperative cancellation: the Stop button sets a cache flag the
                // worker polls every few deltas, then breaks and finalizes the
                // partial reply. Polling (not every token) keeps cache load low.
                if (($deltaCount % 16) === 0 && Cache::get($stopKey)) {
                    break;
                }

                if ($event instanceof ToolCall) {
                    $this->safeBroadcast(fn () => ChatToolCall::dispatch($chat->id, $placeholder->id, $event->toolCall->name));

                    continue;
                }

                if ($event instanceof TextStart) {
                    $separator = self::blockSeparator($buffer, $sawText);
                    if ($separator !== '') {
                        $buffer .= $separator;
                        $this->safeBroadcast(fn () => ChatStreamChunk::dispatch($chat->id, $placeholder->id, $separator));
                    }

                    continue;
                }

                if ($event instanceof TextDelta && $event->delta !== '') {
                    $sawText = true;
                    $deltaCount++;
                    $buffer .= $event->delta;
                    $this->safeBroadcast(fn () => ChatStreamChunk::dispatch($chat->id, $placeholder->id, $event->delta));
                }
            }
            Cache::forget($stopKey);

            $buffer = self::closeDanglingArtifacts($buffer);

            $placeholder->update([
                'content' => $buffer,
                'model' => $resolvedModel,
                'status' => 'complete',
            ]);

            $chat->forceFill([
                'last_message_at' => now(),
                'model' => $resolvedModel,
            ]);
            if ($chat->title === null) {
                $chat->title = $this->generateTitle($promptText, $provider);
            }
            $chat->save();

            $this->safeBroadcast(fn () => ChatStreamComplete::dispatch($placeholder->refresh()));

            Log::info('Chat AI stream finished', [
                'chat_id' => $chat->id,
                'message_id' => $placeholder->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'response_length' => strlen($buffer),
            ]);

            return $placeholder;
        } catch (\Throwable $e) {
            $providerError = null;
            if ($e instanceof RequestException && $e->response !== null) {
                $body = json_decode($e->response->body(), true);
                $providerError = $body['error']['message'] ?? mb_substr($e->response->body(), 0, 500);
            }

            $errMsg = mb_substr($providerError ?? $e->getMessage(), 0, 1500);

            Log::error('Chat AI stream failed', [
                'chat_id' => $chat->id,
                'message_id' => $placeholder->id,
                'error_class' => $e::class,
                'error' => $e->getMessage(),
                'provider_error' => $providerError,
                'model' => $resolvedModel,
            ]);

            $placeholder->update([
                'content' => $buffer !== '' ? $buffer : null,
                'status' => 'error',
                'error' => $errMsg,
            ]);

            $this->safeBroadcast(fn () => ChatStreamError::dispatch($chat->id, $placeholder->id, $errMsg));

            return $placeholder;
        }
    }

    /**
     * Build the SDK tools the agent may call this turn: the user's selected
     * REST/GraphQL/Database tools (via ToolBuilderService) and MCP server tools
     * (expanded from each MCP tool's cached tool list), plus native web search.
     *
     * @param  array<int, string>  $toolIds
     * @return array<int, object>
     */
    private function buildChatTools(array $toolIds, ?User $user, bool $webSearch): array
    {
        $tools = [];
        if ($webSearch) {
            $tools[] = new WebSearch;
        }

        if (empty($toolIds) || $user === null) {
            return $tools;
        }

        $dbTools = Tool::query()
            ->forAccountContext($user)
            ->whereIn('id', $toolIds)
            ->where('status', 'active')
            ->get();

        // Each tool is wrapped in a uniquely class-named RuntimeTool because the
        // SDK names tools by class basename; `$seen` dedupes by final name so the
        // provider never receives two tools with the same name.
        $seen = [];
        $add = function (string $name, ToolContract $inner) use (&$tools, &$seen): void {
            $final = RuntimeToolFactory::toolName($name);
            if (isset($seen[$final])) {
                return;
            }
            $seen[$final] = true;
            $tools[] = RuntimeToolFactory::named($name, $inner);
        };

        $executionService = app(ToolExecutionService::class);
        foreach ($dbTools->whereIn('type', [ToolType::RestApi, ToolType::Graphql, ToolType::Database]) as $dbTool) {
            $add($dbTool->name, new DynamicTool($dbTool, $executionService));
        }

        // MCP tools — expand each server's cached tool list into callable tools.
        $configService = app(ToolConfigService::class);
        $mcpClient = app(McpClient::class);
        foreach ($dbTools->where('type', ToolType::Mcp) as $mcpTool) {
            $config = $configService->decryptConfig($mcpTool->type, $mcpTool->config ?? []);
            foreach (($config['mcp_tools'] ?? []) as $definition) {
                $name = $definition['name'] ?? null;
                if (! is_array($definition) || ! is_string($name) || $name === '') {
                    continue;
                }
                $add($name, new McpServerTool(
                    [
                        'name' => $name,
                        'description' => (string) ($definition['description'] ?? ''),
                        'input_schema' => is_array($definition['input_schema'] ?? null) ? $definition['input_schema'] : [],
                    ],
                    $config,
                    $user,
                    $mcpClient,
                ));
            }
        }

        return $tools;
    }

    /**
     * Retrieve relevant context from the given knowledge bases (the agent's or
     * the project's) and format it as an appendable instructions block. Returns
     * '' when there are no KBs, nothing matches, or retrieval fails.
     *
     * @param  array<int, string>  $kbIds
     */
    private function retrieveContext(array $kbIds, string $query, string $chatId): string
    {
        if (empty($kbIds) || trim($query) === '') {
            return '';
        }

        try {
            $result = app(RetrievalService::class)->retrieve($query, $kbIds, topK: 6, threshold: 0.5);

            if (($result['chunk_count'] ?? 0) === 0 || trim($result['context'] ?? '') === '') {
                return '';
            }

            return "\n\n## Relevant context from the knowledge base\n"
                .'Use the following retrieved information when it helps answer the user. '
                ."If it doesn't contain the answer, rely on your own knowledge.\n\n"
                .$result['context'];
        } catch (\Throwable $e) {
            Log::warning('Chat AI: RAG retrieval failed (continuing without context)', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Build vision/document/audio inputs from the attachments linked to the
     * preceding user turn. Stored* read from any disk (S3, fakes).
     *
     * @return array<int, StoredImage|StoredDocument|StoredAudio>
     */
    private function buildAttachments(ChatMessage $placeholder): array
    {
        $userMessage = $placeholder->chat->messages()
            ->where('role', 'user')
            ->where('id', '!=', $placeholder->id)
            ->orderByDesc('created_at')
            ->first();

        if ($userMessage === null) {
            return [];
        }

        $attachments = [];
        foreach ($userMessage->attachments as $att) {
            $disk = Storage::disk($att->disk);
            if (! $disk->exists($att->storage_path)) {
                Log::warning('Chat AI: attachment missing on disk', [
                    'disk' => $att->disk,
                    'path' => $att->storage_path,
                ]);

                continue;
            }

            $attachments[] = match (true) {
                $att->isImage() => new StoredImage($att->storage_path, $att->disk),
                $att->isAudio() => new StoredAudio($att->storage_path, $att->disk),
                default => new StoredDocument($att->storage_path, $att->disk),
            };
        }

        return $attachments;
    }

    /**
     * Generate a concise chat title with a cheap one-shot model call, falling
     * back to a truncation of the first message if the call fails or the model
     * isn't reachable.
     */
    private function generateTitle(string $firstMessage, Lab $provider): string
    {
        $fallback = $this->titleFrom($firstMessage);
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($firstMessage)) ?? '');
        if ($clean === '') {
            return $fallback;
        }

        try {
            $titleAgent = new ChatAgent(
                instructions: 'You generate very short chat titles. Reply with ONLY a 3-6 word title in the same language as the message. No quotes, no punctuation at the end, no prefixes.',
                messages: [],
                tools: [],
            );

            $response = $titleAgent->prompt(
                'Title for this conversation starter:'."\n\n".Str::limit($clean, 1000),
                provider: $provider,
                model: $this->aiDefaults->model('summary_short'),
            );

            $title = trim(strip_tags((string) ($response->text ?? '')));
            $title = trim($title, " \t\n\r\0\x0B\"'`.");

            return $title !== '' ? Str::limit($title, 60, '') : $fallback;
        } catch (\Throwable $e) {
            Log::warning('Chat AI: title generation failed (using fallback)', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    /**
     * A short title derived from the first user message, trimmed at a word
     * boundary.
     */
    private function titleFrom(string $text): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if ($clean === '') {
            return 'New chat';
        }

        return Str::limit($clean, 48, '…');
    }

    /**
     * Paragraph break before a new streamed text block that follows existing
     * text, unless the buffer already ends in a newline.
     */
    public static function blockSeparator(string $buffer, bool $sawText): string
    {
        if ($sawText && $buffer !== '' && ! str_ends_with($buffer, "\n")) {
            return "\n\n";
        }

        return '';
    }

    /**
     * Balance the `<artifact>` tags in a finished reply. When the model ends the
     * turn without emitting a closing `</artifact>`, the frontend card would spin
     * on "Writing…" forever; appending the missing tag(s) finalizes the persisted
     * content so it renders closed on stream-complete and on reload. Only fully
     * formed `<artifact …>` openers count, mirroring the frontend parser, so a
     * reply truncated mid-tag isn't given a stray close.
     */
    public static function closeDanglingArtifacts(string $buffer): string
    {
        $opens = preg_match_all('/<artifact\b[^>]*>/i', $buffer);
        $closes = preg_match_all('/<\/artifact>/i', $buffer);
        $dangling = $opens - $closes;

        if ($dangling > 0) {
            $buffer .= str_repeat('</artifact>', $dangling);
        }

        return $buffer;
    }

    /**
     * Broadcasts go to Reverb over HTTP. If Reverb is down we must not crash
     * the job — the message is already persisted, so a refresh recovers it.
     */
    private function safeBroadcast(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            static $lastWarn = 0;
            if (microtime(true) - $lastWarn > 1) {
                Log::warning('Chat AI broadcast failed (continuing)', ['error' => $e->getMessage()]);
                $lastWarn = microtime(true);
            }
        }
    }
}
