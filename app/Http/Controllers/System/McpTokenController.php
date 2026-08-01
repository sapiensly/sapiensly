<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Admin\AdminMcpController;
use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-managed MCP access for an organization. Tokens authenticate a user but
 * are bound to THIS organization (and used against its per-org MCP URL); the raw
 * token is shown once on creation. Mirrors the SSO settings surface — every
 * action is gated by OrganizationPolicy@manageMcp.
 */
class McpTokenController extends Controller
{
    /**
     * What this screen may grant: the tenant abilities, and only those.
     *
     * `platform:admin` is issued from the sysadmin console (Admin → MCP,
     * {@see AdminMcpController}). Not because a
     * sysadmin could not be trusted with the checkbox, but because a
     * credential that administers every organization does not belong on a
     * screen whose whole frame says "this organization" — the surrounding
     * context would misdescribe what the token does.
     *
     * @var list<string>
     */
    private const TENANT_ABILITIES = ['apps:build', 'data:read', 'data:write', 'agents:invoke'];

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->organization_id === null) {
            return to_route('organization.create');
        }

        $organization = $user->organization;
        $this->authorize('manageMcp', $organization);

        return Inertia::render('system/McpTokens', [
            'tokens' => McpAccessToken::query()
                ->where('organization_id', $organization->id)
                ->with('user:id,name')
                ->latest()
                ->get()
                ->map(fn (McpAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'masked' => substr($token->token, 0, 8).'…',
                    'abilities' => $token->abilities,
                    'created_by' => $token->user?->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at->toIso8601String(),
                ])
                ->all(),
            'abilities' => self::TENANT_ABILITIES,
            'serverUrl' => url("mcp/{$organization->slug}/v1"),
            'justCreatedToken' => $request->session()->get('plain_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        $this->authorize('manageMcp', $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['array'],
            'abilities.*' => ['string', 'in:'.implode(',', self::TENANT_ABILITIES)],
        ]);

        $plain = McpAccessToken::generateToken();

        McpAccessToken::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => $validated['name'],
            'token' => $plain,
            'abilities' => empty($validated['abilities']) ? null : array_values($validated['abilities']),
        ]);

        return back()->with('plain_token', $plain);
    }

    public function destroy(Request $request, McpAccessToken $mcpToken): RedirectResponse
    {
        $organization = $request->user()->organization;
        $this->authorize('manageMcp', $organization);

        abort_unless($mcpToken->organization_id === $organization?->id, 403);

        $mcpToken->delete();

        return back()->with('success', 'MCP token revoked.');
    }
}
