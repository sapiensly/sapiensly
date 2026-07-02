<?php

namespace App\Ai\Tools\Chat;

use App\Events\Chat\ChatActionProposalReady;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ActionExecutor;
use App\Services\Chat\Actions\ActionRegistry;
use App\Services\Chat\Actions\PlatformBuildAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request as AiRequest;
use Stringable;

/**
 * A chat-scoped tool that lets the plain assistant surface a "build this" card —
 * an {@see ChatMessage} with `message_type=action_proposal` — proposing that the
 * platform create something for the user (an app/dashboard, chatbot, integration,
 * knowledge base, or agent). The card carries Execute / Dismiss buttons; the
 * build only runs (via {@see ActionExecutor} →
 * {@see PlatformBuildAction}) if the user clicks
 * Execute. This is the proactive counterpart to the model calling a create_*
 * tool directly: it puts the decision in the user's hands instead of building
 * silently. The proposal is surfaced live via {@see ChatActionProposalReady}.
 */
class ProposeBuildTool implements ToolContract
{
    /**
     * The platform build actions a proposal may target. Each MUST have a
     * registered handler in {@see ActionRegistry}; the enum here and the
     * registry are the two halves of the same contract.
     *
     * @var list<string>
     */
    public const BUILD_TYPES = [
        'create_app',
        'scaffold_app',
        'create_chatbot',
        'create_integration',
        'create_knowledge_base',
        'create_agent',
        'save_document',
        'create_presentation',
    ];

    public function __construct(
        private Chat $chat,
        private User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Surface an actionable "build this" card (with Execute / Dismiss buttons) proposing that the platform build something for the user. The build runs only if the user clicks Execute. Use this when you PROACTIVELY detect a need the platform can cover — instead of building silently. Put the inputs the matching tool needs in `parameters`; do NOT also run the tool yourself — the card runs it. Action types:
- `create_app`: an empty app to refine interactively (parameters: name, slug).
- `scaffold_app`: a COMPLETE ready-to-use app generated from a description in one step — objects, fields, list/board pages and a dashboard. Prefer this for a real "build me an app" offer, e.g. a PROJECT / PLAN TRACKER: parameters {name, description, seed_records}. Make the description spell out the entities and their fields; for a work plan describe a "Tasks" object with a start date, an end date and a status — the app then renders a Gantt timeline of the plan. ALWAYS include `seed_records` when the conversation already contains the data the app should open with (the plan\'s actual tasks, milestones, content pieces): [{object: "tasks", records: [{field: value, …}, …]}], keyed by the field names from your description, dates as absolute ISO YYYY-MM-DD (resolve "week 1" etc. from today), selects by option label. An empty tracker breaks the promise of the card.
- `create_chatbot`, `create_integration`, `create_knowledge_base`, `create_agent`: parameters satisfy that create_* tool.
- `save_document`: save a document the user can keep in Sapiensly. Use it after you produce a substantial HTML or Markdown deliverable (a report, brief, spec, plan write-up). parameters {name, body (the full content), type: "artifact" for HTML or "md" for Markdown}.
- `create_presentation`: a polished slide deck the user can present full-screen and share, rendered on-brand by the platform. Offer it when the conversation produced content worth presenting (a strategy, pitch, plan, results review). parameters {name, theme?: executive|dark|minimal|bold, slides: [{layout, ...fields}]} — author the COMPLETE slides array in the proposal using the constrained layouts (title, section, bullets, two_column, big_number, metrics, chart, quote, timeline, table, closing); never HTML. Keep copy tight: one message per slide.
When the user has explicitly asked you to build/save something right now, call the underlying tool directly instead of proposing.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action_type' => $schema->string()->enum(self::BUILD_TYPES)->description('Which platform feature to build.')->required(),
            'action_label' => $schema->string()->description('Short imperative label for the Execute button / card (max 60 chars), e.g. "Create the Support Desk app".')->required(),
            'summary' => $schema->string()->description('1-3 sentences, in the user\'s language, explaining what will be built and why it fits their need.')->required(),
            'parameters' => $schema->object()->description('The inputs for the matching create_* tool (e.g. {"name":"Support Desk","slug":"support_desk"}). Must satisfy that tool\'s required fields.')->required(),
            'rationale' => $schema->string()->description('Optional one-line reason (max 120 chars).'),
        ];
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $args = $request->toArray();

        $type = (string) ($args['action_type'] ?? '');
        if (! in_array($type, self::BUILD_TYPES, true)) {
            return 'Error: action_type must be one of: '.implode(', ', self::BUILD_TYPES).'.';
        }

        $label = trim((string) ($args['action_label'] ?? ''));
        $summary = trim((string) ($args['summary'] ?? ''));
        if ($label === '' || $summary === '') {
            return 'Error: provide both action_label and summary.';
        }

        $parameters = (array) ($args['parameters'] ?? []);
        // Convenience: derive a slug from the name for app creation when omitted,
        // so a proposal that only names the app still executes.
        if ($type === 'create_app' && empty($parameters['slug']) && ! empty($parameters['name'])) {
            $parameters['slug'] = Str::of((string) $parameters['name'])->slug('_')->toString();
        }

        $payload = [
            'action_type' => $type,
            'action_label' => Str::limit($label, 60, ''),
            'summary' => Str::limit($summary, 600, ''),
            'agreed_by' => [],
            'parameters' => $parameters,
            'rationale' => Str::limit(trim((string) ($args['rationale'] ?? '')), 120, ''),
            'executable' => app(ActionRegistry::class)->knows($type),
            // Per-message lifecycle: a single chat can hold several proposals, so
            // the card's executed/dismissed state lives here, not on the chat.
            'status' => 'ready',
        ];

        $message = ChatMessage::create([
            'chat_id' => $this->chat->id,
            'role' => 'assistant',
            'content' => $payload['action_label'],
            'status' => 'complete',
            'message_type' => 'action_proposal',
            'action_payload' => $payload,
        ]);

        try {
            // Single-turn proposals never touch the chat-level synthesis_status
            // (that drives the multi-agent synthesis UI); pass the current value
            // through unchanged so the listener doesn't flip it.
            ChatActionProposalReady::dispatch($message, (string) ($this->chat->synthesis_status ?? ''));
        } catch (\Throwable) {
            // Live surfacing is best-effort; the card still loads on reload.
        }

        return 'Proposed a build card to the user for "'.$payload['action_label'].'". It runs only if they click Execute. Do not build it yourself now — briefly tell the user you have proposed it.';
    }
}
