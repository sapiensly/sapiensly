import { describe, expect, it } from 'vitest';
import { mayWaitForASignal } from './useActionExecutor';

/**
 * What is allowed to be done an hour late.
 *
 * The queue's honesty depends on this predicate as much as on the queue: an
 * action sequence that should not have waited is worse held than failed,
 * because the person walked away believing it was done.
 */
const seq = (...types: string[]) => types.map((type) => ({ type }));

describe('deciding what may wait for a signal', () => {
    it('lets the app’s own record writes wait', () => {
        // A work order closed in a basement is closed whenever the close lands.
        expect(mayWaitForASignal(seq('create_record'), null)).toBe(true);
        expect(mayWaitForASignal(seq('update_record', 'navigate'), null)).toBe(true);
        expect(mayWaitForASignal(seq('delete_record'), null)).toBe(true);
    });

    it('refuses a sequence that reaches outward', () => {
        // A workflow sends email, calls a webhook, messages a customer. Held
        // six hours it arrives about a visit that already ended, and there is
        // no undo on the other side of it.
        expect(mayWaitForASignal(seq('run_workflow'), null)).toBe(false);
        expect(mayWaitForASignal(seq('create_record', 'run_workflow'), null)).toBe(false);
    });

    it('refuses anything from the sandbox', () => {
        // A queued write is a plain POST with no environment binding of its
        // own, so the flush would land it wherever the session then points.
        // Sandbox work written into production is the worse failure.
        expect(mayWaitForASignal(seq('create_record'), 'demo')).toBe(false);
    });

    it('lets an empty sequence through rather than inventing a rule for it', () => {
        expect(mayWaitForASignal([], null)).toBe(true);
    });
});

describe('an app that says what it may leave on a device', () => {
    const create = (objectId: string) => [{ type: 'create_record', object_id: objectId }];

    it('holds nothing at all when the owner turned offline off', () => {
        expect(mayWaitForASignal(create('obj_ordenes'), null, { enabled: false, excluded_object_ids: [] })).toBe(false);
    });

    it('refuses only the writes that touch an excluded object', () => {
        // Per object, so the field app keeps working in the basement and the
        // payroll screen does not follow the technician home.
        const policy = { enabled: true, excluded_object_ids: ['obj_nominas'] };

        expect(mayWaitForASignal(create('obj_ordenes'), null, policy)).toBe(true);
        expect(mayWaitForASignal(create('obj_nominas'), null, policy)).toBe(false);
    });

    it('finds the object however deeply an action buries it', () => {
        // The list of places an object_id can appear is exactly the list that
        // goes stale, so nothing enumerates it. The server walks the page the
        // same way for the same reason.
        const policy = { enabled: true, excluded_object_ids: ['obj_nominas'] };
        const nested = [{ type: 'create_record', values: { lines: [{ object_id: 'obj_nominas' }] } }];

        expect(mayWaitForASignal(nested, null, policy)).toBe(false);
    });

    it('allows everything when no policy reached the client', () => {
        // The builder preview mounts blocks with no runtime page around them.
        // The default is what every surface did before this existed.
        expect(mayWaitForASignal(create('obj_ordenes'), null)).toBe(true);
    });
});
