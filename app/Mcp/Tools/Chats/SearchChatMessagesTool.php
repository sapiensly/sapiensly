<?php

namespace App\Mcp\Tools\Chats;

use App\Mcp\Tools\SapiensTool;
use App\Models\ChatMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Full-text search across chat MESSAGES in your account (content contains the query), newest first — for QA like "find chats where the bot mentioned refunds". Optional date range and role filter. Returns matching messages with their chat_id and a snippet; call get_chat for the surrounding transcript.')]
class SearchChatMessagesTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    private const SNIPPET_LENGTH = 240;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'from' => ['nullable', 'string'],
            'to' => ['nullable', 'string'],
            'role' => ['nullable', 'string', 'in:user,assistant'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $from = $this->parseDate($validated['from'] ?? null);
        $to = $this->parseDate($validated['to'] ?? null);
        if (($validated['from'] ?? null) !== null && $from === null) {
            return Response::error("Could not parse 'from' as a date (use ISO, e.g. 2026-06-01).");
        }
        if (($validated['to'] ?? null) !== null && $to === null) {
            return Response::error("Could not parse 'to' as a date (use ISO).");
        }

        $term = $validated['query'];

        // RLS already scopes ChatMessage to the tenant; join the chat for title +
        // a belongs-to-me guard via the visible chat ids.
        $messages = ChatMessage::query()
            ->whereHas('chat', fn ($q) => $q->forAccountContext($user))
            ->where('content', 'like', '%'.$term.'%')
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($from, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('created_at', '<=', $d))
            ->with('chat:id,title')
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 25)
            ->get();

        return Response::json([
            'matches' => $messages->map(fn (ChatMessage $m): array => [
                'chat_id' => $m->chat_id,
                'chat_title' => $m->chat?->title,
                'message_id' => $m->id,
                'role' => $m->role,
                'snippet' => $this->snippet((string) $m->content, $term),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'returned' => $messages->count(),
        ]);
    }

    /**
     * A window of text around the first match, so the caller sees context.
     */
    private function snippet(string $content, string $term): string
    {
        $pos = mb_stripos($content, $term);
        if ($pos === false) {
            return mb_substr($content, 0, self::SNIPPET_LENGTH);
        }

        $start = max(0, $pos - 60);
        $snippet = mb_substr($content, $start, self::SNIPPET_LENGTH);

        return ($start > 0 ? '…' : '').$snippet.(mb_strlen($content) > $start + self::SNIPPET_LENGTH ? '…' : '');
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
            'query' => $schema->string()->description('Text to find inside message content (case-insensitive substring).')->required(),
            'from' => $schema->string()->description('Optional start of the date range (ISO) on message time.'),
            'to' => $schema->string()->description('Optional end of the date range (ISO).'),
            'role' => $schema->string()->enum(['user', 'assistant'])->description('Optional. Only match user or assistant messages.'),
            'limit' => $schema->integer()->description('Max matches to return (default 25, max 100).'),
        ];
    }
}
