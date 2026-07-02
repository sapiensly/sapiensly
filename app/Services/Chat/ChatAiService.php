<?php

namespace App\Services\Chat;

use App\Ai\ChatAgent;
use App\Ai\Tools\Capabilities\GenerateImageTool;
use App\Ai\Tools\Capabilities\OcrDocumentTool;
use App\Ai\Tools\Capabilities\RerankTool;
use App\Ai\Tools\Capabilities\SynthesizeSpeechTool;
use App\Ai\Tools\Capabilities\TranscribeAudioTool;
use App\Ai\Tools\Chat\AskUserQuestionTool;
use App\Ai\Tools\Chat\ConsultAgentTool;
use App\Ai\Tools\Chat\ProposeBuildTool;
use App\Ai\Tools\DynamicTool;
use App\Ai\Tools\McpServerTool;
use App\Ai\Tools\Platform\PlatformToolsFactory;
use App\Ai\Tools\RuntimeToolFactory;
use App\Enums\ToolType;
use App\Events\Chat\ChatStreamChunk;
use App\Events\Chat\ChatStreamComplete;
use App\Events\Chat\ChatStreamError;
use App\Events\Chat\ChatToolCall;
use App\Jobs\SummarizeChatHistoryJob;
use App\Mcp\Servers\SapiensServer;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Tool;
use App\Models\User;
use App\Services\Ai\AiCapabilities;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiSpendGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\OpenRouterClient;
use App\Services\AiProviderService;
use App\Services\CloudProviderService;
use App\Services\RetrievalService;
use App\Services\ToolConfigService;
use App\Services\ToolExecutionService;
use App\Services\Tools\McpClient;
use App\Support\Chat\ConsultationLog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
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
use Laravel\Ai\Streaming\Events\ToolResult;

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

    /**
     * Inline placeholder for an agent consultation, emitted into the reply at the
     * point the assistant paused to consult. The frontend splits the content on
     * this token and renders the matching consultation card there (order-matched
     * to consultation_context). Kept in sync with CONSULT_MARKER in the frontend.
     */
    public const CONSULT_MARKER = "\n\n[[consult]]\n\n";

    /**
     * Max seconds the stream may go without producing a new event before we treat
     * the provider connection as stalled and abort. Guzzle's request timeout only
     * bounds connection setup, not reads from an already-open SSE body, so a
     * provider that opens the stream and then hangs would otherwise block until
     * the worker's pcntl alarm kills the job (an uncatchable hard timeout). This
     * watchdog turns that into a clean, catchable error.
     *
     * Reset on every stream event (including tool calls), so it bounds *idle*
     * time, not total runtime. Kept generous enough to tolerate slow
     * time-to-first-token on large prompts and in-stream tool execution
     * (a `consult_agent` turn or a slow REST tool yields no events while it runs).
     */
    private const STREAM_IDLE_TIMEOUT_SECONDS = 120;

    private const MAX_HISTORY_MESSAGES = 30;

    /**
     * Long-conversation summarization (the `summary_large` AI default). The most
     * recent SUMMARY_KEEP_RECENT messages always stay verbatim; once at least
     * SUMMARY_BATCH_MIN older-than-that messages have accrued past the summary
     * watermark, they are folded into the rolling summary.
     */
    private const SUMMARY_KEEP_RECENT = 10;

    private const SUMMARY_BATCH_MIN = 8;

    /** A first message at or under this length becomes the title verbatim — no model call. */
    private const TITLE_DIRECT_MAX_CHARS = 60;

    /** Regenerate the title once the conversation reaches this many messages (3 user + 3 assistant). */
    private const TITLE_REFINE_AT_MESSAGES = 6;

    private const SUMMARY_INSTRUCTIONS = <<<'PROMPT'
        You compress a chat conversation into a dense running summary that another AI assistant will rely on as its memory of earlier turns.

        Produce ONE summary that merges the existing summary (if provided) with the new messages. Preserve the user's goal, decisions made, concrete facts, names, numbers, identifiers (files, code, orders, IDs), stated preferences, and any open questions or pending tasks. Drop greetings, filler and redundancy. Do NOT answer, continue, or comment on the conversation — only summarize it. Write in the conversation's own language, as compact paragraphs or bullet points, under ~400 words.
        PROMPT;

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

    /**
     * Capability awareness for the plain model chat: tells the assistant it runs
     * inside Sapiensly and can build apps/chatbots/integrations/knowledge
     * bases/agents in the user's workspace (the platform tools merged in by
     * {@see PlatformToolsFactory}), so it proactively proposes building something
     * when it detects a need the platform can cover. Only injected for plain
     * chats — a selected agent governs its own persona via its prompt_template.
     * Kept high-level (categories, not a tool-by-tool dump) so the cacheable
     * prefix stays small; depth is pulled on demand via `guide` /
     * `framework_reference`. The capability categories mirror the non-denylisted
     * create_* tools in {@see SapiensServer::TOOLS}.
     */
    private const PLATFORM_CAPABILITIES_GUIDANCE = <<<'PROMPT'
        ## Building on the Sapiensly platform
        You are running inside Sapiensly — a platform where the user can build low-code apps, autonomous agents, chatbots, integrations and knowledge bases. Beyond answering questions, you can create and edit these things directly in the user's own workspace using the platform tools available to you. So when a user describes a need the platform can cover, don't just reply in prose — recognise it and offer to build it.

        What you can build (use the listed tools; pull `guide` or the relevant `framework_reference` topic for depth before building, and `whoami` to confirm what this user may do):
        - **Apps & dashboards** — low-code data apps with forms, tables, CRUD, dashboards and reports. `scaffold_app` builds a COMPLETE app from a description in one step; `create_app` + `propose_change` for finer control.
        - **Project / plan trackers** — a special case worth spotting: whenever the conversation lands on a plan, roadmap, project or set of phases/milestones with dates, you can turn it into a real tracking app. Scaffold an app whose Tasks (or Milestones/Phases) object has a start date, an end date and a status — the app then renders a **Gantt timeline** of the work plan, plus a board and list. Pass the plan's ACTUAL tasks as `seed_records` (absolute ISO dates resolved from today, one row per plan action) so the tracker opens populated — an empty Gantt breaks the card's promise.
        - **Documents** — save an HTML or Markdown deliverable the user can keep in their workspace (a report, brief, spec, plan write-up): `save_document` (or `add_document`).
        - **Presentations** — a polished slide deck rendered on-brand that the user presents full-screen and shares: `create_presentation`. Offer it when the conversation produced content worth presenting to others (a strategy, pitch, plan, results review). You author a constrained slide manifest (layouts: title, section, bullets, two_column, big_number, metrics, chart, quote, closing) — never slide HTML; the platform guarantees the visual quality. One message per slide, tight copy. To EDIT an existing deck on feedback ("make slide 4 punchier", "add a chart after the metrics"), use `get_presentation` + `update_presentation` with slide-level operations — never recreate the deck. Chart and metric slides can bind to LIVE app data via `data_source` / `value_source` so the numbers refresh on every open.
        - **Chatbots & bot flows** — conversational flows for a website or WhatsApp: `create_chatbot`, `scaffold_bot_flow`.
        - **Integrations & tools** — connect external systems and call their actions: `create_integration`, `create_tool` (browse with `list_available_integrations`, `list_connector_actions`).
        - **Knowledge bases (RAG)** — searchable document collections for grounded answers: `create_knowledge_base`, `add_document`, `search_knowledge`.
        - **Agents** — triage / knowledge / action agents that resolve tasks autonomously: `create_agent`.
        - **Data** — query, aggregate and manage records in existing apps: `query_records`, `aggregate_records`, `create_record`.

        When to propose: when you detect a recurring or structural need the platform covers — tracking records, repetitive data entry, a process to automate, a customer-support assistant, connecting an external service, or organising documents for grounded Q&A. Two moments to watch for especially:
        - You just wrote (or are about to write) a substantial HTML or Markdown deliverable the user will want to keep → offer to **save it as a document** (`save_document`, parameters {name, body (the full content), type: "artifact" for HTML or "md" for Markdown}).
        - The conversation is about a plan/project/roadmap with steps and timing → offer to **build a tracking app with a Gantt** (`scaffold_app`; describe a Tasks object with start date, end date and status, and seed it with the plan's tasks via `seed_records`).
        Map the need to the specific capability above and offer it.

        How to propose:
        - When you PROACTIVELY spot the need, surface it with the `propose_build` tool — it shows the user an Execute / Dismiss card and runs the build only if they accept. Fill `parameters` with the inputs the matching tool needs (create_app → name, slug; scaffold_app → name, description, seed_records; save_document → name, body, type). After calling it, briefly tell the user you've proposed it; do NOT also run the underlying tool yourself.
        - Build DIRECTLY with the create_* tools only when the user has explicitly asked you to build it now ("create an app called X"). Otherwise prefer the proposal card over silent creation.
        - Don't derail a simple question, a one-off answer, or casual chat with a build offer. One clear suggestion beats repeated nudges; if the user declines, drop it.
        - Before proposing or building, lean on the relevant `guide` / `framework_reference` topic and confirm the user is allowed (`whoami`). If a capability isn't available to this user, or the platform genuinely can't express what they need, say so honestly — never promise or fake a feature.
        PROMPT;

    /**
     * Tells the plain chat model when to use the `ask_user_question` tool instead
     * of writing a clarifying question in prose. The tool renders a card of
     * clickable options and ENDS the turn; the user's pick returns as their next
     * message. Kept tight so the model reaches for it only on genuinely bounded
     * decisions, not as a way to stall on things it can decide itself.
     */
    private const ASK_USER_QUESTION_GUIDANCE = <<<'PROMPT'
        ## Asking the user to choose
        When you need the user to make a bounded decision before you can continue — picking between a few concrete options (which account, which format, confirm vs cancel, which of several matches) — use the `ask_user_question` tool instead of writing the question as prose. It renders clickable option buttons (with an optional "Other" free-text field) and reads better than a numbered list.

        Rules:
        - Only use it when a short menu of choices genuinely unblocks you. For open-ended questions, or anything you can reasonably decide or infer yourself, just answer normally — don't turn every clarification into a poll.
        - Calling it ENDS your turn: you will NOT see the answer in the same reply. The user's choice arrives as their next message and you continue from there, so don't also write out the options or pre-empt the answer.
        - Give 2-6 distinct, mutually exclusive options with short labels; keep "Other" enabled unless the choices are truly exhaustive.
        - One question at a time. Don't stack multiple question cards in a single turn.
        PROMPT;

    public function __construct(
        private readonly AiProviderService $providers,
        private readonly AiDefaults $aiDefaults,
    ) {}

    /**
     * Stream an ordinary chat turn. A selected agent (chat.agent_id) runs the
     * turn as that agent; otherwise it is a plain model chat.
     */
    public function streamMessage(ChatMessage $placeholder, string $userText, ?string $modelOverride = null, bool $webSearch = false, array $toolIds = []): ChatMessage
    {
        return $this->performStream($placeholder, $userText, null, $modelOverride, $webSearch, $toolIds);
    }

    /**
     * Stream one mentioned agent's turn in a multi-agent thread. Forces the given
     * agent's prompt/model/KBs/tools/web-search regardless of chat.agent_id, tags
     * the message with the agent, and snapshots the data sources it used into
     * agent_data_context (the data pills).
     */
    public function streamAgentTurn(ChatMessage $placeholder, Agent $agent, string $userText): ChatMessage
    {
        $placeholder->forceFill([
            'agent_id' => $agent->id,
            'message_type' => 'text',
        ])->save();

        return $this->performStream($placeholder, $userText, $agent, null, (bool) $agent->web_search, []);
    }

    private function performStream(ChatMessage $placeholder, string $userText, ?Agent $explicitAgent, ?string $modelOverride = null, bool $webSearch = false, array $toolIds = []): ChatMessage
    {
        set_time_limit(0);

        $chat = $placeholder->chat;
        $user = $chat->user;

        // When the conversation has been summarized, the summary stands in for
        // every message up to and including the watermark; only the verbatim
        // tail after it is sent. (Prefixed-ULID ids sort chronologically, so the
        // id is a safe ordering watermark.)
        $summary = trim((string) ($chat->summary ?? ''));
        $summaryWatermark = $summary !== '' ? $chat->summary_through_message_id : null;

        // History excludes the placeholder we're about to fill and any other
        // non-complete rows. We want the most recent N turns in chronological
        // order: reorder() clears the relation's default created_at ASC sort
        // (otherwise orderByDesc would only append a no-op tiebreaker, leaving
        // the query ascending and selecting the OLDEST N), then we take the
        // newest N and reverse back to chronological order.
        $history = $chat->messages()
            ->where('id', '!=', $placeholder->id)
            ->where('status', 'complete')
            ->when($summaryWatermark, fn ($q, $id) => $q->where('id', '>', $id))
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        // Label agent-authored turns with the agent's name so a later agent in a
        // multi-agent thread can see who said what and address them by name.
        $agentIds = $history->pluck('agent_id')->filter()->unique()->values()->all();
        $agentNames = empty($agentIds)
            ? []
            : Agent::query()->whereIn('id', $agentIds)->pluck('name', 'id')->all();

        $sdkHistory = [];
        foreach ($history as $m) {
            $content = (string) ($m->content ?? '');
            if ($content === '') {
                continue;
            }
            if ($m->role === 'user') {
                $sdkHistory[] = new UserMessage($content);

                continue;
            }
            // Inline consultation markers are a UI device; never feed them back.
            $content = self::stripMarkers($content);
            if ($m->agent_id !== null && isset($agentNames[$m->agent_id])) {
                $content = '[@'.$agentNames[$m->agent_id].'] '.$content;
            }
            $sdkHistory[] = new AssistantMessage($content);
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

        // An explicit agent (a mentioned agent in a multi-agent thread) always
        // runs the turn as itself. Otherwise a selected agent (chat.agent_id) runs
        // the turn as that agent; failing that it's a plain model chat using the
        // project's instructions/KBs and the composer's tools.
        $agent = $explicitAgent
            ?? ($chat->agent_id !== null ? Agent::find($chat->agent_id) : null);
        if ($agent !== null && ($user === null || ! $agent->isVisibleTo($user))) {
            $agent = null;
        }

        if ($agent !== null) {
            $instructions = trim((string) $agent->prompt_template) !== ''
                ? (string) $agent->prompt_template
                : self::SYSTEM_PROMPT;
            $resolvedModel = $this->aiDefaults->model('chat', $agent->model ?: ($modelOverride ?? $chat->model));
            $ragKbIds = $agent->knowledgeBaseIds();
            $toolIds = $agent->tools()->where('status', 'active')->pluck('tools.id')->all();
            // The agent governs web search via its own setting, mirroring how it
            // overrides the tool selection; the composer's value is ignored.
            $webSearch = (bool) $agent->web_search;
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

        if ($summary !== '') {
            $instructions .= "\n\n## Summary of earlier conversation\n"
                ."Earlier turns have been condensed into the summary below. Treat it as established context you already know — do not mention that a summary was provided.\n\n"
                .$summary;
        }

        $instructions .= "\n\n".self::ARTIFACTS_INSTRUCTIONS;

        $startedAt = microtime(true);
        $buffer = '';

        // Snapshot of the data sources this agent actually used this turn, rendered
        // as data pills. Only persisted for explicit agent turns (multi-agent).
        $usedSources = [];

        // Agents this turn consulted (via consult_agent), persisted as cards.
        $consultationLog = new ConsultationLog;

        try {
            $provider = Lab::Anthropic;
            if ($user !== null) {
                $this->providers->applyRuntimeConfig($user);
                $provider = $this->providers->resolveProviderForCatalogModel($resolvedModel, $user) ?? Lab::Anthropic;
            }

            // Knowledge (RAG): retrieved chunks change per query, so they must NOT
            // sit in the (cacheable) system prefix — that would invalidate the
            // cache every turn. Append them to the user turn instead, keeping the
            // system prefix byte-stable. Best-effort — a retrieval hiccup never
            // breaks the chat (retrieveContext swallows and returns '').
            [$ragContext, $ragChunks] = $this->retrieveContext($ragKbIds, $promptText, $chat->id);
            if ($ragChunks > 0) {
                $usedSources['Knowledge base'] = $ragChunks.' passage'.($ragChunks === 1 ? '' : 's');
            }

            // How many results web search may pull, when configured on the agent.
            $webSearchMax = $this->webSearchMaxResults($agent);

            // Web search is a provider-native tool only some gateways implement
            // (Anthropic/Gemini/OpenAI). Others can't take the WebSearch tool —
            // attaching it throws and would kill the turn. OpenRouter has its own
            // web search via its `web` plugin; everyone else degrades to none.
            // Either way we never attach the unsupported tool.
            $openRouterWebSearch = false;
            if ($webSearch && ! $this->providerSupportsWebSearch($provider)) {
                if ($provider === Lab::OpenRouter) {
                    $openRouterWebSearch = true;
                    $usedSources['Web search'] = 'via OpenRouter';
                } else {
                    Log::info('Web search unsupported by provider; continuing without it', [
                        'chat_id' => $chat->id,
                        'provider' => $provider->value,
                        'model' => $resolvedModel,
                    ]);
                    $usedSources['Web search'] = 'not available on this model — skipped';
                }
                $webSearch = false;
            }

            $tools = $this->buildChatTools($toolIds, $user, $webSearch, $webSearchMax);

            // Cross-agent consultation: when other agents exist, the running
            // model/agent can consult them mid-turn (background or in the front)
            // and is told they exist + how to reach them. Always user-visible.
            if ($user !== null) {
                // Plain model chat is made aware of the platform's build
                // capabilities (the tools merged in by PlatformToolsFactory) so it
                // proactively proposes building something when it detects a need
                // the platform can cover. A selected agent governs its own persona
                // via its prompt_template, so we don't override it with this. The
                // propose_build tool lets it surface an Execute/Dismiss card rather
                // than building silently.
                if ($agent === null) {
                    $instructions .= "\n\n".self::PLATFORM_CAPABILITIES_GUIDANCE;
                    $instructions .= "\n\n".self::ASK_USER_QUESTION_GUIDANCE;
                    $tools[] = RuntimeToolFactory::named('propose_build', new ProposeBuildTool($chat, $user));
                    $tools[] = RuntimeToolFactory::named('ask_user_question', new AskUserQuestionTool($chat, $user));
                }

                $roster = $this->consultableAgents($user, $agent);
                if ($roster->isNotEmpty()) {
                    $tools[] = RuntimeToolFactory::named(
                        'consult_agent',
                        new ConsultAgentTool($chat, $placeholder, $user, $agent, $consultationLog),
                    );
                    $instructions .= "\n\n".$this->consultationGuidance($roster);
                }

                // Specialized-capability handoff: expose a tool per capability
                // that has a model configured in admin AI > Defaults, and tell the
                // agent which task each one handles and through which tool.
                $configuredTools = app(AiCapabilities::class)->configuredTools();
                if ($configuredTools !== []) {
                    foreach ($this->capabilityTools($configuredTools, $user, $placeholder) as $name => $tool) {
                        $tools[] = RuntimeToolFactory::named($name, $tool);
                    }
                    $instructions .= "\n\n".$this->capabilityGuidance($configuredTools);
                }
            }

            $sdkAgent = new ChatAgent(
                instructions: $instructions,
                messages: $sdkHistory,
                tools: $tools,
            );

            // Mark the frozen prefix (system + agent/project instructions +
            // summary + artifacts guidance) as cacheable. RAG was deliberately
            // kept out of $instructions above, so this prefix is stable across
            // turns until the rolling summary recompresses.
            if (config('ai.prompt_caching.enabled')) {
                $sdkAgent->withCacheableSystem($instructions);
            }

            // OpenRouter web search rides on its `web` plugin (set via provider
            // options on the request), not a tool — enable it on the agent here.
            if ($openRouterWebSearch) {
                $sdkAgent->withWebSearch($webSearchMax);
            }

            $attachments = $this->buildAttachments($placeholder);

            $placeholder->update(['status' => 'streaming', 'model' => $resolvedModel]);

            Log::info('Chat AI streaming', [
                'chat_id' => $chat->id,
                'message_id' => $placeholder->id,
                'model' => $resolvedModel,
                'attachments' => count($attachments),
            ]);

            app(AiSpendGuard::class)->assertWithinBudget(
                $user, $user?->organization_id, $resolvedModel,
            );

            $stream = $sdkAgent->stream(
                $promptText.$ragContext,
                attachments: $attachments,
                provider: $provider,
                model: $resolvedModel,
            );

            $stopKey = self::STOP_CACHE_PREFIX.$placeholder->id;
            $sawText = false;
            $deltaCount = 0;

            // Idle watchdog: abort if the provider stalls mid-stream. Reset on
            // every event below; if it elapses, the handler throws and the catch
            // block surfaces a clean error — instead of the read blocking until
            // the worker's hard (uncatchable) pcntl timeout fires.
            $watchdog = $this->streamIdleWatchdog();
            $watchdog['arm']();

            // Total wall-clock cap for the stream. The idle watchdog above
            // commandeers the worker's SIGALRM and re-arms it on every token, which
            // disables the job's own total timeout — so a long, steadily-streaming
            // reply has NO total bound and would run until the queue's retry_after
            // re-reserves the still-running job and fails it with
            // MaxAttemptsExceeded (losing everything). This cap breaks cooperatively
            // and keeps the partial reply, dying cleanly well before retry_after.
            $maxStreamSeconds = (float) config('ai.max_stream_seconds', 300);
            $truncated = false;

            try {
                foreach ($stream as $event) {
                    $watchdog['tick']();

                    if ($maxStreamSeconds > 0 && (microtime(true) - $startedAt) > $maxStreamSeconds) {
                        $truncated = true;
                        break;
                    }

                    // Cooperative cancellation: the Stop button sets a cache flag the
                    // worker polls every few deltas, then breaks and finalizes the
                    // partial reply. Polling (not every token) keeps cache load low.
                    if (($deltaCount % 16) === 0 && Cache::get($stopKey)) {
                        break;
                    }

                    if ($event instanceof ToolCall) {
                        if ($event->toolCall->name === 'consult_agent') {
                            // Drop a positional marker into the reply so the frontend
                            // renders the consultation card inline — exactly where the
                            // assistant paused to consult — instead of lumped at the
                            // top. The card itself streams via ChatAgentConsultation.
                            $buffer .= self::CONSULT_MARKER;
                            $this->safeBroadcast(fn () => ChatStreamChunk::dispatch($chat->id, $placeholder->id, self::CONSULT_MARKER));
                        } else {
                            $usedSources[$this->prettySource($event->toolCall->name)] = 'used';
                            $this->safeBroadcast(fn () => ChatToolCall::dispatch(
                                $chat->id, $placeholder->id, $event->toolCall->name, 'start', $event->toolCall->id,
                            ));
                        }

                        continue;
                    }

                    if ($event instanceof ToolResult) {
                        // Completion half of the lifecycle: flip the chip to done/failed.
                        if ($event->toolResult->name !== 'consult_agent') {
                            $this->safeBroadcast(fn () => ChatToolCall::dispatch(
                                $chat->id, $placeholder->id, $event->toolResult->name, 'result', $event->toolResult->id, $event->successful,
                            ));
                        }

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
            } finally {
                $watchdog['disarm']();
            }
            Cache::forget($stopKey);

            $buffer = self::closeDanglingArtifacts($buffer);

            if ($truncated) {
                $locale = $user?->locale ?? (string) config('app.fallback_locale', 'en');
                $buffer .= "\n\n".__('_(The response was cut off because it was taking too long. Ask me to continue for the rest.)_', [], $locale);
                Log::warning('Chat AI stream truncated at wall-clock cap', [
                    'chat_id' => $chat->id,
                    'message_id' => $placeholder->id,
                    'max_stream_seconds' => $maxStreamSeconds,
                    'response_length' => strlen($buffer),
                ]);
            }

            $finalAttributes = [
                'content' => $buffer,
                'model' => $resolvedModel,
                'status' => 'complete',
            ];
            if ($explicitAgent !== null) {
                $finalAttributes['agent_data_context'] = $usedSources ?: null;
            }
            if (! $consultationLog->isEmpty()) {
                $finalAttributes['consultation_context'] = $consultationLog->all();
            }
            $placeholder->update($finalAttributes);

            $chat->forceFill([
                'last_message_at' => now(),
                'model' => $resolvedModel,
            ]);
            $this->maybeUpdateTitle($chat, $promptText);
            $chat->save();

            $this->safeBroadcast(fn () => ChatStreamComplete::dispatch($placeholder->refresh(), $chat->title));

            $this->maybeQueueSummary($chat);

            Log::info('Chat AI stream finished', [
                'chat_id' => $chat->id,
                'message_id' => $placeholder->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'response_length' => strlen($buffer),
                'provider' => $provider->value,
                'prompt_tokens' => $stream->usage?->promptTokens ?? 0,
                'completion_tokens' => $stream->usage?->completionTokens ?? 0,
                'cache_read_input_tokens' => $stream->usage?->cacheReadInputTokens ?? 0,
                'cache_write_input_tokens' => $stream->usage?->cacheWriteInputTokens ?? 0,
            ]);

            app(AiUsageRecorder::class)->record(
                'chat', $resolvedModel, $chat->user, $chat->user?->organization_id, $stream->usage ?? null,
            );

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
     * Idle watchdog for a provider stream. Returns arm/tick/disarm closures:
     * arm() starts a SIGALRM countdown of STREAM_IDLE_TIMEOUT_SECONDS, tick()
     * resets it on each stream event, disarm() cancels it and restores the prior
     * signal state. If the countdown elapses the handler throws, which the
     * streaming try/catch surfaces as a clean provider error rather than letting
     * a hung read block until the worker's uncatchable hard timeout.
     *
     * Degrades to no-ops when pcntl async signals aren't available (non-Linux /
     * some dev setups), falling back to the worker timeout — the same guard that
     * existed before. Arming overrides the worker's own SIGALRM handler for the
     * duration of the stream; disarm() restores it. Since the watchdog window is
     * shorter than the worker timeout, it always fires first when it fires.
     *
     * @return array{arm: callable(): void, tick: callable(): void, disarm: callable(): void}
     */
    private function streamIdleWatchdog(): array
    {
        $noop = static function (): void {};
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_alarm')) {
            return ['arm' => $noop, 'tick' => $noop, 'disarm' => $noop];
        }

        $timeout = self::STREAM_IDLE_TIMEOUT_SECONDS;
        $previousHandler = null;
        $previousAsync = null;

        $arm = function () use (&$previousHandler, &$previousAsync, $timeout): void {
            $previousAsync = pcntl_async_signals(true);
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            pcntl_signal(SIGALRM, static function (): void {
                throw new \RuntimeException(sprintf(
                    'The model stopped responding (no stream activity for %ds).',
                    self::STREAM_IDLE_TIMEOUT_SECONDS,
                ));
            });
            pcntl_alarm($timeout);
        };

        $tick = static function () use ($timeout): void {
            pcntl_alarm($timeout);
        };

        $disarm = function () use (&$previousHandler, &$previousAsync, $timeout): void {
            pcntl_alarm(0);
            if (is_callable($previousHandler) || is_int($previousHandler)) {
                pcntl_signal(SIGALRM, $previousHandler);
                // Arming consumed the worker's own alarm; restore a bounded
                // backstop for the post-stream tail (title/summary AI calls) using
                // the worker's handler, so the tail can't hang unbounded either.
                if (is_callable($previousHandler)) {
                    pcntl_alarm($timeout);
                }
            }
            if ($previousAsync !== null) {
                pcntl_async_signals($previousAsync);
            }
        };

        return ['arm' => $arm, 'tick' => $tick, 'disarm' => $disarm];
    }

    /**
     * Build the SDK tools the agent may call this turn: the user's selected
     * REST/GraphQL/Database tools (via ToolBuilderService) and MCP server tools
     * (expanded from each MCP tool's cached tool list), plus native web search.
     *
     * @param  array<int, string>  $toolIds
     * @return array<int, object>
     */
    /**
     * Whether the resolved provider's gateway implements the native WebSearch
     * provider tool. Only Anthropic, Gemini and OpenAI do; every other gateway
     * (OpenRouter, Groq, DeepSeek, …) throws when a provider tool is attached.
     */
    private function providerSupportsWebSearch(Lab $provider): bool
    {
        return in_array($provider, [Lab::Anthropic, Lab::Gemini, Lab::OpenAI], true);
    }

    /**
     * The agent's configured cap on web search results (config.web_search.max_results),
     * clamped to a sane 1–10. Null when unset or no agent — the provider's default applies.
     */
    private function webSearchMaxResults(?Agent $agent): ?int
    {
        $max = $agent?->config['web_search']['max_results'] ?? null;
        if (! is_numeric($max)) {
            return null;
        }

        return max(1, min(10, (int) $max));
    }

    private function buildChatTools(array $toolIds, ?User $user, bool $webSearch, ?int $webSearchMax = null): array
    {
        $tools = [];
        if ($webSearch) {
            $tools[] = new WebSearch(maxSearches: $webSearchMax);
        }

        if (empty($toolIds) || $user === null) {
            // Still grant platform tools when the agent has no tools of its own.
            return $user !== null ? PlatformToolsFactory::merge($tools, $user) : $tools;
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

        return PlatformToolsFactory::merge($tools, $user);
    }

    /**
     * Retrieve relevant context from the given knowledge bases (the agent's or
     * the project's) and format it as an appendable instructions block. Returns
     * ['', 0] when there are no KBs, nothing matches, or retrieval fails.
     *
     * @param  array<int, string>  $kbIds
     * @return array{0: string, 1: int}
     */
    private function retrieveContext(array $kbIds, string $query, string $chatId): array
    {
        if (empty($kbIds) || trim($query) === '') {
            return ['', 0];
        }

        try {
            $result = app(RetrievalService::class)->retrieve($query, $kbIds, topK: 6, threshold: 0.5);

            $chunks = (int) ($result['chunk_count'] ?? 0);
            if ($chunks === 0 || trim($result['context'] ?? '') === '') {
                return ['', 0];
            }

            return ["\n\n## Relevant context from the knowledge base\n"
                .'Use the following retrieved information when it helps answer the user. '
                ."If it doesn't contain the answer, rely on your own knowledge.\n\n"
                .$result['context'], $chunks];
        } catch (\Throwable $e) {
            Log::warning('Chat AI: RAG retrieval failed (continuing without context)', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return ['', 0];
        }
    }

    /**
     * Human-readable label for a data source / tool name used in data pills
     * (e.g. `web_search` → "Web search", `check-orders` → "Check orders").
     */
    private function prettySource(string $name): string
    {
        $label = trim((string) preg_replace('/[_-]+/', ' ', $name));

        return $label === '' ? $name : Str::ucfirst($label);
    }

    /**
     * The other agents the running model/agent may consult this turn — active
     * agents in the user's account context, excluding the one already running.
     *
     * @return Collection<int, Agent>
     */
    private function consultableAgents(User $user, ?Agent $current): Collection
    {
        return Agent::query()
            ->forAccountContext($user)
            ->where('status', 'active')
            ->when($current !== null, fn ($q) => $q->where('id', '!=', $current->id))
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'type']);
    }

    /**
     * System-prompt addendum that makes the running agent aware of its peers and
     * how to consult them, with a roster of who's available.
     *
     * @param  Collection<int, Agent>  $roster
     */
    private function consultationGuidance(Collection $roster): string
    {
        $list = $roster
            ->map(fn (Agent $a) => '- '.$a->name.' (id: '.$a->id.', '.$a->type?->value.')'
                .($a->description ? ' — '.$a->description : ''))
            ->implode("\n");

        return <<<PROMPT
            ## Consulting other agents
            You are not alone — these other agents are available in this workspace, each with their own expertise:

            {$list}

            When a question genuinely falls in another agent's domain, or a decision warrants a second opinion, consult one with the `consult_agent` tool (agent_id, question, visible). Set `visible: true` to show the user that agent's full answer as a card; leave it false to consult quietly and fold the answer into your reply. Briefly tell the user when you're consulting someone and why. Do NOT consult for routine questions you can answer yourself, and never consult more than necessary.
            PROMPT;
    }

    /**
     * Instantiate the capability tools that are configured, keyed by tool name.
     *
     * @param  array<string, array{tool: string, categories: list<string>, model: string, provider: Lab}>  $configured
     * @return array<string, ToolContract>
     */
    private function capabilityTools(array $configured, User $user, ChatMessage $placeholder): array
    {
        $caps = app(AiCapabilities::class);
        $cloud = app(CloudProviderService::class);
        $openRouter = app(OpenRouterClient::class);

        $factories = [
            'generate_image' => fn () => new GenerateImageTool($user, $caps, $cloud, $openRouter),
            'synthesize_speech' => fn () => new SynthesizeSpeechTool($user, $caps, $cloud, $openRouter),
            'rerank' => fn () => new RerankTool($caps),
            'transcribe_audio' => fn () => new TranscribeAudioTool($placeholder, $caps, $user, $openRouter),
            'ocr_document' => fn () => new OcrDocumentTool($placeholder, $caps, $user, $openRouter),
        ];

        $tools = [];
        foreach (array_keys($configured) as $name) {
            if (isset($factories[$name])) {
                $tools[$name] = $factories[$name]();
            }
        }

        return $tools;
    }

    /**
     * Tell the agent which specialized tasks it can hand off and through which
     * tool — listing only capabilities with a model configured in Defaults.
     *
     * @param  array<string, array{tool: string, categories: list<string>, model: string, provider: Lab}>  $configured
     */
    private function capabilityGuidance(array $configured): string
    {
        $descriptions = [
            'generate_image' => 'generate an image from a description',
            'synthesize_speech' => 'turn text into spoken audio',
            'rerank' => 'reorder candidate passages by relevance to a query',
            'transcribe_audio' => 'transcribe an attached audio file to text',
            'ocr_document' => 'extract text from an attached PDF or image (incl. scans)',
        ];

        $lines = [];
        foreach ($configured as $name => $info) {
            $what = $descriptions[$name] ?? $name;
            $lines[] = "- `{$name}` — {$what} (handled by {$info['model']}).";
        }
        $list = implode("\n", $lines);

        return <<<PROMPT
            ## Specialized capabilities
            Beyond chatting, you can hand off specialized tasks to dedicated models by calling these tools:

            {$list}

            Call the matching tool when the user's request needs that capability; don't attempt these tasks yourself. If a needed capability isn't listed, it isn't configured — tell the user it's unavailable rather than guessing.
            PROMPT;
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
    /**
     * Queue a history summary when enough un-summarized older messages have
     * accrued past the watermark. Best-effort — a queue hiccup must never fail a
     * turn that already completed and broadcast.
     */
    private function maybeQueueSummary(Chat $chat): void
    {
        try {
            $pending = $chat->messages()
                ->where('status', 'complete')
                ->when($chat->summary_through_message_id, fn ($q, $id) => $q->where('id', '>', $id))
                ->count();

            if ($pending >= self::SUMMARY_KEEP_RECENT + self::SUMMARY_BATCH_MIN) {
                SummarizeChatHistoryJob::dispatch($chat->id);
            }
        } catch (\Throwable $e) {
            Log::warning('Chat AI: could not queue history summary', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fold the older messages of a long conversation into the chat's rolling
     * summary (the `summary_large` default), keeping the most recent turns
     * verbatim. Idempotent: each run merges the prior summary with the new batch
     * and advances the watermark, so it only ever processes fresh older messages.
     */
    public function summarizeHistory(Chat $chat): void
    {
        $existing = trim((string) ($chat->summary ?? ''));
        $watermark = $existing !== '' ? $chat->summary_through_message_id : null;

        $pending = $chat->messages()
            ->where('status', 'complete')
            ->when($watermark, fn ($q, $id) => $q->where('id', '>', $id))
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Keep the most recent turns verbatim; only fold what is older than them.
        $foldCount = $pending->count() - self::SUMMARY_KEEP_RECENT;
        if ($foldCount < self::SUMMARY_BATCH_MIN) {
            return;
        }

        $toFold = $pending->slice(0, $foldCount)->values();

        $transcript = $toFold
            ->map(fn (ChatMessage $m) => mb_strtoupper((string) $m->role).': '.self::stripMarkers(trim((string) $m->content)))
            ->implode("\n\n");

        $model = $this->aiDefaults->model('summary_large');
        $provider = $this->resolveModelProvider($model, $chat->user);

        $prompt = ($existing !== '' ? "Existing summary:\n".$existing."\n\n" : '')
            ."Conversation messages to fold into the summary:\n\n".$transcript;

        try {
            $agent = new ChatAgent(instructions: self::SUMMARY_INSTRUCTIONS, messages: [], tools: []);
            $response = $agent->prompt(Str::limit($prompt, 24000), provider: $provider, model: $model);

            $summary = trim(strip_tags((string) ($response->text ?? '')));
            if ($summary === '') {
                return;
            }

            $chat->forceFill([
                'summary' => $summary,
                'summary_through_message_id' => $toFold->last()->id,
            ])->save();

            Log::info('Chat AI: history summarized', [
                'chat_id' => $chat->id,
                'folded' => $toFold->count(),
                'through' => $toFold->last()->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Chat AI: history summarization failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Title lifecycle: derive an initial title on the first turn, then regenerate
     * a sharper one once the conversation matures to {@see self::TITLE_REFINE_AT_MESSAGES}
     * messages (3 user + 3 assistant). A short opener is used verbatim — no model call.
     */
    private function maybeUpdateTitle(Chat $chat, string $promptText): void
    {
        $user = $chat->user;

        if ($chat->title === null) {
            $chat->title = $this->initialTitle($promptText, $user);

            return;
        }

        // Regenerate the title once, as soon as the conversation has matured to at
        // least TITLE_REFINE_AT_MESSAGES (3 user + 3 assistant). The watermark
        // makes it fire a single time and survive chats already past the threshold
        // and errored/retried turns that skew the count (a plain `=== N` could miss
        // the exact tick). Stamp the watermark only on a successful regeneration so
        // a transient model failure retries on a later turn instead of giving up.
        if ($chat->title_refined_at !== null) {
            return;
        }

        $completeCount = $chat->messages()->where('status', 'complete')->count();
        if ($completeCount < self::TITLE_REFINE_AT_MESSAGES) {
            return;
        }

        $refined = $this->refineTitle($chat, $user);
        if ($refined === null) {
            return;
        }

        $chat->title = $refined;
        $chat->title_refined_at = now();

        Log::info('Chat AI: title regenerated', [
            'chat_id' => $chat->id,
            'messages' => $completeCount,
            'title' => $refined,
        ]);
    }

    /**
     * Resolve the provider for a title/summary model. These run on the
     * `summary_short` / `summary_large` defaults, which may live on a different
     * provider than the chat model — so route by the model's own catalog driver,
     * never the chat's provider (else e.g. an OpenRouter model id gets sent to
     * Anthropic and 404s).
     */
    private function resolveModelProvider(string $model, ?User $user): Lab
    {
        if ($user === null) {
            return Lab::Anthropic;
        }

        $this->providers->applyRuntimeConfig($user);

        return $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;
    }

    /**
     * First-turn title: a short opener already reads as a title, so use it verbatim
     * and skip the model; a long opener is condensed via the short-summary model.
     */
    private function initialTitle(string $firstMessage, ?User $user): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($firstMessage)) ?? '');
        if ($clean === '') {
            return $this->titleFrom($firstMessage);
        }

        if (mb_strlen($clean) <= self::TITLE_DIRECT_MAX_CHARS) {
            return $this->normalizeTitle($clean);
        }

        return $this->titleFromModel(
            'Title for this conversation starter:'."\n\n".Str::limit($clean, 1000),
            $user,
        ) ?? $this->titleFrom($firstMessage);
    }

    /**
     * Regenerate the title from the opening exchange (the first
     * {@see self::TITLE_REFINE_AT_MESSAGES} messages). Returns null on an empty
     * transcript or a model failure so the caller can leave the current title.
     */
    private function refineTitle(Chat $chat, ?User $user): ?string
    {
        $transcript = $chat->messages()
            ->where('status', 'complete')
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::TITLE_REFINE_AT_MESSAGES)
            ->get()
            ->map(fn (ChatMessage $m) => mb_strtoupper((string) $m->role).': '.self::stripMarkers(trim((string) $m->content)))
            ->implode("\n\n");

        if (trim($transcript) === '') {
            return null;
        }

        return $this->titleFromModel(
            'Generate a concise title for this conversation:'."\n\n".Str::limit($transcript, 4000),
            $user,
        );
    }

    /**
     * Prompt the short-summary model (on its own provider) for a title. Returns
     * null on an empty response or any error.
     */
    private function titleFromModel(string $prompt, ?User $user): ?string
    {
        $model = $this->aiDefaults->model('summary_short');

        try {
            $titleAgent = new ChatAgent(
                instructions: 'You generate very short chat titles. Reply with ONLY a 3-6 word title in the same language as the conversation. No quotes, no punctuation at the end, no prefixes.',
                messages: [],
                tools: [],
            );

            $response = $titleAgent->prompt(
                $prompt,
                provider: $this->resolveModelProvider($model, $user),
                model: $model,
            );

            $title = $this->normalizeTitle((string) ($response->text ?? ''));

            return $title !== '' ? $title : null;
        } catch (\Throwable $e) {
            Log::warning('Chat AI: title generation failed', ['error' => $e->getMessage(), 'model' => $model]);

            return null;
        }
    }

    /** Strip tags, wrapping quotes/trailing punctuation, and cap a title at 60 characters. */
    private function normalizeTitle(string $text): string
    {
        $title = trim(strip_tags($text));
        $title = trim($title, " \t\n\r\0\x0B\"'`.");

        return Str::limit($title, 60, '');
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
    /**
     * Strip the inline consultation markers from persisted content before it is
     * fed back to a model (history / summary / title). They are a UI-only device
     * for rendering the consultation card in place; the model must never see them.
     */
    public static function stripMarkers(string $text): string
    {
        return (string) preg_replace("/\n*\\[\\[consult\\]\\]\n*/", "\n\n", $text);
    }

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
