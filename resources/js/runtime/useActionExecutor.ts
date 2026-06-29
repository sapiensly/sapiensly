import { router } from '@inertiajs/vue3';
import axios from 'axios';
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
function currentPageSlug(): string | undefined {
    const m = window.location.pathname.match(
        /^\/r\/[a-z][a-z0-9_]*\/([a-z][a-z0-9_]*)/,
    );
    return m?.[1];
}

/**
 * Resolve a navigate `to` into a real in-app URL. A manifest authors page links
 * as a page reference ("pos", "/pos", "pos?order=1"), NOT the full runtime path
 * — but `router.visit("/pos")` would hit the host root and 404 (the app is
 * mounted under /r/{appSlug}/). So we rebase any in-app reference onto
 * /r/{appSlug}/<page>. Genuinely external/absolute URLs and already-rebased
 * (/r/…) paths pass through untouched.
 */
function resolveNavTo(to: string, ctx: ExecutionContext): string {
    if (to === '') return to;
    // External (http(s)://, protocol-relative //, mailto:, tel:) — leave alone.
    if (/^([a-z]+:)?\/\//i.test(to) || /^(mailto:|tel:)/i.test(to)) return to;
    // Already a runtime path.
    if (to.startsWith('/r/')) return to;
    if (!ctx.appSlug) return to;

    const ref = to.replace(/^\/+/, '');
    // A bare query/hash ("?order=1") means "this page" — keep the current slug.
    if (ref === '' || ref.startsWith('?') || ref.startsWith('#')) {
        const page = ctx.page ?? currentPageSlug() ?? '';
        return `/r/${ctx.appSlug}/${page}${ref}`;
    }

    return `/r/${ctx.appSlug}/${ref}`;
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
    async function execute(
        actions: RuntimeAction[],
        ctx: ExecutionContext,
    ): Promise<ExecutionResult> {
        if (actions.length === 0) {
            return { ok: true };
        }

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
                `/r/${ctx.appSlug}/actions`,
                {
                    actions,
                    params: ctx.params ?? {},
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
            case 'refresh':
                router.reload({ preserveScroll: true });
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
