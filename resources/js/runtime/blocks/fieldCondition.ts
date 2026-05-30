/**
 * Structured field condition used by a form field's `visible_if` / `required_if`.
 * Mirrors the manifest `field_condition` $def: a single comparison against the
 * live value of ANOTHER field in the same form. This is intentionally NOT an
 * expression language — it is evaluated reactively on the client with a plain
 * operator switch (no parser, no eval), so there is no dialect to keep in sync
 * with the server and no code-execution surface.
 */
export interface FieldCondition {
    field_id: string;
    op:
        | 'eq'
        | 'neq'
        | 'gt'
        | 'gte'
        | 'lt'
        | 'lte'
        | 'in'
        | 'not_in'
        | 'contains'
        | 'is_null'
        | 'is_not_null'
        | 'is_truthy'
        | 'is_falsy';
    value?: unknown;
}

function isBlank(v: unknown): boolean {
    return v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);
}

/** Loose equality matching the server's `==`: numeric when both sides parse as numbers, else string compare. */
function looseEq(a: unknown, b: unknown): boolean {
    if (typeof a === 'boolean' || typeof b === 'boolean') {
        return Boolean(a) === Boolean(b);
    }
    const na = Number(a);
    const nb = Number(b);
    if (a !== '' && b !== '' && !Number.isNaN(na) && !Number.isNaN(nb)) {
        return na === nb;
    }
    return String(a) === String(b);
}

function num(v: unknown): number {
    const n = Number(v);
    return Number.isNaN(n) ? NaN : n;
}

/**
 * Evaluate a condition against the form's current values.
 *
 * @param slugForFieldId resolves the condition's field_id to the slug under which
 *   the value is stored in formData (the manifest references fields by id, the
 *   form state is keyed by slug).
 */
export function evaluateFieldCondition(
    cond: FieldCondition,
    formData: Record<string, unknown>,
    slugForFieldId: (fieldId: string) => string | undefined,
): boolean {
    const slug = slugForFieldId(cond.field_id);
    const actual = slug === undefined ? undefined : formData[slug];
    const expected = cond.value;

    switch (cond.op) {
        case 'eq':
            return looseEq(actual, expected);
        case 'neq':
            return !looseEq(actual, expected);
        case 'gt':
            return num(actual) > num(expected);
        case 'gte':
            return num(actual) >= num(expected);
        case 'lt':
            return num(actual) < num(expected);
        case 'lte':
            return num(actual) <= num(expected);
        case 'in':
            return Array.isArray(expected) && expected.some((v) => looseEq(actual, v));
        case 'not_in':
            return !(Array.isArray(expected) && expected.some((v) => looseEq(actual, v)));
        case 'contains':
            if (Array.isArray(actual)) {
                return actual.some((v) => looseEq(v, expected));
            }
            return String(actual ?? '').includes(String(expected ?? ''));
        case 'is_null':
            return isBlank(actual);
        case 'is_not_null':
            return !isBlank(actual);
        case 'is_truthy':
            return Boolean(actual) && !isBlank(actual);
        case 'is_falsy':
            return !Boolean(actual) || isBlank(actual);
        default:
            return false;
    }
}
