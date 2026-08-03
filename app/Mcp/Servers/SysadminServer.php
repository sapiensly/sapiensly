<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Laravel\Mcp\Schema\Icon;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The platform-administration MCP server, served at `mcp/platform/v1`.
 *
 * Deliberately NOT organization-bound. {@see SapiensServer} is reached at
 * `mcp/{organization}/v1` because everything it does happens inside one tenant;
 * this one administers the platform ITSELF, so binding it to an organization
 * would be a fiction — the scope would appear in the URL, in whoami, and in the
 * model's reasoning, while every tool ignored it.
 *
 * It carries only tools that are honest without a tenant: the platform suite,
 * plus identity and the clock. The tenant-facing catalogue is absent rather
 * than present-and-failing, so a model never plans around a tool that would
 * error the moment it ran.
 */
#[Name('Sapiensly Sysadmin')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
Sapiensly platform administration. This connection is NOT bound to an
organization: every tool here acts across the whole platform — all tenants, all
accounts, all providers. For work inside one organization use that
organization's own endpoint (mcp/{organization}/v1) instead.

Every write is recorded in the platform audit log with the actor and the
sanitized arguments; read_platform_audit answers "who changed what" later.

Start with platform_overview — counts, spend, growth and a health roll-up in one
call — then drill in:

Observe:
  - platform_health (Horizon, Reverb, Redis, Postgres, queue depth, failed jobs),
    platform_stack (what this platform is built from, with live up/down),
    list_failed_jobs / retry_failed_job (queue forensics — retry re-runs a job,
    so anything with real side effects performs them again),
    read_platform_logs, read_platform_audit.

Organizations:
  - list_organizations, inspect_organization (members, what they built, spend,
    budget, tokens), create_organization, manage_organization (rename / suspend /
    restore — suspend is a REVERSIBLE soft-delete, never a purge),
    purge_organization (the irreversible one: destroys every row, file and
    cached value of a SUSPENDED tenant, and is how a deletion request is
    honoured — run dry_run=true first, then confirm with the slug),
    set_organization_budget (their limits, and the platform cap they cannot raise).

Accounts:
  - list_platform_users, inspect_platform_user — read this BEFORE deleting
    anyone: it says what they own and which organizations they solely own —
    invite_platform_user, manage_platform_user (block / unblock /
    reset_two_factor / resend_verification / delete),
    manage_organization_membership, which never leaves an organization without
    an active owner.

Sign-in policy:
  - get_access_policy / set_access_policy. Turning on two-factor or an IP
    allowlist can lock people out of the web app; the tools report the blast
    radius before you commit to it.

AI:
  - list_platform_providers, manage_provider_key (set/rotate, test, sync models),
    list_catalog_models, manage_catalog_model, get_ai_defaults / set_ai_defaults
    (which model each module routes to, platform-wide), get_platform_spend.

Infrastructure:
  - get_cloud_config; verify_tenant_isolation checks that RLS, policies and
    triggers are really in place on every tenant table — run it after any
    migration that touches them; list_mcp_tokens / revoke_mcp_token;
    run_platform_maintenance (a fixed allowlist of operations, no arguments —
    the disruptive ones need explicit confirmation).

Destructive or outward-facing actions (deleting an account, suspending an
organization, rotating a provider key, restarting workers) are the user's call.
Confirm before performing one.
TXT)]
class SysadminServer extends Server
{
    public int $defaultPaginationLength = 100;

    public int $maxPaginationLength = 100;

    /**
     * Only what is honest without a tenant: the platform suite, plus identity
     * and the clock. Every entry is registered from the same classes
     * SapiensServer uses, so a tool cannot behave differently depending on
     * which endpoint reached it.
     *
     * @var list<class-string>
     */
    public const TOOLS = [
        // Identity & clock.
        Tools\Account\WhoamiTool::class,
        Tools\Account\CurrentDatetimeTool::class,
        // Observe.
        Tools\Platform\PlatformOverviewTool::class,
        Tools\Platform\PlatformHealthTool::class,
        Tools\Platform\PlatformStackTool::class,
        Tools\Platform\ListFailedJobsTool::class,
        Tools\Platform\RetryFailedJobTool::class,
        Tools\Platform\ReadPlatformLogsTool::class,
        Tools\Platform\ReadPlatformAuditTool::class,
        // Organizations.
        Tools\Platform\ListOrganizationsTool::class,
        Tools\Platform\InspectOrganizationTool::class,
        Tools\Platform\CreateOrganizationTool::class,
        Tools\Platform\ManageOrganizationTool::class,
        Tools\Platform\PurgeOrganizationTool::class,
        Tools\Platform\SetOrganizationBudgetTool::class,
        // Accounts & membership.
        Tools\Platform\ListPlatformUsersTool::class,
        Tools\Platform\InspectPlatformUserTool::class,
        Tools\Platform\InvitePlatformUserTool::class,
        Tools\Platform\ManagePlatformUserTool::class,
        Tools\Platform\ManageOrganizationMembershipTool::class,
        // Sign-in policy.
        Tools\Platform\GetAccessPolicyTool::class,
        Tools\Platform\SetAccessPolicyTool::class,
        // Platform AI configuration.
        Tools\Platform\ListPlatformProvidersTool::class,
        Tools\Platform\ManageProviderKeyTool::class,
        Tools\Platform\ListCatalogModelsTool::class,
        Tools\Platform\ManageCatalogModelTool::class,
        Tools\Platform\GetAiDefaultsTool::class,
        Tools\Platform\SetAiDefaultsTool::class,
        Tools\Platform\GetPlatformSpendTool::class,
        // Infrastructure & credentials.
        Tools\Platform\GetCloudConfigTool::class,
        Tools\Platform\VerifyTenantIsolationTool::class,
        Tools\Platform\ListMcpTokensTool::class,
        Tools\Platform\RevokeMcpTokenTool::class,
        Tools\Platform\RunPlatformMaintenanceTool::class,
    ];

    /** @var list<class-string> */
    protected array $tools = self::TOOLS;

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];

    /**
     * @return list<Icon>
     */
    protected function icons(): array
    {
        $icons = [];

        $svg = @file_get_contents(public_path('favicon.svg'));
        if ($svg !== false) {
            $icons[] = Icon::from('data:image/svg+xml;base64,'.base64_encode($svg), 'image/svg+xml');
        }

        $icons[] = Icon::from(url('favicon/android-chrome-512x512.png'), 'image/png', ['512x512']);

        return $icons;
    }
}
