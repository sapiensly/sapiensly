<?php

namespace App\Services\Context;

use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Support\Context\PromptContext;

/**
 * Resolves the organization Contextbook for a prompt-building chokepoint.
 *
 * The block is materialized on write, so this is a single indexed lookup — no
 * rendering, and deliberately no Redis (a tenant-derived value under a shared
 * cache key leaks across tenants; this is control-plane data and the query is
 * cheap enough not to need TenantCache).
 *
 * Deliberately NOT a container singleton. The memo below must not outlive the
 * service that owns it: on a long-lived queue worker a singleton would keep
 * serving a Contextbook the organization has since edited. Callers hold it as a
 * lazily-created property, so the memo lives exactly as long as the service does.
 */
class OrganizationContextResolver
{
    /** @var array<string, PromptContext> */
    private array $memo = [];

    public function forUser(?User $user): PromptContext
    {
        return $this->forOrganizationId($user?->organization_id);
    }

    /**
     * Personal accounts get nothing: a personal workspace has no business
     * identity to describe, so there is nothing to inject.
     */
    public function forOrganizationId(?string $organizationId): PromptContext
    {
        if ($organizationId === null || $organizationId === '') {
            return PromptContext::none();
        }

        return $this->memo[$organizationId] ??= $this->load($organizationId);
    }

    private function load(string $organizationId): PromptContext
    {
        $row = OrganizationAiContext::query()
            ->where('organization_id', $organizationId)
            ->first();

        $block = $row?->injectableBlock();

        if ($row === null || $block === null) {
            return PromptContext::none();
        }

        return new PromptContext($block, $row->context()->timezone);
    }
}
