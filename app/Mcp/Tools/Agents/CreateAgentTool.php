<?php

namespace App\Mcp\Tools\Agents;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\Visibility;
use App\Mcp\Tools\Agents\Concerns\PresentsAgent;
use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a new Sapiensly agent. Pick a type and a model id from list_agent_models; attach knowledge bases (list_knowledge_bases) and tools (list_tools) by id. The agent is created as a draft — update_agent can activate it.')]
class CreateAgentTool extends SapiensTool
{
    use PresentsAgent;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('create', Agent::class)) {
            return Response::error('You do not have permission to create agents.');
        }

        $validated = $request->validate([
            'type' => ['required', Rule::enum(AgentType::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'prompt_template' => ['nullable', 'string'],
            'model' => $this->chatModelRule($user, required: true),
            'web_search' => ['nullable', 'boolean'],
            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['string', 'exists:tenant.knowledge_bases,id'],
            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
            ...$this->configRules(),
        ]);

        $agent = Agent::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'type' => $validated['type'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'keywords' => $validated['keywords'] ?? [],
            'status' => AgentStatus::Draft,
            'prompt_template' => $validated['prompt_template'] ?? null,
            'model' => $validated['model'],
            'config' => $validated['config'] ?? [],
            'web_search' => (bool) ($validated['web_search'] ?? false),
        ]);

        if (array_key_exists('knowledge_base_ids', $validated)) {
            $agent->syncKnowledgeBases($validated['knowledge_base_ids'] ?? []);
        }

        if (array_key_exists('tool_ids', $validated)) {
            $agent->tools()->sync($validated['tool_ids'] ?? []);
        }

        return Response::json($this->agentPayload($agent));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_column(AgentType::cases(), 'value'))->description('Agent type: general (triage + knowledge + action in one), triage, knowledge, or action.')->required(),
            'name' => $schema->string()->description('The agent name.')->required(),
            'model' => $schema->string()->description('A model id from list_agent_models (e.g. "deepseek/deepseek-v4-pro").')->required(),
            'description' => $schema->string()->description('Short description of what the agent does.'),
            'keywords' => $schema->array()->description('Optional keywords for search/categorization.'),
            'prompt_template' => $schema->string()->description('The system prompt / instructions for the agent.'),
            'web_search' => $schema->boolean()->description('Whether the agent may use web search (default false).'),
            'knowledge_base_ids' => $schema->array()->description('Knowledge base ids to attach (from list_knowledge_bases).'),
            'tool_ids' => $schema->array()->description('Tool ids to attach (from list_tools).'),
            'config' => $schema->object()->description('Optional tuning: { temperature, rag_params: { chunk_size, top_k, similarity_threshold }, tool_execution: { timeout, retry_count }, web_search: { max_results } (1-10; applies when web_search is enabled) }.'),
        ];
    }
}
