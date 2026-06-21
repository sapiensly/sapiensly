<?php

namespace App\Mcp\Tools\Agents;

use App\Enums\MessageRole;
use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\LLMService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Send a message to a Sapiensly agent and get its reply (synchronous). Pass conversation_id to continue an existing thread with full memory of prior turns; omit it to start a new one. The returned conversation_id is what you pass back on the next call. Use list_agents to find an agent id.')]
class InvokeAgentTool extends SapiensTool
{
    protected const ABILITY = 'agents:invoke';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:8000'],
            'conversation_id' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $agent = Agent::query()->forAccountContext($user)->findOrFail($validated['agent_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No agent '{$validated['agent_id']}' is visible to you.");
        }

        $conversation = $this->resolveConversation($validated['conversation_id'] ?? null, $agent, $user);

        if ($conversation === null) {
            return Response::error("No conversation '{$validated['conversation_id']}' with this agent is visible to you.");
        }

        $userMessage = new Message([
            'role' => MessageRole::User,
            'content' => $validated['message'],
        ]);

        $history = $conversation->messages()->get()->all();

        try {
            $reply = app(LLMService::class)
                ->setContext($user)
                ->chat($agent, [...$history, $userMessage]);
        } catch (\Throwable $e) {
            return Response::error('The agent could not respond: '.$e->getMessage());
        }

        $this->persistTurn($conversation, $validated['message'], $reply, $agent);

        return Response::json([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'reply' => $reply,
        ]);
    }

    /**
     * Load the requested conversation (scoped to this user + agent) or, when no
     * id is given, start a fresh one. Returns null when an id is supplied but no
     * matching conversation is visible.
     */
    protected function resolveConversation(?string $conversationId, Agent $agent, User $user): ?Conversation
    {
        if ($conversationId === null || $conversationId === '') {
            return Conversation::create([
                'user_id' => $user->id,
                'agent_id' => $agent->id,
                'title' => null,
            ]);
        }

        return Conversation::query()
            ->where('user_id', $user->id)
            ->where('agent_id', $agent->id)
            ->find($conversationId);
    }

    /**
     * Store the user message and the agent reply, and title the conversation
     * from its opening message the first time around.
     */
    protected function persistTurn(Conversation $conversation, string $message, string $reply, Agent $agent): void
    {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $message,
        ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $reply,
            'model' => $agent->model,
        ]);

        if ($conversation->title === null) {
            $conversation->update(['title' => Str::limit($message, 60)]);
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The id of the agent to message.')->required(),
            'message' => $schema->string()->description('The user message to send to the agent.')->required(),
            'conversation_id' => $schema->string()->description('Optional. The id returned by a previous invoke_agent call. Pass it to continue that conversation with full memory; omit to start a new one.'),
        ];
    }
}
