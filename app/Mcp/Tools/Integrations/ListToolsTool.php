<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the tools (connector operations) in your account, with their type, effect (read/write) and whether they are safe to auto-run.')]
class ListToolsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $tools = Tool::query()->forAccountContext($user)->orderBy('name')->get();

        return Response::json([
            'tools' => $tools->map(fn (Tool $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'type' => $t->type?->value,
                'effect' => $t->effect?->value,
                'safe' => $t->safe,
                'status' => $t->status?->value,
                'integration_id' => $t->config['integration_id'] ?? null,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
