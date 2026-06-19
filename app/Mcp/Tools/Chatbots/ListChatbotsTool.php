<?php

namespace App\Mcp\Tools\Chatbots;

use App\Mcp\Tools\SapiensTool;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List your chatbots, with their id, name, status and whether they have a bot flow.')]
class ListChatbotsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $chatbots = Chatbot::query()->forAccountContext($user)->with('botFlow:id,chatbot_id')->get();

        return Response::json([
            'chatbots' => $chatbots->map(fn (Chatbot $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'status' => $c->status?->value,
                'has_flow' => $c->botFlow !== null,
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
