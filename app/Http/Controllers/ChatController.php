<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Http\Requests\Chat\UpdateChatRequest;
use App\Jobs\RunChatAiJob;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatProject;
use App\Models\KnowledgeBase;
use App\Models\Tool;
use App\Services\AiProviderService;
use App\Services\Chat\MentionParser;
use App\Services\Chat\MultiAgentDispatcher;
use App\Support\Chat\ChatMessagePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChatController extends Controller
{
    public function __construct(
        private readonly AiProviderService $providers,
        private readonly MentionParser $mentions,
        private readonly MultiAgentDispatcher $multiAgent,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('chat/Index', [
            ...$this->sharedProps($request),
            'activeChat' => null,
        ]);
    }

    public function show(Request $request, Chat $chat): Response
    {
        $this->authorizeChat($request, $chat);

        return Inertia::render('chat/Index', [
            ...$this->sharedProps($request),
            'activeChat' => $this->presentChat($chat->load('messages.attachments', 'participants')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'content' => ['nullable', 'string', 'max:50000'],
            'model' => ['nullable', 'string', 'max:100'],
            'chat_project_id' => ['nullable', 'string'],
            'web_search' => ['nullable', 'boolean'],
            'tool_ids' => ['nullable', 'array', 'max:50'],
            'tool_ids.*' => ['string'],
            'mentioned_agent_ids' => ['nullable', 'array', 'max:20'],
            'mentioned_agent_ids.*' => ['string'],
        ]);

        // The picker sends either a model id or `agent:{id}`.
        $selection = $request->string('model')->toString() ?: null;
        $agentId = null;
        $model = null;
        if (is_string($selection) && str_starts_with($selection, 'agent:')) {
            $agent = Agent::query()->forAccountContext($user)->find(substr($selection, 6));
            if ($agent !== null) {
                $agentId = $agent->id;
                $model = $agent->model;
            }
        }
        if ($agentId === null) {
            $reachable = collect($this->providers->getEnabledChatModels())->pluck('value');
            $model = ($selection !== null && $reachable->contains($selection)) ? $selection : $reachable->first();
        }

        $toolIds = Tool::query()
            ->forAccountContext($user)
            ->whereIn('id', (array) $request->input('tool_ids', []))
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $chat = Chat::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => Visibility::Private,
            'model' => $model,
            'agent_id' => $agentId,
            'tool_ids' => $toolIds ?: null,
            'chat_project_id' => $request->string('chat_project_id')->toString() ?: null,
        ]);

        // Optional first turn — lets the empty-state composer create + send in
        // a single navigation. The chat.show page then streams the reply.
        $content = trim((string) $request->input('content', ''));
        if ($content !== '') {
            ChatMessage::create([
                'chat_id' => $chat->id,
                'role' => 'user',
                'content' => $content,
                'model' => $model,
                'status' => 'complete',
            ]);

            // First message may already @mention agents — run the multi-agent flow.
            $mentioned = $this->mentions->resolve($user, (array) $request->input('mentioned_agent_ids', []), $content);
            if ($mentioned['agents']->isNotEmpty()) {
                $this->multiAgent->dispatch($chat, $content, $mentioned['agents'], $mentioned['capped']);

                return to_route('chat.show', $chat);
            }

            $placeholder = ChatMessage::create([
                'chat_id' => $chat->id,
                'role' => 'assistant',
                'content' => null,
                'model' => $model,
                'status' => 'pending',
            ]);
            RunChatAiJob::dispatch($placeholder->id, $content, $model, $request->boolean('web_search'), $toolIds);
        }

        return to_route('chat.show', $chat);
    }

    public function rename(UpdateChatRequest $request, Chat $chat): RedirectResponse
    {
        $chat->update($request->validated());

        return back();
    }

    public function destroy(Request $request, Chat $chat): RedirectResponse
    {
        $this->authorizeChat($request, $chat);

        $chat->delete();

        return to_route('chat.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedProps(Request $request): array
    {
        $user = $request->user();

        $chats = Chat::query()
            ->where('user_id', $user->id)
            ->orderByRaw('last_message_at IS NULL, last_message_at DESC')
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'chat_project_id', 'last_message_at', 'created_at'])
            ->map(fn (Chat $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'chat_project_id' => $c->chat_project_id,
                'last_message_at' => ($c->last_message_at ?? $c->created_at)?->toIso8601String(),
            ]);

        $projects = ChatProject::query()
            ->where('user_id', $user->id)
            ->with('knowledgeBases:id')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'custom_instructions'])
            ->map(fn (ChatProject $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'custom_instructions' => $p->custom_instructions,
                'knowledge_base_ids' => $p->knowledgeBases->pluck('id')->all(),
            ]);

        $models = $this->providers->getEnabledChatModels($user);

        $knowledgeBases = KnowledgeBase::query()
            ->visibleTo($user)
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'document_count'])
            ->map(fn (KnowledgeBase $kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'status' => $kb->status instanceof \BackedEnum ? $kb->status->value : $kb->status,
                'document_count' => $kb->document_count,
            ]);

        $tools = Tool::query()
            ->forAccountContext($user)
            ->where('status', 'active')
            ->whereIn('type', ['rest_api', 'graphql', 'database', 'mcp'])
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (Tool $tool) => [
                'id' => $tool->id,
                'name' => $tool->name,
                'type' => $tool->type instanceof \BackedEnum ? $tool->type->value : $tool->type,
            ]);

        $agents = Agent::query()
            ->forAccountContext($user)
            ->standalone()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'web_search'])
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'type' => $agent->type instanceof \BackedEnum ? $agent->type->value : $agent->type,
                'web_search' => (bool) $agent->web_search,
            ]);

        return [
            'chats' => $chats,
            'projects' => $projects,
            'models' => $models,
            'defaultModel' => $models[0]['value'] ?? null,
            'knowledgeBases' => $knowledgeBases,
            'tools' => $tools,
            'agents' => $agents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentChat(Chat $chat): array
    {
        $agentMeta = ChatMessagePresenter::agentMetaFor($chat->messages);

        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'model' => $chat->model,
            'agent_id' => $chat->agent_id,
            'tool_ids' => $chat->tool_ids ?? [],
            'chat_project_id' => $chat->chat_project_id,
            'mode' => $chat->mode ?? 'single',
            'synthesis_status' => $chat->synthesis_status,
            'agents' => $this->presentRoster($chat),
            'messages' => $chat->messages
                ->map(fn ($m) => ChatMessagePresenter::present($m, $agentMeta))
                ->values(),
        ];
    }

    /**
     * The agents participating in a multi-agent thread (roster), for rendering
     * the @mention bubbles and the ActionCard's agreed-by avatars.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function presentRoster(Chat $chat): array
    {
        $agentIds = $chat->participants->pluck('agent_id')->all();
        if (empty($agentIds)) {
            return [];
        }

        return Agent::query()
            ->whereIn('id', $agentIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name])
            ->all();
    }

    private function authorizeChat(Request $request, Chat $chat): void
    {
        if ($chat->user_id !== $request->user()->id) {
            throw new NotFoundHttpException('Chat not found.');
        }
    }
}
