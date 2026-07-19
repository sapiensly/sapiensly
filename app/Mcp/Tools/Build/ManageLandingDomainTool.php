<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\CustomDomain;
use App\Models\User;
use App\Services\Landing\CustomDomainService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Connect a tenant's OWN domain (landing.acme.com) to a published landing, so it serves at that hostname's root. Actions: `connect` registers the hostname and returns the CNAME instructions; `verify` re-checks DNS (and Cloudflare when configured) and activates the domain when everything lines up — call it again after the customer updates DNS; `status` reports the current state; `disconnect` removes the domain (the hostname stops serving). Outward-facing infrastructure: only act on an explicit user request.")]
class ManageLandingDomainTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'action' => ['required', 'string', 'in:connect,verify,status,disconnect'],
            'hostname' => ['required_if:action,connect', 'nullable', 'string', 'max:253'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $service = app(CustomDomainService::class);

        if ($validated['action'] === 'connect') {
            try {
                $domain = $service->connect($app, (string) $validated['hostname']);
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage());
            }

            return Response::json([
                'connected' => true,
                'hostname' => $domain->hostname,
                'status' => $domain->status,
                'dns_instructions' => "Add a CNAME record: {$domain->hostname} → {$service->cnameTarget()}, then call this tool with action=verify.",
            ]);
        }

        $domain = CustomDomain::query()->where('app_id', $app->id)->latest()->first();
        if ($domain === null) {
            return Response::error("App '{$app->slug}' has no custom domain. Use action=connect first.");
        }

        if ($validated['action'] === 'verify') {
            $result = $service->verify($domain);

            return Response::json([
                'hostname' => $result['domain']->hostname,
                'status' => $result['domain']->status,
                'checks' => $result['checks'],
                'live_url' => $result['domain']->status === CustomDomain::STATUS_ACTIVE
                    ? 'https://'.$result['domain']->hostname
                    : null,
            ]);
        }

        if ($validated['action'] === 'disconnect') {
            $service->disconnect($domain);

            return Response::json([
                'disconnected' => true,
                'hostname' => $domain->hostname,
            ]);
        }

        return Response::json([
            'hostname' => $domain->hostname,
            'status' => $domain->status,
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'cname_target' => $service->cnameTarget(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()->description('The slug of the landing app.')->required(),
            'action' => $schema->string()
                ->description('connect (register a hostname, returns DNS instructions) | verify (re-check DNS/SSL, activates when ready) | status | disconnect.')
                ->required(),
            'hostname' => $schema->string()->description('The customer domain to connect (e.g. landing.acme.com). Required for action=connect.'),
        ];
    }
}
