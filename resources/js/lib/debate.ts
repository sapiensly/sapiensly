import DOMPurify from 'dompurify';
import { marked } from 'marked';

/**
 * Per-participant accent classes. Kept as literal strings so Tailwind's
 * scanner keeps them. Keyed by the `accent` token the backend assigns.
 */
export interface AccentClasses {
    dot: string;
    text: string;
    border: string;
    soft: string;
    ring: string;
}

const ACCENTS: Record<string, AccentClasses> = {
    violet: {
        dot: 'bg-violet-500',
        text: 'text-violet-500',
        border: 'border-violet-500/40',
        soft: 'bg-violet-500/10',
        ring: 'ring-violet-500/30',
    },
    emerald: {
        dot: 'bg-emerald-500',
        text: 'text-emerald-500',
        border: 'border-emerald-500/40',
        soft: 'bg-emerald-500/10',
        ring: 'ring-emerald-500/30',
    },
    amber: {
        dot: 'bg-amber-500',
        text: 'text-amber-500',
        border: 'border-amber-500/40',
        soft: 'bg-amber-500/10',
        ring: 'ring-amber-500/30',
    },
    sky: {
        dot: 'bg-sky-500',
        text: 'text-sky-500',
        border: 'border-sky-500/40',
        soft: 'bg-sky-500/10',
        ring: 'ring-sky-500/30',
    },
    rose: {
        dot: 'bg-rose-500',
        text: 'text-rose-500',
        border: 'border-rose-500/40',
        soft: 'bg-rose-500/10',
        ring: 'ring-rose-500/30',
    },
    teal: {
        dot: 'bg-teal-500',
        text: 'text-teal-500',
        border: 'border-teal-500/40',
        soft: 'bg-teal-500/10',
        ring: 'ring-teal-500/30',
    },
    fuchsia: {
        dot: 'bg-fuchsia-500',
        text: 'text-fuchsia-500',
        border: 'border-fuchsia-500/40',
        soft: 'bg-fuchsia-500/10',
        ring: 'ring-fuchsia-500/30',
    },
    orange: {
        dot: 'bg-orange-500',
        text: 'text-orange-500',
        border: 'border-orange-500/40',
        soft: 'bg-orange-500/10',
        ring: 'ring-orange-500/30',
    },
    indigo: {
        dot: 'bg-indigo-500',
        text: 'text-indigo-500',
        border: 'border-indigo-500/40',
        soft: 'bg-indigo-500/10',
        ring: 'ring-indigo-500/30',
    },
};

const FALLBACK: AccentClasses = {
    dot: 'bg-accent-blue',
    text: 'text-accent-blue',
    border: 'border-accent-blue/40',
    soft: 'bg-accent-blue/10',
    ring: 'ring-accent-blue/30',
};

export function accentClasses(
    accent: string | null | undefined,
): AccentClasses {
    return (accent && ACCENTS[accent]) || FALLBACK;
}

export function renderMarkdown(content: string | null): string {
    if (!content) return '';
    const raw = marked.parse(content, {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
}
