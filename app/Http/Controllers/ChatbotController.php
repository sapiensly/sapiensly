<?php

namespace App\Http\Controllers;

use App\Enums\ChatbotStatus;
use App\Enums\Visibility;
use App\Http\Requests\Chatbot\StoreChatbotRequest;
use App\Http\Requests\Chatbot\UpdateChatbotRequest;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\WidgetConversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatbotController extends Controller
{
    public function index(Request $request): Response
    {
        $chatbots = Chatbot::query()
            ->forAccountContext($request->user())
            ->with(['agent:id,name,type', 'agentTeam:id,name'])
            ->withCount(['conversations', 'sessions'])
            ->latest()
            ->paginate(12);

        return Inertia::render('chatbots/Index', [
            'chatbots' => $chatbots,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('chatbots/Create', [
            'agents' => Agent::forAccountContext($user)
                ->get(['id', 'name', 'type', 'status']),
            'agentTeams' => AgentTeam::forAccountContext($user)
                ->get(['id', 'name', 'status']),
            'defaultConfig' => Chatbot::getDefaultConfig(),
            'visibilityOptions' => collect(Visibility::cases())->map(fn ($v) => [
                'value' => $v->value,
                'label' => $v->label(),
                'description' => $v->description(),
            ])->values()->all(),
            'canShareWithOrg' => $user->hasOrganization(),
        ]);
    }

    public function store(StoreChatbotRequest $request): RedirectResponse
    {
        $user = $request->user();

        $chatbot = Chatbot::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'agent_id' => $request->agent_id,
            'agent_team_id' => $request->agent_team_id,
            'name' => $request->name,
            'description' => $request->description,
            'config' => $request->config ?? Chatbot::getDefaultConfig(),
            'allowed_origins' => $request->allowed_origins,
            'status' => ChatbotStatus::Draft,
        ]);

        // Create default API token
        ChatbotApiToken::create([
            'chatbot_id' => $chatbot->id,
            'name' => 'Default Token',
            'token' => ChatbotApiToken::generateToken(),
            'abilities' => ['chat', 'feedback'],
        ]);

        return to_route('chatbots.show', $chatbot);
    }

    public function show(Request $request, Chatbot $chatbot): Response
    {
        if (! $chatbot->isVisibleTo($request->user())) {
            abort(403);
        }

        $recentConversations = $chatbot->conversations()
            ->with('session:id,visitor_email,visitor_name')
            ->latest()
            ->limit(5)
            ->get();

        // Quick stats
        $stats = [
            'total_conversations' => $chatbot->conversations()->count(),
            'total_sessions' => $chatbot->sessions()->count(),
            'avg_rating' => $chatbot->conversations()->whereNotNull('rating')->avg('rating'),
            'resolution_rate' => $this->calculateResolutionRate($chatbot),
        ];

        return Inertia::render('chatbots/Show', [
            'chatbot' => $chatbot->load(['agent', 'agentTeam']),
            'recentConversations' => $recentConversations,
            'stats' => $stats,
        ]);
    }

    public function edit(Request $request, Chatbot $chatbot): Response
    {
        if (! $chatbot->isOwnedBy($request->user())) {
            abort(403);
        }

        $user = $request->user();

        return Inertia::render('chatbots/Edit', [
            'chatbot' => $chatbot->load(['agent', 'agentTeam']),
            'agents' => Agent::forAccountContext($user)
                ->get(['id', 'name', 'type', 'status']),
            'agentTeams' => AgentTeam::forAccountContext($user)
                ->get(['id', 'name', 'status']),
            'visibilityOptions' => collect(Visibility::cases())->map(fn ($v) => [
                'value' => $v->value,
                'label' => $v->label(),
                'description' => $v->description(),
            ])->values()->all(),
            'canShareWithOrg' => $user->hasOrganization(),
        ]);
    }

    public function update(UpdateChatbotRequest $request, Chatbot $chatbot): RedirectResponse
    {
        if (! $chatbot->isOwnedBy($request->user())) {
            abort(403);
        }

        $chatbot->update([
            'name' => $request->name,
            'description' => $request->description,
            'agent_id' => $request->agent_id,
            'agent_team_id' => $request->agent_team_id,
            'config' => $request->config ?? $chatbot->config,
            'allowed_origins' => $request->allowed_origins,
            'status' => $request->status ?? $chatbot->status,
        ]);

        // Handle visibility update
        if ($request->has('visibility')) {
            $chatbot->updateVisibility(
                Visibility::from($request->visibility),
                $request->user()
            );
        }

        return to_route('chatbots.show', $chatbot);
    }

    public function destroy(Request $request, Chatbot $chatbot): RedirectResponse
    {
        if (! $chatbot->isOwnedBy($request->user())) {
            abort(403);
        }

        $chatbot->delete();

        return to_route('chatbots.index');
    }

    public function embed(Request $request, Chatbot $chatbot): Response
    {
        if (! $chatbot->isVisibleTo($request->user())) {
            abort(403);
        }

        // Get or create default API token
        $token = $chatbot->apiTokens()->first();

        if (! $token) {
            $token = ChatbotApiToken::create([
                'chatbot_id' => $chatbot->id,
                'name' => 'Default Token',
                'token' => ChatbotApiToken::generateToken(),
                'abilities' => ['chat', 'feedback'],
            ]);
        }

        $embedCode = $this->generateEmbedCode($chatbot, $token);

        // Make tokens visible with masked token for display
        $apiTokens = $chatbot->apiTokens->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'token' => substr($token->token, 0, 12).'...',
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'expires_at' => $token->expires_at?->toISOString(),
                'created_at' => $token->created_at->toISOString(),
            ];
        });

        return Inertia::render('chatbots/Embed', [
            'chatbot' => $chatbot,
            'embedCode' => $embedCode,
            'apiTokens' => $apiTokens,
        ]);
    }

    public function preview(Request $request, Chatbot $chatbot): Response
    {
        if (! $chatbot->isVisibleTo($request->user())) {
            abort(403);
        }

        return Inertia::render('chatbots/Preview', [
            'chatbot' => $chatbot->load(['agent', 'agentTeam']),
        ]);
    }

    public function conversations(Request $request, Chatbot $chatbot): Response
    {
        if (! $chatbot->isVisibleTo($request->user())) {
            abort(403);
        }

        $conversations = $chatbot->conversations()
            ->with('session:id,visitor_email,visitor_name,last_activity_at')
            ->withCount('messages')
            ->latest()
            ->paginate(20);

        return Inertia::render('chatbots/Conversations', [
            'chatbot' => $chatbot,
            'conversations' => $conversations,
        ]);
    }

    public function conversation(Request $request, Chatbot $chatbot, WidgetConversation $conversation): Response
    {
        if (! $chatbot->isVisibleTo($request->user())) {
            abort(403);
        }

        if ($conversation->chatbot_id !== $chatbot->id) {
            abort(404);
        }

        return Inertia::render('chatbots/Conversation', [
            'chatbot' => $chatbot,
            'conversation' => $conversation->load(['session', 'messages']),
        ]);
    }

    private function generateEmbedCode(Chatbot $chatbot, ChatbotApiToken $token): string
    {
        $baseUrl = config('app.url');

        return <<<HTML
<!-- Sapiensly Widget -->
<script>
  (function(w,d,s,o,f,js,fjs){
    w['SapienslyWidget']=o;w[o]=w[o]||function(){
    (w[o].q=w[o].q||[]).push(arguments)};
    js=d.createElement(s),fjs=d.getElementsByTagName(s)[0];
    js.id=o;js.src=f;js.async=1;fjs.parentNode.insertBefore(js,fjs);
  }(window,document,'script','sapiensly','{$baseUrl}/widget/v1/widget.js'));
  sapiensly('init', '{$token->token}');
</script>
<!-- End Sapiensly Widget -->
HTML;
    }

    private function calculateResolutionRate(Chatbot $chatbot): float
    {
        $total = $chatbot->conversations()->count();

        if ($total === 0) {
            return 0;
        }

        $resolved = $chatbot->conversations()->where('is_resolved', true)->count();

        return round(($resolved / $total) * 100, 1);
    }
}
