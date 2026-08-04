import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { inject } from 'vue';
import { toast } from 'vue-sonner';

export type RuntimeAction = Record<string, unknown> & { type: string };

export interface ExecutionContext {
    appSlug: string;
    /** Current page slug — lets the server return fresh block data for a refresh. */
    page?: string;
    params?: Record<string, unknown>;
    form?: Record<string, unknown>;
    /**
     * Per-row context emitted by table action columns. Shape mirrors what the
     * server expects: `{id: rec_..., data: {<slug>: value}}`. Drives
     * {{row.id}} and {{row.data.<slug>}} in on_click action sequences.
     */
    row?: { id: string; data: Record<string, unknown> };
}

export interface ExecutionResult {
    ok: boolean;
    errors?: Record<
        number,
        { type: string; fields?: Record<string, string[]>; message?: string }
    >;
    fieldErrors?: Record<string, string[]>;
}

/**
 * Tiny event bus for modal open/close so any nested BlockButton can fire an
 * open_modal action and BlockModal anywhere in the page tree picks it up.
 * Vanilla EventTarget-backed — no third-party dep needed.
 */
type ModalEvent = 'open' | 'close';
type ModalListener = (
    modalId: string | undefined,
    params?: Record<string, unknown>,
) => void;

class ModalBus {
    private listeners = new Map<ModalEvent, Set<ModalListener>>();

    on(event: ModalEvent, fn: ModalListener): () => void {
        if (!this.listeners.has(event)) this.listeners.set(event, new Set());
        this.listeners.get(event)!.add(fn);
        return () => this.listeners.get(event)?.delete(fn);
    }

    emit(
        event: ModalEvent,
        modalId?: string,
        params?: Record<string, unknown>,
    ) {
        this.listeners.get(event)?.forEach((fn) => fn(modalId, params));
    }
}

export const modalBus = new ModalBus();

/**
 * Carries fresh block data the action endpoint returns alongside a `refresh`, so
 * the page patches the changed blocks in place — no second full page reload.
 * Page.vue subscribes and merges the payload into its reactive blockData.
 */
type BlockDataListener = (data: Record<string, unknown>) => void;

class BlockDataBus {
    private listeners = new Set<BlockDataListener>();

    on(fn: BlockDataListener): () => void {
        this.listeners.add(fn);
        return () => this.listeners.delete(fn);
    }

    emit(data: Record<string, unknown>) {
        this.listeners.forEach((fn) => fn(data));
    }
}

export const blockDataBus = new BlockDataBus();

/** The current page slug from the runtime URL (/r/{app}/{page}), or undefined. */
/**
 * The page's own params, read off the URL.
 *
 * `{{params.x}}` MEANS a URL query param, and only the blocks that were handed
 * one passed any: a table row action carries `row`, a modal carries what opened
 * it, and a plain button passed nothing at all. So a button whose action said
 * `{{params.id}}` — the natural way to write "the record this page is showing"
 * — resolved it to empty and the action ran against no record. A Delete button
 * on a record's own page deleted nothing and said nothing.
 *
 * Read here rather than threaded from each block: every call site would have to
 * pass it, and this is the one place that knows what a param is.
 */
function paramsFromUrl(): Record<string, unknown> {
    const params: Record<string, unknown> = {};
    for (const [key, value] of new URLSearchParams(window.location.search)) {
        params[key] = value;
    }

    return params;
}

function currentPageSlug(): string | undefined {
    const m = window.location.pathname.match(
        /^\/[ra]\/[a-z0-9][a-z0-9_-]*\/([a-z][a-z0-9_]*)/,
    );
    return m?.[1];
}

/**
 * Where this app is mounted: `/r/{slug}` for the authenticated runtime,
 * `/a/{public_slug}` for a public portal.
 *
 * Read from the URL rather than passed down, because the mount is a fact about
 * the page the visitor is ON — and every caller of this module is a click
 * handler, so the browser is always there to ask. The ctx.appSlug form is the
 * fallback for a non-browser context and for anything served outside the two
 * known mounts.
 */
function mountFor(ctx: ExecutionContext): string {
    if (typeof window !== 'undefined') {
        const m = window.location.pathname.match(
            /^\/(r|a)\/([a-z0-9][a-z0-9_-]*)/,
        );
        if (m) return `/${m[1]}/${m[2]}`;
    }
    return `/r/${ctx.appSlug}`;
}

/**
 * Resolve a navigate `to` into a real in-app URL. A manifest authors page links
 * as a page reference ("pos", "/pos", "pos?order=1"), NOT the full runtime path
 * — but `router.visit("/pos")` would hit the host root and 404 (the app is
 * mounted under its own prefix). So we rebase any in-app reference onto
 * <mount>/<page>. Genuinely external/absolute URLs and already-rebased
 * (/r/…, /a/…) paths pass through untouched.
 */
function resolveNavTo(to: string, ctx: ExecutionContext): string {
    if (to === '') return to;
    // External (http(s)://, protocol-relative //, mailto:, tel:) — leave alone.
    if (/^([a-z]+:)?\/\//i.test(to) || /^(mailto:|tel:)/i.test(to)) return to;
    // Already a runtime path (either mount).
    if (to.startsWith('/r/') || to.startsWith('/a/')) return to;
    if (!ctx.appSlug) return to;

    const mount = mountFor(ctx);
    const ref = to.replace(/^\/+/, '');
    // A bare query/hash ("?order=1") means "this page" — keep the current slug.
    if (ref === '' || ref.startsWith('?') || ref.startsWith('#')) {
        const page = ctx.page ?? currentPageSlug() ?? '';
        return `${mount}/${page}${ref}`;
    }

    return `${mount}/${ref}`;
}

/** Walk a dotted path (e.g. "row.data.title") against the execution context. */
function digPath(ctx: ExecutionContext, path: string): unknown {
    let value: unknown = ctx;
    for (const key of path.split('.')) {
        if (value === null || value === undefined) return null;
        if (typeof value !== 'object') return null;
        value = (value as Record<string, unknown>)[key];
    }
    return value;
}

/**
 * Client-side mini resolver for expression strings inside open_modal params.
 * Only supports the exact `{{path.to.value}}` form against the execution
 * context — returns the TYPED value (so an id stays a string, a number a number).
 */
function resolveClientValue(raw: unknown, ctx: ExecutionContext): unknown {
    if (typeof raw !== 'string') return raw;
    const m = raw.match(/^\{\{\s*([\w.]+)\s*\}\}$/);
    if (!m) return raw;
    return digPath(ctx, m[1]);
}

/**
 * Interpolate {{path}} tokens embedded in a string against the context (e.g.
 * "/orders?id={{row.id}}"). Used for navigate `to` and toast messages so a
 * purely client-side sequence still resolves {{row.*}}/{{params.*}}. Tokens the
 * client can't know (notably {{record.*}}, the just-created id) are resolved
 * server-side before they ever reach here, so an unresolved one becomes ''.
 */
function interpolateTemplate(raw: unknown, ctx: ExecutionContext): unknown {
    if (typeof raw !== 'string' || !raw.includes('{{')) return raw;
    return raw.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, path: string) => {
        const v = digPath(ctx, path);
        return v === null || v === undefined ? '' : String(v);
    });
}

/**
 * Execute a manifest action_sequence. Server-side actions (create/update/
 * delete_record) are POSTed in one batch to /r/{slug}/actions; the response
 * carries `client_actions` that we then run locally (navigate, refresh, toast,
 * open/close_modal).
 */
export function useActionExecutor() {
    const environment = inject<string | null>('runtimeEnvironment', null);
    async function execute(
        actions: RuntimeAction[],
        context: ExecutionContext,
    ): Promise<ExecutionResult> {
        if (actions.length === 0) {
            return { ok: true };
        }

        // Normalised once, so the client-side resolver and the server request
        // agree on what `{{params.x}}` means. A caller that named its own wins.
        const ctx: ExecutionContext = {
            ...context,
            params: { ...paramsFromUrl(), ...(context.params ?? {}) },
        };

        // Fast path: every action is purely client-side — skip the round trip.
        const isClientSide = (t: string) =>
            [
                'navigate',
                'open_modal',
                'close_modal',
                'show_toast',
                'refresh',
            ].includes(t);
        if (actions.every((a) => isClientSide(a.type))) {
            actions.forEach((a) => runClientAction(a, ctx));
            return { ok: true };
        }

        try {
            const { data } = await axios.post(
                `${mountFor(ctx)}/actions`,
                {
                    actions,
                    params: ctx.params ?? {},
                    // Only ever 'demo', and only from a surface that provides
                    // it (the builder preview). The server narrows on this and
                    // never widens, so saying it cannot reach real records.
                    ...(environment ? { environment } : {}),
                    form: ctx.form ?? {},
                    row: ctx.row ?? {},
                    page: ctx.page ?? currentPageSlug(),
                },
                { timeout: 30_000 },
            );

            // Single round-trip refresh: when the server returned fresh block
            // data, patch it in place and skip the `refresh` reload — the second
            // request (and full remount) is what made adding to a cart feel slow.
            const patch = data.block_data as
                | Record<string, unknown>
                | undefined;
            const patched = patch != null && typeof patch === 'object';
            if (patched) {
                blockDataBus.emit(patch);
            }

            (data.client_actions as RuntimeAction[] | undefined)?.forEach(
                (a) => {
                    if (patched && a.type === 'refresh') {
                        return; // data already applied reactively
                    }
                    runClientAction(a, ctx);
                },
            );

            return { ok: data.ok === true };
        } catch (e) {
            const err = e as {
                response?: {
                    status?: number;
                    headers?: Record<string, string>;
                    data?: ExecutionResult & {
                        errors?: Record<
                            string,
                            {
                                type?: string;
                                fields?: Record<string, string[]>;
                                message?: string;
                            }
                        >;
                    };
                };
            };
            // Rate limited (429): surface a clear, retry-aware toast rather than
            // a generic failure. Retry-After is seconds.
            if (err.response?.status === 429) {
                const retry = Number(err.response.headers?.['retry-after']);
                const wait =
                    Number.isFinite(retry) && retry > 0
                        ? ` Retry in ${retry}s.`
                        : '';
                toast.error(`Too many requests.${wait}`);
                return { ok: false };
            }
            const body = err.response?.data;
            const validationErrors = body?.errors ?? {};
            const fieldErrors: Record<string, string[]> = {};
            // Field-level validation errors get attached to the form inputs by
            // the caller. Non-validation errors (a workflow step crashing, a
            // missing record, an unknown action) have no field to land on, so
            // we surface their message as an error toast — otherwise the
            // failure is completely invisible to the user.
            const toastMessages: string[] = [];
            for (const entry of Object.values(validationErrors)) {
                if (entry?.fields) {
                    for (const [slug, messages] of Object.entries(
                        entry.fields,
                    )) {
                        fieldErrors[slug] = messages;
                    }
                } else if (
                    typeof entry?.message === 'string' &&
                    entry.message !== ''
                ) {
                    toastMessages.push(entry.message);
                }
            }
            if (toastMessages.length === 0 && !err.response) {
                // No structured body at all — network error / timeout.
                toastMessages.push('The request failed. Please try again.');
            }
            toastMessages.forEach((message) => toast.error(message));
            return { ok: false, errors: body?.errors, fieldErrors };
        }
    }

    function runClientAction(action: RuntimeAction, ctx: ExecutionContext) {
        switch (action.type) {
            case 'navigate': {
                const to = interpolateTemplate(action.to, ctx);
                if (typeof to === 'string' && to !== '') {
                    router.visit(resolveNavTo(to, ctx));
                }
                break;
            }
            case 'download_pdf': {
                // A hard navigation, not an Inertia visit: the response is a
                // file, and router.visit would be waiting for a page that
                // never comes. The browser takes the download and leaves the
                // app where it was.
                const slug = String(action.page_slug ?? '');
                if (slug === '') break;

                const query = new URLSearchParams();
                for (const [key, raw] of Object.entries(
                    (action.params as Record<string, unknown>) ?? {},
                )) {
                    const value = interpolateTemplate(String(raw ?? ''), ctx);
                    if (value !== '' && value !== null && value !== undefined) {
                        query.set(key, String(value));
                    }
                }

                // Signed-in runtime only. A portal grants a visitor their own
                // row, not a rendering of the business's pages — the route is
                // not mounted there, and offering a link that 404s is worse
                // than offering nothing.
                const mount = mountFor(ctx);
                if (!mount.startsWith('/r/')) break;

                const qs = query.toString();
                window.location.href =
                    `${mount}/${slug}/pdf` + (qs !== '' ? `?${qs}` : '');
                break;
            }
            case 'refresh':
                // `blockData` is a DEFERRED prop, and a deferred prop is left
                // OUT of a plain reload's response — the page would come back
                // with it undefined and every data block would render its
                // empty state ("No record selected.") until a full navigation.
                // Asking for it by name is the same partial request Inertia's
                // own deferred fetch makes, so the refreshed data arrives.
                // It is the only prop a refresh needs: the manifest, the page
                // params and the chrome cannot change without a navigation.
                router.reload({ only: ['blockData'] });
                break;
            case 'show_toast': {
                const message =
                    typeof action.message === 'string'
                        ? (interpolateTemplate(action.message, ctx) as string)
                        : '';
                if (message === '') {
                    break;
                }
                const level =
                    typeof action.level === 'string' ? action.level : 'info';
                switch (level) {
                    case 'success':
                        toast.success(message);
                        break;
                    case 'error':
                        toast.error(message);
                        break;
                    case 'warning':
                        toast.warning(message);
                        break;
                    default:
                        toast.info(message);
                }
                break;
            }
            case 'open_modal':
                if (typeof action.modal_block_id === 'string') {
                    // Resolve any {{row.id}}, {{form.x}} expressions inside
                    // the params payload against the calling execution
                    // context. Modal-side blocks read these as {{params.X}}.
                    const rawParams = action.params as
                        | Record<string, unknown>
                        | undefined;
                    const resolved: Record<string, unknown> = {};
                    if (rawParams && typeof rawParams === 'object') {
                        for (const [k, v] of Object.entries(rawParams)) {
                            resolved[k] = resolveClientValue(v, ctx);
                        }
                    }
                    modalBus.emit('open', action.modal_block_id, resolved);
                }
                break;
            case 'close_modal': {
                const id =
                    typeof action.modal_block_id === 'string'
                        ? action.modal_block_id
                        : undefined;
                modalBus.emit('close', id);
                break;
            }
        }
    }

    return { execute };
}
