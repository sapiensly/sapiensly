<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\BuilderConversation;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("List an app's builder chat conversations (the AI app-builder sessions), most recently active first — to debug what was asked/built or to find a conversation to inspect or continue. Returns ids + metadata; call get_builder_conversation for the full transcript.")]
class ListBuilderConversationsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'status' => ['nullable', 'string', 'in:active,archived,all'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $status = $validated['status'] ?? 'all';
        $limit = $validated['limit'] ?? 25;
        $offset = $validated['offset'] ?? 0;

        $base = BuilderConversation::query()
            ->where('app_id', $app->id)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status));

        $total = (clone $base)->count();

        $conversations = $base
            ->withCount('messages')
            ->withMax('messages as last_activity_at', 'created_at')
            ->orderByRaw('last_activity_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return Response::json([
            'app_slug' => $app->slug,
            'conversations' => $conversations->map(fn (BuilderConversation $c): array => [
                'conversation_id' => $c->id,
                'status' => $c->status,
                'user_id' => $c->user_id,
                'message_count' => $c->messages_count,
                'created_at' => $c->created_at?->toIso8601String(),
                'last_activity_at' => $c->last_activity_at
                    ? Carbon::parse($c->last_activity_at)->toIso8601String()
                    : null,
            ])->values(),
            'total' => $total,
            'returned' => $conversations->count(),
            'has_more' => ($offset + $conversations->count()) < $total,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('The slug of the app whose builder conversations to list.')
                ->required(),
            'status' => $schema->string()->enum(['active', 'archived', 'all'])
                ->description('Filter by conversation status (default all).'),
            'limit' => $schema->integer()->description('Max conversations to return (default 25, max 100).'),
            'offset' => $schema->integer()->description('Offset for pagination (default 0).'),
        ];
    }
}
