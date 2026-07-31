<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\McpAccessToken;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Platform\PlatformAudit;
use Illuminate\Support\Facades\Auth;

/**
 * Base for the platform-administration suite — the tools that act ACROSS
 * organizations (users, orgs, providers, access policy, infrastructure) rather
 * than inside the tenant the connection is bound to.
 *
 * Two independent keys must both turn, and either one alone is not enough:
 *
 *   1. the credential must name `platform:admin` OUTRIGHT. An empty ability
 *      list means "all tool groups" for the tenant abilities; it deliberately
 *      does not reach this one ({@see McpAccessToken::EXPLICIT_ONLY_ABILITIES}),
 *      and the OAuth connection — which carries no ability list at all — never
 *      does either;
 *   2. the authenticated user must actually hold the platform sysadmin role
 *      ({@see User::isSysAdmin()}), checked at call time against the live
 *      assignment. A token minted with the ability is worthless once the person
 *      behind it stops being a sysadmin.
 *
 * Because the gate lives in shouldRegister(), a caller who fails it does not
 * get "forbidden" — the tools are not in tools/list at all, so an ordinary
 * connection cannot even see that the platform surface exists.
 *
 * Subclasses that WRITE must call {@see self::audit()}.
 */
abstract class SysadminTool extends SapiensTool
{
    protected const ABILITY = McpAccessToken::PLATFORM_ADMIN;

    public function shouldRegister(): bool
    {
        if (! app(McpContext::class)->allowsExplicitly(McpAccessToken::PLATFORM_ADMIN)) {
            return false;
        }

        return $this->actor()?->isSysAdmin() === true;
    }

    /**
     * The authenticated principal, or null outside an authenticated request.
     */
    protected function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Record a platform write. Call it AFTER the change lands, with the values
     * that actually took effect — an audit row for something that then failed
     * is worse than no row at all.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function audit(
        User $actor,
        ?string $summary = null,
        array $meta = [],
        ?string $organizationId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $targetLabel = null,
        string $result = PlatformAuditLog::RESULT_OK,
    ): void {
        app(PlatformAudit::class)->record(
            action: $this->name(),
            actor: $actor,
            organizationId: $organizationId,
            targetType: $targetType,
            targetId: $targetId,
            targetLabel: $targetLabel,
            result: $result,
            summary: $summary,
            meta: $meta,
        );
    }
}
