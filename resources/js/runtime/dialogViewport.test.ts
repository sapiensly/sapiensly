import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * A dialog must never be taller than the screen it opens on.
 *
 * Reported from a phone: big modals "appear halfway and do not scroll". The
 * cause was one line in the shared component — centred with a translate, no
 * height bound, no overflow. Content taller than the viewport then overflowed
 * in BOTH directions: the bottom went past the fold and the top went ABOVE it,
 * off-screen and unreachable, with nothing to scroll.
 *
 * Asserted against the source text rather than a mounted component, because
 * what is being checked is a set of utility classes on a vendored shadcn file —
 * jsdom has no layout, so mounting it would prove nothing about height. This is
 * a lock on the fix, and it is the honest kind: it says exactly what it checks.
 */
const read = (path: string) =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

const BASES = [
    'resources/js/components/ui/dialog/DialogContent.vue',
    'resources/js/components/ui/alert-dialog/AlertDialogContent.vue',
    'resources/js/runtime/ConfirmDialog.vue',
];

describe('every dialog the product centres on the viewport', () => {
    it.each(BASES)(
        'bounds its height against the visible viewport — %s',
        (path) => {
            const source = read(path);

            expect(source).toMatch(/max-h-\[calc\(100dvh/);
        },
    );

    it('takes the whole screen on a phone, and only becomes a card from sm up', () => {
        // A dialog holding a twelve-field form has nothing to gain from a
        // gutter and a shadow: every pixel spent on framing is a pixel not
        // spent on the question, and a card that ends before the fold makes it
        // look as though the form does too.
        const source = read(
            'resources/js/components/ui/dialog/DialogContent.vue',
        );

        expect(source).toContain('fixed inset-0');
        expect(source).toContain('h-full');
        expect(source).toContain('max-w-none');
        // The centring — and everything that only makes sense on a card — is
        // behind the breakpoint.
        expect(source).toContain('sm:translate-x-[-50%]');
        expect(source).toContain('sm:rounded-lg');
        // The zoom too: full-screen it reads as the page lurching.
        expect(source).not.toMatch(/(?<!sm:)data-\[state=open\]:zoom-in-95/);
    });

    it.each(BASES)('scrolls what does not fit — %s', (path) => {
        expect(read(path)).toContain('overflow-y-auto');
    });

    it.each(BASES)(
        'does not let the page behind it scroll instead — %s',
        (path) => {
            // Reaching the end of a scrolling modal and having the page underneath
            // start moving is the other half of "it does not scroll properly".
            expect(read(path)).toContain('overscroll-contain');
        },
    );
});

/**
 * A page that scrolls sideways takes every dialog on it down.
 *
 * A `position: fixed` element is laid out against the viewport — but once the
 * DOCUMENT is wider than the screen, that viewport is no longer what you can
 * see, and a full-width dialog ends up with its right edge, and the close
 * button pinned to it, past the visible area. A row of five tabs on a phone was
 * enough to do it.
 *
 * So the rule is one level up from the dialog: a block that outgrows the screen
 * scrolls inside ITSELF and never widens the document.
 */
describe('nothing in the runtime widens the page', () => {
    it('slides a row of tabs instead of letting it push the page out', () => {
        const source = read('resources/js/components/ui/tabs/TabsList.vue');

        expect(source).toContain('max-w-full');
        expect(source).toContain('overflow-x-auto');
        // Centred inside a box narrower than itself, a flex row overflows in
        // both directions and its first tab cannot be scrolled back to.
        expect(source).toContain('justify-start');
        expect(source).not.toContain('justify-center');
    });

    it('clips what still gets past that, on every runtime layout', () => {
        // `clip` rather than `hidden`: it does not create a scroll container,
        // so the sticky table header and the sticky form actions still work.
        // Counted in the class attributes, not in the prose around them: the
        // three layout roots are the landing surface, the sidebar layout and
        // the top-header one, and a fourth would need this too.
        const source = read('resources/js/pages/runtime/Page.vue');
        const applied =
            source.match(/class="[^"]*overflow-x-clip[^"]*"/g) ?? [];

        expect(applied).toHaveLength(3);
        expect(applied.every((c) => c.includes('min-h-screen'))).toBe(true);
    });
});

/**
 * `vh` is not the viewport you can see.
 *
 * On a phone it measures the viewport with the browser chrome hidden, so an
 * `85vh` dialog is taller than the screen while the address bar is up — the
 * exact way a modal ends up cut off on the device where it matters most. Every
 * dialog in the product sizes itself in `dvh`.
 */
describe('sizing a dialog in the unit that matches what you can see', () => {
    const SIZED = [
        'resources/js/components/ui/dialog/DialogContent.vue',
        'resources/js/components/ui/alert-dialog/AlertDialogContent.vue',
        'resources/js/runtime/ConfirmDialog.vue',
        'resources/js/components/admin/OpenRouterModelsDialog.vue',
        'resources/js/components/bot-flows/AgentCreateModal.vue',
        'resources/js/components/bot-flows/CapabilityModal.vue',
        'resources/js/components/builder/ManualChat.vue',
        'resources/js/components/apps/builder/ApiKeysPanel.vue',
        'resources/js/components/apps/builder/ImportPanel.vue',
        'resources/js/components/documents/ArtifactEditor.vue',
        'resources/js/components/documents/DocumentSelectorDialog.vue',
    ];

    it.each(SIZED)('uses dvh rather than vh — %s', (path) => {
        expect(read(path)).not.toMatch(/-\[[^\]]*\d+vh[^\]]*\]/);
    });
});
