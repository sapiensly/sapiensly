<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\User;
use App\Services\Platform\AccessPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Change the platform sign-in policy. Only the fields you pass change. Passing a list REPLACES it — send the full list, not just the entry you are adding, and send an empty list to open it up again. Two settings can lock people out and are reported back with their blast radius: turning on two_factor_required affects everyone who has not enrolled yet, and an ip_allowlist you are not calling from will shut you out of the web app (this MCP connection is unaffected, so you can undo it). Audited.')]
class SetAccessPolicyTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'registration_open' => ['sometimes', 'boolean'],
            'email_verification_required' => ['sometimes', 'boolean'],
            'two_factor_required' => ['sometimes', 'boolean'],
            'ip_allowlist_enabled' => ['sometimes', 'boolean'],
            'ip_allowlist' => ['sometimes', 'array'],
            'ip_allowlist.*' => ['string', 'max:45'],
            'domain_allowlist' => ['sometimes', 'array'],
            'domain_allowlist.*' => ['string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'session_lifetime_minutes' => [
                'sometimes',
                'integer',
                'between:'.AccessPolicy::SESSION_LIFETIME_MIN.','.AccessPolicy::SESSION_LIFETIME_MAX,
            ],
            'concurrent_sessions_max' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $policy = app(AccessPolicy::class);
        $before = $policy->read();

        $changes = [];
        $warnings = [];

        if ($request->has('registration_open')) {
            $policy->setRegistrationOpen((bool) $validated['registration_open']);
            $changes['registration_open'] = (bool) $validated['registration_open'];
        }

        if ($request->has('email_verification_required')) {
            $policy->setEmailVerificationRequired((bool) $validated['email_verification_required']);
            $changes['email_verification_required'] = (bool) $validated['email_verification_required'];
        }

        if ($request->has('two_factor_required')) {
            $enabled = (bool) $validated['two_factor_required'];
            $policy->setTwoFactorRequired($enabled);
            $changes['two_factor_required'] = $enabled;

            if ($enabled) {
                $without = User::query()->whereNull('two_factor_confirmed_at')->count();
                if ($without > 0) {
                    $warnings[] = "{$without} account(s) have no confirmed second factor and must enrol before they can sign in.";
                }
            }
        }

        if ($request->has('ip_allowlist_enabled')) {
            $policy->setIpAllowlistEnabled((bool) $validated['ip_allowlist_enabled']);
            $changes['ip_allowlist_enabled'] = (bool) $validated['ip_allowlist_enabled'];
        }

        if ($request->has('ip_allowlist')) {
            $policy->setIpAllowlist($validated['ip_allowlist']);
            $changes['ip_allowlist'] = $validated['ip_allowlist'];
        }

        if ($request->has('domain_allowlist')) {
            $policy->setDomainAllowlist($validated['domain_allowlist']);
            $changes['domain_allowlist'] = $validated['domain_allowlist'];
        }

        if ($request->has('session_lifetime_minutes')) {
            $policy->setSessionLifetimeMinutes((int) $validated['session_lifetime_minutes']);
            $changes['session_lifetime_minutes'] = (int) $validated['session_lifetime_minutes'];
        }

        if ($request->has('concurrent_sessions_max')) {
            $max = $validated['concurrent_sessions_max'] === null ? null : (int) $validated['concurrent_sessions_max'];
            $policy->setConcurrentSessionsMax($max);
            $changes['concurrent_sessions_max'] = $max;
        }

        if ($changes === []) {
            return Response::error('Nothing to change — pass at least one setting.');
        }

        $after = $policy->read();

        if ($after['ipAllowlistEnabled'] && $after['ipAllowlist'] === []) {
            $warnings[] = 'The IP allowlist is enabled but empty, which enforces nothing. Add ranges or disable it.';
        }
        if ($after['ipAllowlistEnabled'] && $after['ipAllowlist'] !== []) {
            $warnings[] = 'Anyone whose address is outside the allowlist loses access to the web app. Confirm your own range is listed.';
        }

        $this->audit(
            actor: $actor,
            summary: 'Updated access policy: '.implode(', ', array_keys($changes)),
            meta: ['changes' => $changes],
            targetType: 'access_policy',
        );

        return Response::json([
            'changed' => array_keys($changes),
            'policy' => $after,
            'posture' => $policy->posture($after),
            'warnings' => $warnings,
            // The whole prior policy, so an undo needs no second call.
            'previous_policy' => $before,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'registration_open' => $schema->boolean()->description('Whether anyone may create an account.'),
            'email_verification_required' => $schema->boolean()->description('Require a verified address before sign-in.'),
            'two_factor_required' => $schema->boolean()->description('Require an authenticator for every account.'),
            'ip_allowlist_enabled' => $schema->boolean()->description('Whether the IP allowlist is enforced at all.'),
            'ip_allowlist' => $schema->array()->items($schema->string())->description('Full replacement list of allowed IPs/ranges. Empty means no restriction.'),
            'domain_allowlist' => $schema->array()->items($schema->string())->description('Full replacement list of email domains allowed to register. Empty means any.'),
            'session_lifetime_minutes' => $schema->integer()->description('Idle session lifetime, 15-10080 minutes.'),
            'concurrent_sessions_max' => $schema->integer()->description('Max simultaneous sessions per account; null for unlimited.'),
        ];
    }
}
