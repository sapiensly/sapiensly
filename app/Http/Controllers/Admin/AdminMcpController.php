<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Services\Platform\PlatformAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform MCP access — the sysadmin credential, and only that.
 *
 * A token issued here is GLOBAL: no organization, no other abilities, no
 * choices to get wrong. It carries `platform:admin` and nothing else, and it
 * connects at `mcp/platform/v1`, which has no organization in its URL because
 * administering the platform does not happen inside a tenant.
 *
 * The per-organization screen (System → MCP) issues tenant tokens and cannot
 * grant this ability at all.
 */
class AdminMcpController extends Controller
{
    /**
     * The MCP server name suggested in the `claude mcp add` line. Claude Code
     * keys connections by name and an operator normally already has `sapiensly`
     * (and one per organization) configured; reusing the name would add a
     * root-equivalent credential over an existing tenant connection, silently.
     */
    private const SERVER_NAME = 'sapiensly-sysadmin';

    public function index(Request $request): Response
    {
        return Inertia::render('admin/Mcp', [
            'serverUrl' => url('mcp/platform/v1'),
            'serverName' => self::SERVER_NAME,
            'tokens' => $this->tokens(),
            'justCreatedToken' => $request->session()->get('plain_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $plain = McpAccessToken::generateToken();

        $token = McpAccessToken::create([
            'user_id' => $user->id,
            // Null on purpose: this credential is not bound to a tenant.
            'organization_id' => null,
            'name' => $validated['name'],
            'token' => $plain,
            'abilities' => [McpAccessToken::PLATFORM_ADMIN],
        ]);

        app(PlatformAudit::class)->record(
            action: 'issue_platform_token',
            actor: $user,
            targetType: 'mcp_access_token',
            targetId: $token->id,
            targetLabel: $token->name,
            summary: "Issued the platform-admin MCP token '{$token->name}'",
            channel: 'web',
        );

        return back()->with('plain_token', $plain);
    }

    public function destroy(Request $request, McpAccessToken $mcpToken): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $snapshot = [
            'name' => $mcpToken->name,
            'owner' => $mcpToken->user?->email,
            'abilities' => $mcpToken->abilities,
        ];
        $organizationId = $mcpToken->organization_id;
        $id = $mcpToken->id;

        $mcpToken->delete();

        app(PlatformAudit::class)->record(
            action: 'revoke_mcp_token',
            actor: $user,
            organizationId: $organizationId,
            targetType: 'mcp_access_token',
            targetId: $id,
            targetLabel: $snapshot['name'],
            summary: "Revoked MCP token '{$snapshot['name']}' owned by ".($snapshot['owner'] ?? 'unknown'),
            meta: $snapshot,
            channel: 'web',
        );

        return back()->with('success', __('MCP token revoked.'));
    }

    /**
     * Every MCP token on the platform, newest first — the same inventory the
     * `list_mcp_tokens` tool returns, so a stale credential is visible from
     * either surface. Tenant tokens appear here too: this is the screen that
     * can see all of them, and revoking one is a sysadmin's job.
     *
     * @return list<array<string, mixed>>
     */
    private function tokens(): array
    {
        return McpAccessToken::query()
            ->with(['user:id,name,email', 'organization:id,name,slug'])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (McpAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'masked' => substr($token->token, 0, 8).'…',
                'abilities' => $token->abilities ?? [],
                'isPlatform' => in_array(McpAccessToken::PLATFORM_ADMIN, $token->abilities ?? [], true),
                'owner' => $token->user?->name ?? $token->user?->email,
                'organization' => $token->organization?->name,
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'createdAt' => $token->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
