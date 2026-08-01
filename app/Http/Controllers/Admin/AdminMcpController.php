<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\PlatformAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform MCP access. A token carrying `platform:admin` is a credential for
 * administering the WHOLE platform, so it is minted here — in the sysadmin
 * console — and not on an organization's own settings screen, where the
 * surrounding context would suggest it were scoped to that tenant.
 *
 * The org-facing screen (System → MCP) keeps issuing tenant tokens and no
 * longer offers this ability at all.
 *
 * A token is still PINNED to one organization, because the MCP endpoint is
 * per-org (`mcp/{organization}/v1`) and AuthenticateMcpToken requires the
 * bearer to be an active member of it. That org decides the tenant scope of any
 * tenant abilities on the same token; the platform tools ignore it and act
 * across every organization.
 */
class AdminMcpController extends Controller
{
    /**
     * The MCP server name suggested in the `claude mcp add` line.
     *
     * A platform credential gets its OWN name. Claude Code keys connections by
     * name, and an operator normally already has `sapiensly` (and one per
     * organization) configured — reusing the name would add a root-equivalent
     * credential over an existing tenant connection, silently.
     */
    private const PLATFORM_SERVER_NAME = 'sapiensly-sysadmin';

    private const TENANT_SERVER_NAME = 'sapiensly';

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('admin/Mcp', [
            'organizations' => $this->pinnableOrganizations($user),
            'abilities' => McpAccessToken::ABILITIES,
            'platformAbility' => McpAccessToken::PLATFORM_ADMIN,
            'tokens' => $this->tokens(),
            'justCreatedToken' => $request->session()->get('plain_token'),
            'justCreatedUrl' => $request->session()->get('plain_token_url'),
            // Names the MCP server in the suggested `claude mcp add` line. A
            // platform credential gets its own name so it cannot be added over
            // an existing per-organization connection of the same name.
            'justCreatedServerName' => $request->session()->get('plain_token_server'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pinnable = collect($this->pinnableOrganizations($user))->pluck('id')->all();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'organization_id' => ['required', 'string', Rule::in($pinnable)],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(McpAccessToken::ABILITIES)],
        ], [
            'organization_id.in' => __('Pin the token to an organization you are an active member of — the MCP endpoint refuses a token whose bearer is not.'),
            'abilities.required' => __('Choose at least one ability. Unlike the organization screen, this one never grants abilities by omission.'),
        ]);

        $organization = Organization::findOrFail($validated['organization_id']);
        $plain = McpAccessToken::generateToken();
        $isPlatform = in_array(McpAccessToken::PLATFORM_ADMIN, $validated['abilities'], true);

        $token = McpAccessToken::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => $validated['name'],
            'token' => $plain,
            'abilities' => array_values($validated['abilities']),
        ]);

        if ($isPlatform) {
            app(PlatformAudit::class)->record(
                action: 'issue_platform_token',
                actor: $user,
                organizationId: $organization->id,
                targetType: 'mcp_access_token',
                targetId: $token->id,
                targetLabel: $token->name,
                summary: "Issued a platform-admin MCP token '{$token->name}' pinned to '{$organization->name}'",
                meta: ['abilities' => $validated['abilities']],
                channel: 'web',
            );
        }

        return back()
            ->with('plain_token', $plain)
            ->with('plain_token_url', url("mcp/{$organization->slug}/v1"))
            ->with('plain_token_server', $isPlatform ? self::PLATFORM_SERVER_NAME : self::TENANT_SERVER_NAME);
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
     * Organizations this sysadmin may pin a token to: the ones they are an
     * active member of. Anything else would mint a credential the MCP endpoint
     * rejects on first use.
     *
     * @return list<array{id: string, name: string, slug: string}>
     */
    private function pinnableOrganizations(User $user): array
    {
        $ids = $user->memberships()
            ->where('status', MembershipStatus::Active)
            ->pluck('organization_id')
            ->all();

        if ($user->organization_id !== null) {
            $ids[] = $user->organization_id;
        }

        return Organization::query()
            ->whereIn('id', array_unique($ids))
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ])
            ->values()
            ->all();
    }

    /**
     * Every MCP token on the platform, newest first — the same inventory the
     * `list_mcp_tokens` tool returns, so a stale credential is visible from
     * either surface.
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
                'organizationSlug' => $token->organization?->slug,
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'createdAt' => $token->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
