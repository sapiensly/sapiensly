import axios from 'axios';

/**
 * What every record mutation answers with, whoever asked.
 *
 * Uniform on purpose: a caller that tells success from failure by catching a
 * particular exception shape is a caller that will get it wrong the day the
 * transport changes — and the transport IS going to change.
 *
 * The failure carries the server's own response rather than a flattened
 * message. This is a seam, not a filter: hiding the status would take the 429
 * handling and the per-field validation errors with it, and a caller forced to
 * go around the seam to get them would defeat the point of having one.
 */
export interface WriteResult<T = unknown> {
    ok: boolean;
    data?: T;
    /** Absent when the request never reached a server (offline, timeout). */
    status?: number;
    headers?: Record<string, string>;
    /** The parsed error body, when the server sent one. */
    body?: unknown;
}

/**
 * The one place the runtime writes a record.
 *
 * Not a wrapper for tidiness. It exists because OFFLINE IS A TRANSPORT, not a
 * feature: a form that submits when the signal comes back is the same request,
 * held somewhere else. Built with the write path scattered, offline means
 * finding every caller and retrofitting each one; built with the path already
 * single, it is this function's body and nothing else.
 *
 * Same reasoning as the environment guard in `scopeForObject` and the soft
 * delete on Record: the seam goes in before it is needed, because putting it in
 * afterwards means touching everything that grew in the meantime.
 *
 * SCOPE, deliberately narrow: things that change the tenant's RECORDS. Chat
 * messages, sign-in, notification reads and environment switches also POST, and
 * none of them belongs here — queueing a login for later is meaningless, and a
 * chat message held for six hours is worse than one that failed.
 */
export function useRuntimeWrite() {
    /**
     * @param path Absolute runtime path, already mounted (/r/… or /a/…).
     */
    async function write<T = unknown>(
        path: string,
        payload: unknown,
        options: { timeoutMs?: number } = {},
    ): Promise<WriteResult<T>> {
        try {
            const { data } = await axios.post<T>(path, payload, {
                timeout: options.timeoutMs ?? 30_000,
            });

            return { ok: true, data };
        } catch (e) {
            const err = e as {
                response?: {
                    status?: number;
                    headers?: Record<string, string>;
                    data?: unknown;
                };
            };

            return {
                ok: false,
                status: err.response?.status,
                headers: err.response?.headers,
                body: err.response?.data,
            };
        }
    }

    /**
     * The message a person should see for a failed write, when the caller has
     * nothing more specific to say. Shared so "it didn't work" reads the same
     * wherever it happens.
     */
    function messageFor(result: WriteResult, fallback: string): string {
        const body = result.body as { message?: string } | undefined;

        return typeof body?.message === 'string' && body.message !== ''
            ? body.message
            : fallback;
    }

    return { write, messageFor };
}
