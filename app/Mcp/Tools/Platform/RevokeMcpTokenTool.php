<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\McpContext;
use App\Mcp\Tools\SysadminTool;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Revoke an MCP access token by its id. The credential stops working on the next call — anything using it (a Claude Code session, an automation) loses access immediately and cannot be restored; a replacement has to be issued in the app. Find ids with list_mcp_tokens. Revoking the token that authenticated THIS call is refused, since it would cut the connection mid-session. Audited.')]
class RevokeMcpTokenTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'token_id' => ['required', 'string', 'max:60'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $token = McpAccessToken::query()
            ->with(['user:id,name,email', 'organization:id,name,slug'])
            ->find($validated['token_id']);

        if ($token === null) {
            return Response::error("No MCP token with id '{$validated['token_id']}'. Find ids with list_mcp_tokens.");
        }

        $current = app(McpContext::class)->token;

        if ($current !== null && $current->id === $token->id) {
            return Response::error(
                'Refused: that is the token authenticating this very connection. '
                .'Revoking it would end the session mid-call — do it from the app, or revoke a different token.'
            );
        }

        $summary = sprintf(
            "Revoked MCP token '%s' owned by %s (org %s)",
            $token->name,
            $token->user?->email ?? 'unknown',
            $token->organization?->slug ?? 'unknown',
        );

        $snapshot = [
            'id' => $token->id,
            'name' => $token->name,
            'owner' => $token->user?->email,
            'organization' => $token->organization?->slug,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
        ];

        $token->delete();

        $this->audit(
            actor: $actor,
            summary: $summary,
            meta: $snapshot,
            organizationId: $token->organization_id,
            targetType: 'mcp_access_token',
            targetId: $snapshot['id'],
            targetLabel: $snapshot['name'],
        );

        return Response::json([
            'revoked' => $snapshot,
            'note' => 'Effective immediately. Issue a replacement from System → MCP in the app; no tool can mint one.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'token_id' => $schema->string()->description('The token id (mcp_…) from list_mcp_tokens.')->required(),
        ];
    }
}
