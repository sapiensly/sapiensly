<?php

namespace App\Mcp\Tools\Agents;

use App\Enums\AgentStatus;
use App\Mcp\Tools\Agents\Concerns\PresentsAgent;
use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update an existing agent. Only the fields you pass are changed (partial update) — e.g. pass just status="active" to publish it, or just model to switch the model. Use list_agent_models for valid model ids. Pass an empty knowledge_base_ids/tool_ids array to detach all.')]
class UpdateAgentTool extends SapiensTool
{
    use PresentsAgent;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $agentId = $request->validate([
            'agent_id' => ['required', 'string'],
        ])['agent_id'];

        try {
            $agent = Agent::query()->forAccountContext($user)->findOrFail($agentId);
        } catch (ModelNotFoundException) {
            return Response::error("No agent '{$agentId}' is visible to you.");
        }

        if (! $user->can('update', $agent)) {
            return Response::error('You do not have permission to update this agent.');
        }

        $validated = $request->validate([
            'agent_id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'status' => ['sometimes', Rule::enum(AgentStatus::class)],
            'prompt_template' => ['nullable', 'string'],
            'model' => $this->chatModelRule($user, required: false),
            'web_search' => ['sometimes', 'boolean'],
            'reasoning' => ['sometimes', 'in:default,off,low,medium,high'],
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['string', 'exists:tenant.knowledge_bases,id'],
            'tool_ids' => ['sometimes', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
            ...$this->configRules(),
        ]);

        // Partial update: only the attributes actually supplied are touched, so
        // an unset field keeps its current value rather than being nulled.
        $attributes = [];
        foreach (['name', 'description', 'keywords', 'status', 'prompt_template', 'model', 'web_search', 'reasoning', 'config'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if ($attributes !== []) {
            $agent->update($attributes);
        }

        if (array_key_exists('knowledge_base_ids', $validated)) {
            $agent->syncKnowledgeBases($validated['knowledge_base_ids'] ?? []);
        }

        if (array_key_exists('tool_ids', $validated)) {
            $agent->tools()->sync($validated['tool_ids'] ?? []);
        }

        return Response::json($this->agentPayload($agent->refresh()));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The id of the agent to update.')->required(),
            'name' => $schema->string()->description('New agent name.'),
            'description' => $schema->string()->description('New description.'),
            'keywords' => $schema->array()->description('Replace the keyword list.'),
            'status' => $schema->string()->enum(array_column(AgentStatus::cases(), 'value'))->description('draft, active, or inactive.'),
            'prompt_template' => $schema->string()->description('Replace the system prompt / instructions.'),
            'model' => $schema->string()->description('A model id from list_agent_models.'),
            'web_search' => $schema->boolean()->description('Enable or disable web search.'),
            'reasoning' => $schema->string()->enum(['default', 'off', 'low', 'medium', 'high'])->description('Reasoning/thinking effort: off (platform default), default (model\'s own), or low/medium/high. Applies to OpenRouter and OpenAI models.'),
            'knowledge_base_ids' => $schema->array()->description('Replace the attached knowledge bases (empty array detaches all).'),
            'tool_ids' => $schema->array()->description('Replace the attached tools (empty array detaches all).'),
            'config' => $schema->object()->description('Replace tuning: { temperature, rag_params, tool_execution }.'),
        ];
    }
}
