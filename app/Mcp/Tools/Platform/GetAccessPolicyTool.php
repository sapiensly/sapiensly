<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\User;
use App\Services\Platform\AccessPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('The platform sign-in policy as it stands: whether registration is open, whether email verification and two-factor are required, the IP and email-domain allowlists, session lifetime and concurrent-session cap — plus the derived hardening checklist and a count of accounts that still have no second factor. Empty allowlists mean "open to all", and an ENABLED but empty IP allowlist enforces nothing; the checklist says so rather than reporting it as protection. Read-only; change it with set_access_policy.')]
class GetAccessPolicyTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $policy = app(AccessPolicy::class);
        $settings = $policy->read();

        return Response::json([
            'policy' => $settings,
            'posture' => $policy->posture($settings),
            'accounts' => [
                'total' => User::query()->count(),
                'without_two_factor' => User::query()->whereNull('two_factor_confirmed_at')->count(),
                'unverified' => User::query()->whereNull('email_verified_at')->count(),
                'blocked' => User::query()->whereNotNull('blocked_at')->count(),
            ],
            'notes' => [
                'domain_allowlist' => $settings['domainAllowlist'] === []
                    ? 'Empty — any email domain may register.'
                    : 'Only these domains may register.',
                'ip_allowlist' => match (true) {
                    ! $settings['ipAllowlistEnabled'] => 'Disabled — no IP restriction.',
                    $settings['ipAllowlist'] === [] => 'Enabled but EMPTY — this enforces nothing.',
                    default => 'Enforced for the listed ranges.',
                },
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
