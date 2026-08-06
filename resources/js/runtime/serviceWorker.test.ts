import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createContext, runInContext } from 'node:vm';
import { describe, expect, it } from 'vitest';

/**
 * The service worker's two decisions, tested away from a browser.
 *
 * `public/sw.js` is a script the browser runs, not a module anything can
 * import — so the rules that matter (what it is allowed to ANSWER for, and what
 * it is allowed to KEEP) would otherwise only be checkable by driving a real
 * browser offline. That test is worth having and is not the one you want on
 * every commit, so the worker exposes its predicates on `self.__swInternals`
 * and they are exercised here.
 *
 * Loading the real file rather than a copy of its regexes is the whole point:
 * a rule that drifts out of the worker fails here.
 */
const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8');

type SwInternals = {
    isRuntimePath: (url: URL) => boolean;
    isImmutableAsset: (url: URL) => boolean;
    isInertiaRequest: (request: { headers: Headers }) => boolean;
    cacheKey: (request: { url: string; headers: Headers }) => string;
    storable: (response: { status: number; type?: string; headers: Headers } | null) => boolean;
};

function loadWorker(): SwInternals {
    const self: Record<string, unknown> = {
        addEventListener: () => {},
        location: { origin: 'https://app.sapiensly.test' },
        clients: { claim: () => Promise.resolve() },
        skipWaiting: () => Promise.resolve(),
    };

    const context = createContext({ self, URL, Headers, Response, caches: undefined, fetch: undefined });
    runInContext(source, context);

    return self.__swInternals as SwInternals;
}

const sw = loadWorker();

const at = (path: string) => new URL(path, 'https://app.sapiensly.test');

const request = (url: string, headers: Record<string, string> = {}) => ({
    url,
    headers: new Headers(headers),
});

const response = (status: number, headers: Record<string, string> = {}, type = 'basic') => ({
    status,
    type,
    headers: new Headers(headers),
});

describe('what the worker is allowed to answer for', () => {
    it('serves the two mounts a built app lives at', () => {
        expect(sw.isRuntimePath(at('/r/servicio_campo'))).toBe(true);
        expect(sw.isRuntimePath(at('/r/servicio_campo/ordenes/rec_01k'))).toBe(true);
        expect(sw.isRuntimePath(at('/a/portal_clientes/inicio'))).toBe(true);
    });

    it('leaves the rest of the platform on the network', () => {
        // A stale admin screen is worse than no admin screen, and a cached
        // auth response is a bug with a CVE number.
        for (const path of ['/', '/admin/users', '/apps/servicio_campo/builder', '/login', '/api/apps', '/settings/profile']) {
            expect(sw.isRuntimePath(at(path))).toBe(false);
        }
    });

    it('does not treat a path that merely starts with the letter as a runtime path', () => {
        // `/reports` and `/account` open with r and a. The separator is what
        // makes the rule a prefix rather than a coincidence.
        expect(sw.isRuntimePath(at('/reports/monthly'))).toBe(false);
        expect(sw.isRuntimePath(at('/account'))).toBe(false);
    });

    it('caches build output and the runtime fonts, and nothing else static', () => {
        expect(sw.isImmutableAsset(at('/build/assets/app-B7f2Qk.js'))).toBe(true);
        expect(sw.isImmutableAsset(at('/fonts/instrument-serif-400.woff2'))).toBe(true);

        // Uploads are tenant data behind a signed url, not immutable output.
        expect(sw.isImmutableAsset(at('/storage/uploads/firma.png'))).toBe(false);
        expect(sw.isImmutableAsset(at('/favicon.svg'))).toBe(false);
    });
});

describe('what the worker is allowed to keep', () => {
    it('keeps an ordinary answer', () => {
        expect(sw.storable(response(200, { 'Cache-Control': 'private, max-age=300' }))).toBe(true);
        expect(sw.storable(response(200))).toBe(true);
    });

    it('refuses anything that is not a 200', () => {
        // A redirect to /login replayed offline would show the login page
        // inside the installed app and look like data loss.
        for (const status of [204, 302, 401, 403, 404, 500]) {
            expect(sw.storable(response(status))).toBe(false);
        }
    });

    it('honours no-store', () => {
        expect(sw.storable(response(200, { 'Cache-Control': 'no-store' }))).toBe(false);
        expect(sw.storable(response(200, { 'Cache-Control': 'private, no-store, max-age=0' }))).toBe(false);
    });

    it('refuses an opaque response, whose status it cannot read', () => {
        expect(sw.storable(response(0, {}, 'opaque'))).toBe(false);
    });

    it('survives having nothing to judge', () => {
        expect(sw.storable(null)).toBe(false);
    });
});

describe('telling Inertia’s two requests for one url apart', () => {
    it('recognises an Inertia visit', () => {
        expect(sw.isInertiaRequest(request('https://app.sapiensly.test/r/x', { 'X-Inertia': 'true' }))).toBe(true);
        expect(sw.isInertiaRequest(request('https://app.sapiensly.test/r/x'))).toBe(false);
    });

    it('keys the deferred props apart from the page they belong to', () => {
        // Inertia asks for the SAME url twice: the page, then `blockData`.
        // Keyed by url alone the second answer overwrites the first and the
        // page comes back from cache with no shell.
        const page = request('https://app.sapiensly.test/r/servicio_campo/ordenes');
        const deferred = request('https://app.sapiensly.test/r/servicio_campo/ordenes', {
            'X-Inertia-Partial-Data': 'blockData',
        });

        expect(sw.cacheKey(page)).toBe('https://app.sapiensly.test/r/servicio_campo/ordenes');
        expect(sw.cacheKey(deferred)).not.toBe(sw.cacheKey(page));
        expect(sw.cacheKey(deferred)).toContain('blockData');
    });

    it('keys two different partials apart from each other', () => {
        const blocks = request('https://app.sapiensly.test/r/x', { 'X-Inertia-Partial-Data': 'blockData' });
        const flash = request('https://app.sapiensly.test/r/x', { 'X-Inertia-Partial-Data': 'flash' });

        expect(sw.cacheKey(blocks)).not.toBe(sw.cacheKey(flash));
    });
});
