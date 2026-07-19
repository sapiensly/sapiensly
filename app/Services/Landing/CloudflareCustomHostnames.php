<?php

namespace App\Services\Landing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare for SaaS custom hostnames — the rented half of custom domains:
 * Cloudflare terminates TLS for the customer's hostname (automatic issuance +
 * renewal, WAF, DDoS) and forwards to our fallback origin. This client only
 * runs when the zone/token are configured; local/dev works without it (DNS
 * check only) so nothing here is load-bearing for the flow itself.
 */
class CloudflareCustomHostnames
{
    public function configured(): bool
    {
        return (string) config('services.cloudflare_saas.api_token') !== ''
            && (string) config('services.cloudflare_saas.zone_id') !== '';
    }

    /**
     * Register the hostname with Cloudflare (starts DCV + cert issuance).
     *
     * @return array{id: ?string, status: ?string}
     */
    public function create(string $hostname): array
    {
        $response = $this->request()->post($this->base().'/custom_hostnames', [
            'hostname' => $hostname,
            'ssl' => ['method' => 'http', 'type' => 'dv'],
        ]);

        $result = $response->json('result') ?? [];

        return [
            'id' => $result['id'] ?? null,
            'status' => $result['status'] ?? null,
        ];
    }

    /**
     * @return array{status: ?string, ssl_status: ?string}
     */
    public function status(string $cfHostnameId): array
    {
        $result = $this->request()->get($this->base().'/custom_hostnames/'.$cfHostnameId)->json('result') ?? [];

        return [
            'status' => $result['status'] ?? null,
            'ssl_status' => $result['ssl']['status'] ?? null,
        ];
    }

    public function delete(string $cfHostnameId): void
    {
        $this->request()->delete($this->base().'/custom_hostnames/'.$cfHostnameId);
    }

    private function base(): string
    {
        return 'https://api.cloudflare.com/client/v4/zones/'.config('services.cloudflare_saas.zone_id');
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('services.cloudflare_saas.api_token'))
            ->timeout(10)
            ->acceptJson();
    }
}
