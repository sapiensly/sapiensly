import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installFakeIndexedDb } from './fakeIndexedDb';

const fake = installFakeIndexedDb();

const { collectHeldIds, holdFile, isHeldFileId, purgeFiles, releaseFiles, resolveHeldFiles } =
    await import('./offlineFiles');

/**
 * A photo taken where there is no signal.
 *
 * The half without which the write queue is a promise the app cannot keep: a
 * work order closes with the photo of the meter and the customer's signature on
 * it, and both are uploads.
 */
const png = (bytes = 32) => new Blob([new Uint8Array(bytes)], { type: 'image/png' });

/** A record payload shaped the way the runtime actually sends one. */
const payloadWith = (file: unknown) => ({
    actions: [{ type: 'create_record', object_id: 'obj_1', values: { folio: 'OT-1', foto: file } }],
    form: {},
});

beforeEach(async () => {
    fake.reset();
    // jsdom has no object URLs; the preview href is not what these test.
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:fake');
});

describe('holding bytes that could not be uploaded', () => {
    it('hands back something a form field can use', async () => {
        const held = await holdFile(png(), 'medidor.png');

        expect(held).not.toBeNull();
        expect(held!.original_name).toBe('medidor.png');
        expect(held!.mime).toBe('image/png');
        // Marked, so the preview can say the bytes are on this device — a
        // preview identical to an uploaded file has somebody drive away
        // believing the photo is in the record.
        expect(held!.pending).toBe(true);
        expect(isHeldFileId(held!.file_id)).toBe(true);
    });

    it('refuses once the device is holding all it may', async () => {
        // A cap we chose, so we can say WHY. Hitting the browser's own quota
        // produces an opaque failure halfway through a write instead.
        // Sized rather than allocated: the rule is arithmetic on `size`, and
        // really making 90 MB of zeroes tests the allocator.
        const huge = { size: 90 * 1024 * 1024, type: 'image/png' } as Blob;
        expect(await holdFile(huge, 'grande.png')).not.toBeNull();

        const overflow = { size: 20 * 1024 * 1024, type: 'image/png' } as Blob;
        expect(await holdFile(overflow, 'otra.png')).toBeNull();
    });
});

describe('finding held files in a write', () => {
    it('sees one nested anywhere in the payload', async () => {
        const held = await holdFile(png(), 'firma.png');

        expect(collectHeldIds(payloadWith(held))).toEqual([held!.file_id]);
    });

    it('ignores a file that was really uploaded', () => {
        const real = { file_id: 'fil_01k9abc', url: '/r/x/files/fil_01k9abc', mime: 'image/png' };

        expect(collectHeldIds(payloadWith(real))).toEqual([]);
    });
});

describe('resolving them just before the write is sent', () => {
    it('swaps the whole value, not just the id', async () => {
        // Replacing only the id would leave a blob: url pointing at a page that
        // closed hours ago, stored in the record forever.
        const held = await holdFile(png(), 'medidor.png');
        const real = { file_id: 'fil_real', url: '/r/x/files/fil_real', mime: 'image/png', size_bytes: 32 };

        const out = await resolveHeldFiles(payloadWith(held), async () => ({ outcome: 'ok', file: real }));

        expect(out.outcome).toBe('ok');
        expect(JSON.stringify(out.payload)).toContain('fil_real');
        expect(JSON.stringify(out.payload)).not.toContain('blob:fake');
    });

    it('leaves the payload untouched when the upload cannot reach a server', async () => {
        const held = await holdFile(png(), 'medidor.png');
        const before = payloadWith(held);

        const out = await resolveHeldFiles(before, async () => ({ outcome: 'unreachable' }));

        expect(out.outcome).toBe('unreachable');
        // Untouched, so the entry can be retried whole. A half-resolved payload
        // reaching disk is how a record ends up referring to one real file and
        // one placeholder.
        expect(out.payload).toBe(before);
    });

    it('refuses the write when an upload is refused', async () => {
        // A record that points at a photo which failed to upload is worse than
        // one not written yet: it is written, it looks complete, and the photo
        // is gone.
        const held = await holdFile(png(), 'medidor.png');

        const out = await resolveHeldFiles(payloadWith(held), async () => ({
            outcome: 'refused',
            reason: 'File too large.',
        }));

        expect(out.outcome).toBe('refused');
        expect(out.reason).toBe('File too large.');
    });

    it('refuses when the bytes are gone', async () => {
        // A purge, or a browser evicting storage. Sending a record that points
        // at nothing would be worse than saying so.
        const held = await holdFile(png(), 'medidor.png');
        await purgeFiles();

        const out = await resolveHeldFiles(payloadWith(held), async () => ({ outcome: 'ok', file: {} }));

        expect(out.outcome).toBe('refused');
        expect(out.reason).toContain(held!.file_id);
    });

    it('does not touch the network for a write with no attachments', async () => {
        const upload = vi.fn();

        const out = await resolveHeldFiles(payloadWith({ file_id: 'fil_real' }), upload);

        expect(out.outcome).toBe('ok');
        expect(upload).not.toHaveBeenCalled();
    });
});

describe('letting go', () => {
    it('frees the budget once the bytes are on the server', async () => {
        const held = await holdFile(png(), 'medidor.png');

        await releaseFiles([held!.file_id]);

        const out = await resolveHeldFiles(payloadWith(held), async () => ({ outcome: 'ok', file: {} }));
        expect(out.outcome).toBe('refused');
    });
});
