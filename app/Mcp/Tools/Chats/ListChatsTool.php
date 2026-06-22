<?php

namespace App\Mcp\Tools\Chats;

use App\Mcp\Tools\SapiensTool;
use App\Models\Chat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List chat conversations in your account, most recently active first — for QA, review, or to find a chat to retrieve/continue. Optional filters: a title search, a date range (on last activity), and an agent. Returns ids + metadata; call get_chat for the full transcript.')]
class ListChatsTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'string'],
            'to' => ['nullable', 'string'],
            'agent_id' => ['nullable', 'string'],
            'include_archived' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $from = $this->parseDate($validated['from'] ?? null);
        $to = $this->parseDate($validated['to'] ?? null);
        if (($validated['from'] ?? null) !== null && $from === null) {
            return Response::error("Could not parse 'from' as a date (use ISO, e.g. 2026-06-01).");
        }
        if (($validated['to'] ?? null) !== null && $to === null) {
            return Response::error("Could not parse 'to' as a date (use ISO, e.g. 2026-06-30).");
        }

        $limit = $validated['limit'] ?? 25;
        $offset = $validated['offset'] ?? 0;

        $base = Chat::query()
            ->forAccountContext($user)
            ->when(! ($validated['include_archived'] ?? false), fn ($q) => $q->whereNull('archived_at'))
            ->when($validated['agent_id'] ?? null, fn ($q, $id) => $q->where('agent_id', $id))
            ->when($validated['query'] ?? null, fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            // Date range applies to last activity, falling back to creation time.
            ->when($from, fn ($q, $d) => $q->where(fn ($w) => $w->where('last_message_at', '>=', $d)->orWhere(fn ($x) => $x->whereNull('last_message_at')->where('created_at', '>=', $d))))
            ->when($to, fn ($q, $d) => $q->where(fn ($w) => $w->where('last_message_at', '<=', $d)->orWhere(fn ($x) => $x->whereNull('last_message_at')->where('created_at', '<=', $d))));

        $total = (clone $base)->count();

        $chats = $base
            ->withCount('messages')
            ->orderByRaw('last_message_at IS NULL, last_message_at DESC')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return Response::json([
            'chats' => $chats->map(fn (Chat $c): array => [
                'chat_id' => $c->id,
                'title' => $c->title,
                'model' => $c->model,
                'agent_id' => $c->agent_id,
                'mode' => $c->mode,
                'message_count' => $c->messages_count,
                'archived' => $c->archived_at !== null,
                'created_at' => $c->created_at?->toIso8601String(),
                'last_message_at' => $c->last_message_at?->toIso8601String(),
            ])->values(),
            'total' => $total,
            'returned' => $chats->count(),
            'has_more' => ($offset + $chats->count()) < $total,
        ]);
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Optional case-insensitive search over chat titles.'),
            'from' => $schema->string()->description('Optional start of the date range (ISO, e.g. 2026-06-01) — matches last activity.'),
            'to' => $schema->string()->description('Optional end of the date range (ISO).'),
            'agent_id' => $schema->string()->description('Optional. Only chats bound to this agent.'),
            'include_archived' => $schema->boolean()->description('Include archived chats (default false).'),
            'limit' => $schema->integer()->description('Max chats to return (default 25, max 100).'),
            'offset' => $schema->integer()->description('Offset for pagination (default 0).'),
        ];
    }
}
