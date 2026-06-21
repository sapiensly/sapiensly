<?php

namespace App\Mcp\Tools\Integrations;

use App\Enums\AgentStatus;
use App\Mcp\Tools\Integrations\Concerns\PresentsTool;
use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use App\Services\ToolConfigService;
use App\Support\Tools\ToolConfigRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update a tool. Only the fields you pass change (partial update) — e.g. status="active" to publish, or a full replacement config. The type is immutable. If you pass config you must pass a complete, valid config for the tool type; omit it to leave config untouched. Empty/omitted secret fields keep their stored value.')]
class UpdateToolTool extends SapiensTool
{
    use PresentsTool;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $toolId = $request->validate(['tool_id' => ['required', 'string']])['tool_id'];

        try {
            $tool = Tool::query()->forAccountContext($user)->findOrFail($toolId);
        } catch (ModelNotFoundException) {
            return Response::error("No tool '{$toolId}' is visible to you.");
        }

        if (! $user->can('update', $tool)) {
            return Response::error('You do not have permission to update this tool.');
        }

        // Type-specific config rules only apply when a config is actually
        // supplied — otherwise their "required" fields would reject an update
        // that doesn't touch config at all.
        $configRules = $request->get('config') !== null
            ? ToolConfigRules::forType($tool->type?->value, $user)
            : [];

        $validated = $request->validate([
            'tool_id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(AgentStatus::class)],
            'config' => ['sometimes', 'array'],
            'tool_ids' => ['sometimes', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
            ...$configRules,
        ]);

        $attributes = [];
        foreach (['name', 'description', 'status'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if (array_key_exists('config', $validated)) {
            $configService = app(ToolConfigService::class);
            $config = $validated['config'] ?? [];
            $attributes['config'] = $configService->hasSensitiveFields($tool->type)
                ? $this->mergeAndEncryptConfig($tool, $config, $configService)
                : $config;
        }

        if ($attributes !== []) {
            $tool->update($attributes);
        }

        if ($tool->type?->value === 'group' && array_key_exists('tool_ids', $validated)) {
            $tool->groupItems()->delete();
            foreach (array_values($validated['tool_ids'] ?? []) as $index => $toolId) {
                $tool->groupItems()->create(['tool_id' => $toolId, 'order' => $index]);
            }
        }

        return Response::json($this->toolPayload($tool->refresh()));
    }

    /**
     * Merge the new config with the stored one (keeping existing encrypted
     * secrets when the new value is empty) and encrypt sensitive fields, the
     * same way the web controller does.
     *
     * @param  array<string, mixed>  $newConfig
     * @return array<string, mixed>
     */
    private function mergeAndEncryptConfig(Tool $tool, array $newConfig, ToolConfigService $configService): array
    {
        $existing = $tool->config ?? [];
        $encryptedFields = match ($tool->type?->value) {
            'rest_api', 'graphql', 'mcp' => ['auth_config'],
            'database' => ['username', 'password'],
            default => [],
        };

        foreach ($encryptedFields as $field) {
            if (empty($newConfig[$field]) && ! empty($existing[$field])) {
                $newConfig[$field] = $existing[$field];
            }
        }

        return $configService->encryptConfig($tool->type, $newConfig);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()->description('The id of the tool to update.')->required(),
            'name' => $schema->string()->description('New tool name.'),
            'description' => $schema->string()->description('New description.'),
            'status' => $schema->string()->enum(array_column(AgentStatus::cases(), 'value'))->description('draft, active, or inactive.'),
            'config' => $schema->object()->description('Complete replacement config for the tool type (empty secret fields keep their stored value).'),
            'tool_ids' => $schema->array()->description('For type=group: replace the member tool ids, in order.'),
        ];
    }
}
