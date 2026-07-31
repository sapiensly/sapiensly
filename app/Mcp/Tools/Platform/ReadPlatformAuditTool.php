<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\PlatformAuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Who changed what on this platform. Every administrative write — accounts blocked or deleted, organizations created or suspended, access policy flipped, provider keys rotated, models toggled, maintenance run — leaves a row here with the actor, the target, a summary and the sanitized arguments (never the secret itself). Filter by action, actor email, organization or a date range. Read-only, and nothing in the suite can edit or delete these rows.')]
class ReadPlatformAuditTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:80'],
            'actor_email' => ['sometimes', 'nullable', 'string', 'max:320'],
            'organization_id' => ['sometimes', 'nullable', 'string', 'max:60'],
            'target_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'since' => ['sometimes', 'nullable', 'date'],
            'until' => ['sometimes', 'nullable', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = PlatformAuditLog::query()->with('actor:id,name,email');

        if (! empty($validated['action'])) {
            $query->where('action', 'like', '%'.$validated['action'].'%');
        }
        if (! empty($validated['actor_email'])) {
            $query->whereRaw('lower(actor_email) = ?', [strtolower($validated['actor_email'])]);
        }
        if (! empty($validated['organization_id'])) {
            $query->where('organization_id', $validated['organization_id']);
        }
        if (! empty($validated['target_id'])) {
            $query->where('target_id', $validated['target_id']);
        }
        if (! empty($validated['since'])) {
            $query->where('created_at', '>=', $validated['since']);
        }
        if (! empty($validated['until'])) {
            $query->where('created_at', '<=', $validated['until']);
        }

        $total = (clone $query)->count();

        $entries = $query
            ->latest('created_at')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return Response::json([
            'total_matching' => $total,
            'returned' => $entries->count(),
            'entries' => $entries->map(fn (PlatformAuditLog $entry) => [
                'id' => $entry->id,
                'at' => $entry->created_at?->toIso8601String(),
                'action' => $entry->action,
                'result' => $entry->result,
                'actor' => [
                    'user_id' => $entry->actor_user_id,
                    'email' => $entry->actor_email,
                    'name' => $entry->actor?->name,
                ],
                'organization_id' => $entry->organization_id,
                'target' => array_filter([
                    'type' => $entry->target_type,
                    'id' => $entry->target_id,
                    'label' => $entry->target_label,
                ], fn ($value) => $value !== null),
                'summary' => $entry->summary,
                'meta' => $entry->meta,
                'channel' => $entry->channel,
                'ip' => $entry->ip,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('Only this action (partial match), e.g. "manage_platform_user" or "provider".'),
            'actor_email' => $schema->string()->description('Only writes performed by this person.'),
            'organization_id' => $schema->string()->description('Only writes affecting this organization.'),
            'target_id' => $schema->string()->description('Only writes against this target id — the history of one user, org or model.'),
            'since' => $schema->string()->description('ISO date/time lower bound.'),
            'until' => $schema->string()->description('ISO date/time upper bound.'),
            'limit' => $schema->integer()->description('How many entries, newest first. 1-200, default 50.'),
        ];
    }
}
