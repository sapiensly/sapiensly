<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\SapiensTool;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the external integrations provisioned in your account (REST/GraphQL/database/MCP), with their auth type and status.')]
class ListIntegrationsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $integrations = Integration::query()->forAccountContext($user)->orderBy('name')->get();

        return Response::json([
            'integrations' => $integrations->map(fn (Integration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'slug' => $i->slug,
                'kind' => $i->kind?->value,
                'status' => $i->status,
                'auth_type' => $i->auth_type?->value,
                'is_mcp' => $i->is_mcp,
                'base_url' => $i->base_url,
                'requests_count' => $i->requests()->count(),
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
