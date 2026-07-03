/**
 * Deck manifest types + theme token sets for the slides module.
 *
 * A deck is authored (by the chat model or MCP callers) as a constrained JSON
 * manifest — never HTML — and rendered deterministically by the components in
 * `components/slides/`. The backend twin of these shapes is
 * `App\Services\Slides\DeckValidator`; keep both in sync.
 */

export type DeckLayout =
    | 'title'
    | 'section'
    | 'bullets'
    | 'two_column'
    | 'big_number'
    | 'metrics'
    | 'chart'
    | 'quote'
    | 'timeline'
    | 'roadmap'
    | 'table'
    | 'closing';

export interface DeckSlideDef {
    layout: DeckLayout;
    notes?: string;
    // Layout-specific fields (validated server-side); typed loosely here so
    // the dispatcher stays a single component.
    [key: string]: unknown;
}

export interface DeckManifest {
    title: string;
    theme?: string;
    slides: DeckSlideDef[];
}

export interface DeckBrand {
    accent: string | null;
    logo_url: string | null;
}

/**
 * Design tokens per theme, applied as CSS custom properties on the stage.
 * All sizes assume the fixed 1280×720 design canvas (the stage scales it).
 */
export interface DeckThemeTokens {
    bg: string;
    surface: string;
    ink: string;
    muted: string;
    subtle: string;
    line: string;
    /** Chart series colors, first = accent-driven. */
    series: string[];
}

export const DECK_THEMES: Record<string, DeckThemeTokens> = {
    executive: {
        bg: '#ffffff',
        surface: '#f4f6f8',
        ink: '#101828',
        muted: '#475467',
        subtle: '#98a2b3',
        line: '#e4e7ec',
        series: ['#0096ff', '#7c5cff', '#12b76a', '#f79009'],
    },
    dark: {
        bg: '#0b1220',
        surface: '#141d30',
        ink: '#f5f7fa',
        muted: '#aab4c5',
        subtle: '#66738a',
        line: '#243044',
        series: ['#38a6ff', '#9d87ff', '#32d583', '#fdb022'],
    },
    minimal: {
        bg: '#fafafa',
        surface: '#f1f1f2',
        ink: '#18181b',
        muted: '#52525b',
        subtle: '#a1a1aa',
        line: '#e4e4e7',
        series: ['#18181b', '#71717a', '#3f3f46', '#a1a1aa'],
    },
    bold: {
        bg: '#09090b',
        surface: '#18181f',
        ink: '#ffffff',
        muted: '#b8b8c2',
        subtle: '#6e6e7a',
        line: '#26262e',
        series: ['#ff5c35', '#ffd166', '#36d399', '#38a6ff'],
    },
};

/** Resolve a theme's tokens, overriding the first series color with the org accent. */
export function deckTheme(
    theme: string | undefined,
    accent: string | null,
): DeckThemeTokens {
    const base = DECK_THEMES[theme ?? 'executive'] ?? DECK_THEMES.executive;
    if (!accent) return base;
    return { ...base, series: [accent, ...base.series.slice(1)] };
}

export const DECK_LAYOUTS: DeckLayout[] = [
    'title',
    'section',
    'bullets',
    'two_column',
    'big_number',
    'metrics',
    'chart',
    'quote',
    'timeline',
    'roadmap',
    'table',
    'closing',
];

/**
 * A valid starter slide per layout, used by the Builder's "add slide" menu.
 * Placeholder copy is intentionally generic — the user (or the AI) replaces it.
 */
export function defaultSlide(layout: DeckLayout): DeckSlideDef {
    switch (layout) {
        case 'title':
            return {
                layout,
                title: 'Presentation title',
                subtitle: 'Subtitle',
            };
        case 'section':
            return { layout, title: 'New section', kicker: 'Section' };
        case 'bullets':
            return {
                layout,
                title: 'Key points',
                bullets: ['First point', 'Second point'],
            };
        case 'two_column':
            return {
                layout,
                title: 'Comparison',
                left: { heading: 'Option A', items: ['Point'] },
                right: { heading: 'Option B', items: ['Point'] },
            };
        case 'big_number':
            return { layout, value: '100', label: 'The headline metric' };
        case 'metrics':
            return {
                layout,
                title: 'Key metrics',
                items: [
                    { value: '1', label: 'Metric one' },
                    { value: '2', label: 'Metric two' },
                ],
            };
        case 'chart':
            return {
                layout,
                title: 'Chart',
                chart_type: 'bar',
                labels: ['A', 'B', 'C'],
                series: [{ name: 'Series', data: [3, 5, 2] }],
            };
        case 'quote':
            return { layout, quote: 'A quote worth a slide.' };
        case 'timeline':
            return {
                layout,
                title: 'Roadmap',
                items: [
                    { label: 'Q1', title: 'Milestone one', status: 'done' },
                    { label: 'Q2', title: 'Milestone two', status: 'active' },
                    {
                        label: 'Q3',
                        title: 'Milestone three',
                        status: 'upcoming',
                    },
                ],
            };
        case 'roadmap':
            return {
                layout,
                title: 'Roadmap',
                periods: ['Q1', 'Q2', 'Q3', 'Q4'],
                lanes: [
                    {
                        name: 'Workstream A',
                        bars: [
                            {
                                label: 'Phase one',
                                start: 1,
                                end: 2,
                                status: 'done',
                            },
                            {
                                label: 'Phase two',
                                start: 2,
                                end: 4,
                                status: 'active',
                            },
                        ],
                    },
                    {
                        name: 'Workstream B',
                        bars: [
                            {
                                label: 'Kickoff',
                                start: 3,
                                end: 4,
                                status: 'upcoming',
                            },
                        ],
                    },
                ],
            };
        case 'table':
            return {
                layout,
                title: 'Comparison',
                columns: ['Item', 'A', 'B'],
                rows: [
                    ['Row one', '—', '—'],
                    ['Row two', '—', '—'],
                ],
            };
        case 'closing':
            return { layout, title: 'Next steps', cta: 'Let’s go' };
    }
}
