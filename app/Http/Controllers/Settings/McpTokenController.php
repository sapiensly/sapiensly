<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service management of a user's MCP access tokens — the bearer tokens an
 * external client (Claude Code / Claude web) uses to reach the Sapiensly MCP
 * server as this user. The raw token is shown exactly once, on creation, via a
 * flash; afterwards only a masked prefix is ever returned.
 */
class McpTokenController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/McpTokens', [
            'tokens' => McpAccessToken::query()
                ->where('user_id', $user->id)
                ->latest()
                ->get()
                ->map(fn (McpAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'masked' => substr($token->token, 0, 8).'…',
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at->toIso8601String(),
                ])
                ->all(),
            'abilities' => McpAccessToken::ABILITIES,
            'serverUrl' => url('mcp/v1'),
            // The raw token, flashed by store() exactly once, so the page can
            // show it (and the connection snippet) before it becomes unreadable.
            'justCreatedToken' => $request->session()->get('plain_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['array'],
            'abilities.*' => ['string', 'in:'.implode(',', McpAccessToken::ABILITIES)],
        ]);

        $plain = McpAccessToken::generateToken();

        McpAccessToken::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'token' => $plain,
            // Empty selection = all abilities (null), matching McpAccessToken::hasAbility.
            'abilities' => empty($validated['abilities']) ? null : array_values($validated['abilities']),
        ]);

        return back()->with('plain_token', $plain);
    }

    public function destroy(Request $request, McpAccessToken $mcpToken): RedirectResponse
    {
        abort_unless($mcpToken->user_id === $request->user()->id, 403);

        $mcpToken->delete();

        return back()->with('success', 'MCP token revoked.');
    }
}
