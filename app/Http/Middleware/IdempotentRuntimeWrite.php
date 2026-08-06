<?php

namespace App\Http\Middleware;

use App\Facades\TenantCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write sent twice is performed once.
 *
 * This exists for the offline queue and it is the reason that queue is
 * allowed to retry at all. A phone that loses the signal mid-request cannot
 * tell "never arrived" from "arrived, answer lost" — and those need opposite
 * handling. Without a key the client must choose: retry and risk a second work
 * order, or not retry and risk losing the first. Both are wrong. With one it
 * retries freely, because the second attempt is the SAME write.
 *
 * Scoped to the tenant through `TenantCache`, which namespaces by the active
 * organization or user and fails closed with no scope — one shared Redis
 * keyspace and a client-chosen key would otherwise let one tenant's key
 * collide with another's, and a collision here returns somebody else's
 * response body.
 *
 * WHAT IT DOES NOT DO. It is a dedupe window, not a transaction log: if Redis
 * loses the key the replay writes again. The window is long relative to how
 * long a device stays offline (a week), and the alternative — a tenant table
 * and a row per write — buys durability for a failure mode (Redis eviction
 * inside the same week as an outage) that is rarer than the one being fixed.
 * If that trade stops holding, this is the seam to change and nothing else.
 */
class IdempotentRuntimeWrite
{
    /** Long enough to outlive any plausible offline stretch. */
    private const TTL = 7 * 24 * 60 * 60;

    /** Held while the first attempt is still running. */
    private const IN_FLIGHT = '__in_flight__';

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        // No key means a live request from an online page, which has no replay
        // to protect against. Costing every ordinary write a Redis round trip
        // to guard a case that cannot happen would be the wrong trade.
        if ($key === '' || mb_strlen($key) > 128) {
            return $next($request);
        }

        $cacheKey = 'idem:'.hash('sha256', $request->path().'|'.$key);

        // `add` is atomic: whoever wins it owns the write, and a second copy of
        // the same request arriving concurrently loses and is told to come
        // back. Checking-then-writing would let both through.
        if (! TenantCache::add($cacheKey, self::IN_FLIGHT, self::TTL)) {
            $stored = TenantCache::get($cacheKey);

            if ($stored === self::IN_FLIGHT) {
                // The first attempt has not finished. Not an error — the queue
                // stops on this and tries the whole flush again.
                return response()->json([
                    'message' => 'This request is already being processed.',
                ], 409);
            }

            return $this->replay($stored);
        }

        $response = $next($request);

        // Only a success is worth remembering. A 422 replayed produces the same
        // 422 from the same input, and a 500 is a failure the caller should be
        // allowed to retry for real — storing either would freeze a transient
        // fault into a permanent answer.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            TenantCache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(),
            ], self::TTL);
        } else {
            TenantCache::forget($cacheKey);
        }

        return $response;
    }

    /**
     * Hand back the first attempt's own answer.
     *
     * Verbatim, including the created record's id, because the caller asked
     * the same question and deserves the same answer. The extra header is for
     * a human reading a network log, not for the client — nothing branches on
     * it, and a client that started to would be relying on cache state.
     *
     * @param  array{status: int, body: string}|mixed  $stored
     */
    private function replay(mixed $stored): Response
    {
        if (! is_array($stored) || ! isset($stored['status'], $stored['body'])) {
            // A malformed entry is a bug in this class, not a reason to serve
            // something wrong. Let the request through: at worst it repeats a
            // write, which is what we had before any of this existed.
            return response()->json(['message' => 'Replay unavailable.'], 409);
        }

        return response(
            $stored['body'],
            $stored['status'],
        )->header('Content-Type', 'application/json')
            ->header('Idempotent-Replay', 'true');
    }
}
