<?php

namespace App\Support\Apps;

use App\Support\Tenancy\TenantContext;

/**
 * Which set of an app's records the current request is working in.
 *
 * An app has two: `production`, holding what the business actually runs on,
 * and `demo`, holding whatever somebody wants to wreck while they learn it.
 * One app, one manifest — two manifests drift the first time somebody edits a
 * button, which is the same reason a multilingual landing is one app and not
 * two. What separates is the DATA and the SIDE EFFECTS, not the structure.
 *
 * Held as request-scoped state rather than threaded through every query, for
 * the reason {@see TenantContext} exists: a filter that
 * each call site has to remember is a filter that one of them forgets, and the
 * failure is silent — a demo order in a real invoice, or worse, a real order
 * deleted by somebody who believed they were in a sandbox.
 *
 * Defaults to production, and that direction is deliberate. Reading production
 * where demo was meant is confusing; WRITING to production while believing you
 * are in a sandbox is not recoverable. So the sandbox is never the fallback —
 * it is only ever reached by asking for it explicitly, and the runtime says so
 * on screen the whole time you are in it.
 */
class EnvironmentContext
{
    public const PRODUCTION = 'production';

    public const DEMO = 'demo';

    public const ALL = [self::PRODUCTION, self::DEMO];

    private string $current = self::PRODUCTION;

    public function current(): string
    {
        return $this->current;
    }

    public function isDemo(): bool
    {
        return $this->current === self::DEMO;
    }

    /** Anything that is not a known environment is production. */
    public function set(?string $environment): void
    {
        $this->current = in_array($environment, self::ALL, true)
            ? $environment
            : self::PRODUCTION;
    }

    /**
     * Run a callable in one environment and put the previous one back.
     *
     * For queues and for anything that has to touch the other side on purpose
     * — resetting the demo, or seeding it from a job that has no request.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runIn(string $environment, callable $callback): mixed
    {
        $previous = $this->current;
        $this->set($environment);

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}
