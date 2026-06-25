<?php

namespace App\Mcp\Tools;

use App\Facades\TenantCache;
use App\Mcp\McpContext;
use App\Models\App;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every Sapiensly MCP tool. Two shared concerns:
 *   - ability gating: a tool declares the token ability it needs in ABILITY and
 *     is only registered (visible + callable) when the request's McpAccessToken
 *     grants it — so a read-only token never even sees the write tools;
 *   - tenant-scoped resolution: helpers resolve apps/the user through the same
 *     HasVisibility scopes the web controllers use, on top of the RLS scope that
 *     BindMcpTenantContext already set.
 */
abstract class SapiensTool extends Tool
{
    /** The token ability required to use this tool; null = no specific ability. */
    protected const ABILITY = null;

    /**
     * Expose a clean snake_case name (e.g. ReadManifestTool -> "read_manifest")
     * so tool names match how the instructions/descriptions refer to them.
     */
    public function name(): string
    {
        return (string) Str::of(class_basename($this))->beforeLast('Tool')->snake();
    }

    /**
     * Registered only when the request's token grants this tool's ability.
     */
    public function shouldRegister(): bool
    {
        return app(McpContext::class)->allows(static::ABILITY);
    }

    /**
     * Resolve an app by slug within the caller's account context, or fail. The
     * forAccountContext scope is the same authorization the web controllers use.
     */
    protected function resolveApp(string $slug, User $user): App
    {
        return App::query()->forAccountContext($user)->where('slug', $slug)->firstOrFail();
    }

    /** How long a client's idempotency key replays its prior result (24h). */
    protected const IDEMPOTENCY_TTL = 86400;

    /**
     * The successful result a previous call with this idempotency key produced, or
     * null if there is none. Scoped to the caller's account (via forOwner) so a key
     * never collides across tenants. Lets a write tool replay a completed result
     * instead of repeating the side effect when a client retries after a timeout.
     *
     * @return array<string, mixed>|null
     */
    protected function idempotentReplay(User $user, ?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        $cached = TenantCache::forOwner($user->organization_id, $user->id)
            ->get($this->idempotencyCacheKey($key));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Remember a successful result so an identical retry replays it. No-op when no
     * key was supplied. Only call this for results that completed successfully.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function rememberIdempotent(User $user, ?string $key, array $payload): void
    {
        if ($key === null || $key === '') {
            return;
        }

        TenantCache::forOwner($user->organization_id, $user->id)
            ->put($this->idempotencyCacheKey($key), $payload, self::IDEMPOTENCY_TTL);
    }

    private function idempotencyCacheKey(string $key): string
    {
        return 'idem:'.$this->name().':'.$key;
    }
}
