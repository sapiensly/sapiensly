<?php

namespace App\Jobs\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware that establishes the tenant scope inside a queue worker, where
 * no HTTP middleware runs. The owning org/user are derived from the job itself
 * (jobs serialize the records they act on), mirroring the owner-aware storage
 * resolution already used by document/embedding jobs.
 *
 * Usage in a job's `middleware()`:
 *
 *   public function middleware(): array
 *   {
 *       return [EstablishTenantContext::fromOwner($this->document->organization_id, $this->document->user_id)];
 *   }
 */
class EstablishTenantContext
{
    public function __construct(
        private readonly ?string $organizationId,
        private readonly ?int $userId,
    ) {}

    public static function fromOwner(?string $organizationId, ?int $userId): self
    {
        return new self($organizationId, $userId);
    }

    public function handle(object $job, Closure $next): mixed
    {
        app(TenantContext::class)->set($this->organizationId, $this->userId);

        try {
            return $next($job);
        } finally {
            app(TenantContext::class)->forget();
        }
    }
}
