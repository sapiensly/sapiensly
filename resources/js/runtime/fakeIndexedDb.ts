/**
 * A minimal in-memory IndexedDB, for tests only.
 *
 * jsdom does not implement IndexedDB, and the offline write queue is mostly a
 * loop over one — so without this the parts worth testing (FIFO order, stopping
 * at the first unreachable entry, a refused write moving to the rejected store)
 * could only be checked by driving a real browser offline.
 *
 * Deliberately not a dependency: `fake-indexeddb` is a full spec implementation
 * and this needs six methods. It covers exactly what `offlineQueue.ts` calls,
 * and nothing else — if that file starts using a cursor, this will throw rather
 * than quietly answer wrong.
 *
 * Not imported by any shipping module; it is referenced only from tests, so it
 * is tree-shaken out of every bundle.
 */

type Row = { id: string } & Record<string, unknown>;

class FakeRequest<T> {
    onsuccess: (() => void) | null = null;

    onerror: (() => void) | null = null;

    onupgradeneeded: (() => void) | null = null;

    result!: T;

    error: unknown = null;
}

class FakeObjectStore {
    constructor(private rows: Map<string, Row>) {}

    add(row: Row) {
        return this.settle(() => {
            if (this.rows.has(row.id)) {
                throw new Error('ConstraintError');
            }
            this.rows.set(row.id, row);
        });
    }

    put(row: Row) {
        return this.settle(() => void this.rows.set(row.id, row));
    }

    delete(id: string) {
        return this.settle(() => void this.rows.delete(id));
    }

    clear() {
        return this.settle(() => this.rows.clear());
    }

    count() {
        return this.settle(() => this.rows.size);
    }

    getAll() {
        return this.settle(() => [...this.rows.values()]);
    }

    private settle<T>(work: () => T): FakeRequest<T> {
        const request = new FakeRequest<T>();

        queueMicrotask(() => {
            try {
                request.result = work();
                request.onsuccess?.();
            } catch (e) {
                request.error = e;
                request.onerror?.();
            }
        });

        return request;
    }
}

class FakeTransaction {
    oncomplete: (() => void) | null = null;

    constructor(private stores: Map<string, Map<string, Row>>) {
        queueMicrotask(() => queueMicrotask(() => this.oncomplete?.()));
    }

    objectStore(name: string): FakeObjectStore {
        const rows = this.stores.get(name);
        if (!rows) {
            throw new Error(`Unknown object store ${name}`);
        }

        return new FakeObjectStore(rows);
    }
}

class FakeDatabase {
    readonly stores = new Map<string, Map<string, Row>>();

    objectStoreNames = {
        contains: (name: string) => this.stores.has(name),
    };

    createObjectStore(name: string) {
        this.stores.set(name, new Map());
    }

    /**
     * A transaction over ONE store, the way `offlineQueue.ts` opens it. Naming
     * an unknown store throws here rather than answering, so a typo in the
     * store name fails the test instead of silently reading an empty map.
     */
    transaction(name: string) {
        if (!this.stores.has(name)) {
            throw new Error(`Unknown object store ${name}`);
        }

        return new FakeTransaction(this.stores);
    }

    close() {}
}

/**
 * Install the fake as the global `indexedDB`, and return a handle that clears
 * every store between tests.
 */
export function installFakeIndexedDb(): { reset: () => void; database: FakeDatabase } {
    const database = new FakeDatabase();

    (globalThis as Record<string, unknown>).indexedDB = {
        open() {
            const request = new FakeRequest<FakeDatabase>();
            request.result = database;

            queueMicrotask(() => {
                request.onupgradeneeded?.();
                request.onsuccess?.();
            });

            return request;
        },
    };

    return {
        database,
        reset: () => database.stores.forEach((rows) => rows.clear()),
    };
}
