import type { FieldDef } from '../types/manifest';

/**
 * Sensible empty value for a freshly-rendered form field, depending on its
 * shape. Shared between BlockForm and BlockMultiStepForm so they agree on
 * the initial state — otherwise updates to one would silently drift from
 * the other.
 */
export function initialFieldValue(field: FieldDef): unknown {
    switch (field.type) {
        case 'boolean':
            return false;
        case 'multi_select':
            return [];
        case 'rating':
            return (field as unknown as { default?: number }).default ?? 0;
        case 'slider': {
            const f = field as unknown as { default?: number; min?: number };
            return f.default ?? f.min ?? 0;
        }
        case 'date_range':
            return { from: '', to: '' };
        case 'file':
            return null;
        case 'rich_text':
            return (field as unknown as { default?: string }).default ?? '';
        default:
            return '';
    }
}
