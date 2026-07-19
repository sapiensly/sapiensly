<?php

namespace App\Services\Landing;

use App\Enums\AppKind;
use App\Models\App;
use App\Models\CustomDomain;
use InvalidArgumentException;

/**
 * The custom-domain lifecycle for landings: connect (register + instructions),
 * verify (DNS + Cloudflare status → active), disconnect, and the Host-header
 * lookup the public surface serves by. Philosophy: own the surface, rent the
 * hard infra — Cloudflare for SaaS does TLS/edge when configured; without it
 * (local/dev) the flow still works on the DNS check alone.
 */
class CustomDomainService
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly CloudflareCustomHostnames $cloudflare,
    ) {}

    /**
     * The CNAME target customers point their hostname at. Configurable for the
     * real edge (e.g. landings.sapiensly.com); defaults to the app host.
     */
    public function cnameTarget(): string
    {
        $configured = (string) config('services.cloudflare_saas.cname_target');
        if ($configured !== '') {
            return strtolower($configured);
        }

        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /**
     * Register a hostname for a landing and return the row + DNS instructions.
     *
     * @throws InvalidArgumentException on a non-landing, an invalid hostname, or a taken hostname
     */
    public function connect(App $app, string $hostname): CustomDomain
    {
        if ($app->kind !== AppKind::Landing) {
            throw new InvalidArgumentException("Only landings can use a custom domain — '{$app->slug}' is a {$app->kind->value}.");
        }

        $hostname = strtolower(trim($hostname, ". \t/"));
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $hostname) !== 1) {
            throw new InvalidArgumentException("'{$hostname}' is not a valid hostname (e.g. landing.acme.com).");
        }
        if ($hostname === $this->cnameTarget() || str_ends_with($hostname, '.'.$this->cnameTarget())) {
            throw new InvalidArgumentException('Point your OWN domain here — not the platform host.');
        }
        if (CustomDomain::query()->where('hostname', $hostname)->exists()) {
            throw new InvalidArgumentException("'{$hostname}' is already connected.");
        }

        $cfId = null;
        if ($this->cloudflare->configured()) {
            $cfId = $this->cloudflare->create($hostname)['id'];
        }

        return CustomDomain::create([
            'organization_id' => $app->organization_id,
            'user_id' => $app->user_id,
            'app_id' => $app->id,
            'hostname' => $hostname,
            'status' => CustomDomain::STATUS_PENDING,
            'cf_hostname_id' => $cfId,
        ]);
    }

    /**
     * Re-check DNS (and Cloudflare when configured) and flip to active when
     * everything lines up. Returns the refreshed row plus per-check detail so
     * the caller can tell the user exactly what is still missing.
     *
     * @return array{domain: CustomDomain, checks: array<string, string>}
     */
    public function verify(CustomDomain $domain): array
    {
        $checks = [];

        $target = $this->cnameTarget();
        $cname = $this->dns->cname($domain->hostname);
        $dnsOk = $cname === $target;
        $checks['dns'] = $dnsOk
            ? "ok — CNAME points at {$target}"
            : ($cname === null
                ? "missing — add a CNAME record: {$domain->hostname} → {$target}"
                : "wrong target — CNAME points at {$cname}, expected {$target}");

        $cfOk = true;
        if ($this->cloudflare->configured() && $domain->cf_hostname_id !== null) {
            $status = $this->cloudflare->status($domain->cf_hostname_id);
            $cfOk = $status['status'] === 'active' && $status['ssl_status'] === 'active';
            $checks['cloudflare'] = $cfOk
                ? 'ok — hostname active, certificate issued'
                : 'pending — hostname '.($status['status'] ?? 'unknown').', ssl '.($status['ssl_status'] ?? 'unknown');
        }

        if ($dnsOk && $cfOk) {
            $domain->forceFill([
                'status' => CustomDomain::STATUS_ACTIVE,
                'verified_at' => $domain->verified_at ?? now(),
            ])->save();
        }

        return ['domain' => $domain->refresh(), 'checks' => $checks];
    }

    public function disconnect(CustomDomain $domain): void
    {
        if ($this->cloudflare->configured() && $domain->cf_hostname_id !== null) {
            try {
                $this->cloudflare->delete($domain->cf_hostname_id);
            } catch (\Throwable) {
                // Best-effort: the row is the source of truth for serving.
            }
        }
        $domain->delete();
    }

    /**
     * The Host-header lookup the public surface serves by. Null when the host
     * is not an active custom domain (the platform routes proceed as normal);
     * 404 when the domain IS ours but its landing is gone/unpublished — a
     * custom-domain visitor should never be bounced to the platform login.
     */
    public function appForHost(string $host): ?App
    {
        $domain = CustomDomain::query()->active()->where('hostname', strtolower($host))->first();
        if ($domain === null) {
            return null;
        }

        $app = App::query()->find($domain->app_id);
        if ($app === null || $app->kind !== AppKind::Landing || $app->published_at === null) {
            abort(404);
        }

        return $app;
    }
}
