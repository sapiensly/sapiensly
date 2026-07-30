import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';

/**
 * The organization identity screen: general facts, the Brandbook and the
 * Contextbook, one form and one save.
 *
 * All of it lives here rather than in the page component because the three parts
 * are not independent — reading the website proposes fields for BOTH books at
 * once, and both books are submitted in a single write. The panels that render
 * the two tabs are given the slices they need and stay templates.
 */

export interface Brand {
    logo_url: string | null;
    icon_url: string | null;
    logo_dark_url: string | null;
    icon_dark_url: string | null;
    accent_color: string | null;
    logo_bg_color: string | null;
    font: string | null;
    theme: string | null;
}

/**
 * The deterministic palette every app surface derives from an accent — the same
 * shape the backend (ColorPalette) and the AI proposal endpoint return.
 */
export interface Palette {
    ramp: Record<string, string>;
    soft: string;
    contrast: string;
    chart: string[];
}

export interface PaletteProposal {
    name: string;
    accent: string;
    rationale: string;
    palette: Palette;
}

export interface Pair {
    [key: string]: string;
}

export interface Context {
    descriptor: string | null;
    industry: string | null;
    size: string | null;
    website: string | null;
    audience: string | null;
    geographies: string[];
    timezone: string | null;
    currency: string | null;
    units: string | null;
    language: string | null;
    formality: string | null;
    tone_notes: string | null;
    glossary: Pair[];
    offerings: Pair[];
    never: string[];
    escalation: string | null;
    disclaimer: string | null;
    links: Pair[];
}

export interface IdentityProps {
    brand: Brand;
    palette: Palette;
    context: Context;
    enabled: boolean;
    preview: string;
    tokens: number;
    maxTokens: number;
    formalityOptions: string[];
    unitOptions: string[];
    updatedAt: string | null;
    lastImport: { url: string | null; at: string } | null;
}

/** A field the site reading wants to write, against what is stored. */
export interface DiffEntry {
    field: string;
    status: string;
    current: unknown;
    proposed: unknown;
}

/** The outcome of one reading of the website, for both books at once. */
export interface ImportStatus {
    /** The normalized URL that was actually read. */
    url: string | null;
    /** The page itself opened and could be read. */
    read: boolean;
    /** Something came out of the run — off the page, or off the brief alone. */
    drafted: boolean;
    /** Why the read failed — see SiteFetch on the server. */
    reason: string;
    /** Human labels of the fields that landed on the form without asking. */
    applied: string[];
    /** Decisions still owed, per book, so each tab can be pointed at. */
    pending: { brand: number; context: number };
    /** What the draft was built from: 'website', 'brief'. */
    sources: string[];
    /** Free-form remarks from the proposal (why an accent was refused, etc.). */
    notes: string[];
    /**
     * The page opened but had no words on it — a client-rendered site whose
     * content we do not run. The brand signals live in the markup and are read
     * either way; only the Contextbook comes back empty.
     */
    noProse: boolean;
}

export type BookTab = 'brand' | 'context';

/** The platform default accent (the --sp-accent-blue token). */
export const DEFAULT_ACCENT = '#0096ff';

const HEX_RE = /^#[0-9A-Fa-f]{6}$/;

type AssetKind = 'logo' | 'icon' | 'logo_dark' | 'icon_dark';

/**
 * Maps an upload kind to the form field that holds its URL, so the base and
 * dark-surface variants share one upload path.
 */
const ASSET_FIELD: Record<
    AssetKind,
    'logo_url' | 'icon_url' | 'logo_dark_url' | 'icon_dark_url'
> = {
    logo: 'logo_url',
    icon: 'icon_url',
    logo_dark: 'logo_dark_url',
    icon_dark: 'icon_dark_url',
};

/** Where a validation error is on screen: the Brandbook tab, or the header. */
const BRAND_FIELDS = [
    'logo_url',
    'icon_url',
    'logo_dark_url',
    'icon_dark_url',
    'accent_color',
    'logo_bg_color',
    'font',
    'theme',
];

/**
 * The general facts, which sit above the tabs and are always visible — including
 * `descriptor`, where the over-budget objection for the whole Contextbook lands.
 */
const HEADER_FIELDS = ['descriptor', 'industry', 'size', 'website'];

export function useOrganizationIdentity(props: IdentityProps) {
    const { t } = useI18n();

    const form = useForm({
        // Brandbook.
        logo_url: props.brand.logo_url ?? '',
        icon_url: props.brand.icon_url ?? '',
        logo_dark_url: props.brand.logo_dark_url ?? '',
        icon_dark_url: props.brand.icon_dark_url ?? '',
        accent_color: props.brand.accent_color ?? DEFAULT_ACCENT,
        logo_bg_color: props.brand.logo_bg_color ?? '',
        font: props.brand.font ?? '',
        theme: props.brand.theme ?? '',
        // General facts (stored with the Contextbook, read by both books).
        descriptor: props.context.descriptor ?? '',
        industry: props.context.industry ?? '',
        size: props.context.size ?? '',
        website: props.context.website ?? '',
        // Contextbook.
        audience: props.context.audience ?? '',
        geographies: [...props.context.geographies],
        timezone: props.context.timezone ?? '',
        currency: props.context.currency ?? '',
        units: props.context.units ?? '',
        language: props.context.language ?? '',
        formality: props.context.formality ?? '',
        tone_notes: props.context.tone_notes ?? '',
        glossary: props.context.glossary.map((entry) => ({ ...entry })),
        offerings: props.context.offerings.map((entry) => ({ ...entry })),
        never: [...props.context.never],
        escalation: props.context.escalation ?? '',
        disclaimer: props.context.disclaimer ?? '',
        links: props.context.links.map((entry) => ({ ...entry })),
        enabled: props.enabled,
    });

    const writable = form as unknown as Record<string, unknown>;

    // ---------------------------------------------------------------- payloads

    const blank = (value: string) =>
        value.trim() === '' ? null : value.trim();
    const rows = (list: Pair[], required: string) =>
        list.filter((row) => (row[required] ?? '').trim() !== '');

    /**
     * The Contextbook half, in the shape the server expects: blanks become null,
     * and half-filled rows are dropped rather than persisted as noise. Kept apart
     * from the brand half so the live preview only re-renders when the words the
     * models read actually change.
     */
    function contextPayload(): Record<string, unknown> {
        return {
            descriptor: blank(form.descriptor),
            industry: blank(form.industry),
            size: blank(form.size),
            website: blank(form.website),
            audience: blank(form.audience),
            geographies: form.geographies.filter(
                (entry) => entry.trim() !== '',
            ),
            timezone: blank(form.timezone),
            currency: blank(form.currency),
            units: blank(form.units),
            language: blank(form.language),
            formality: blank(form.formality),
            tone_notes: blank(form.tone_notes),
            glossary: rows(form.glossary, 'term'),
            offerings: rows(form.offerings, 'name'),
            never: form.never.filter((entry) => entry.trim() !== ''),
            escalation: blank(form.escalation),
            disclaimer: blank(form.disclaimer),
            links: rows(form.links, 'url'),
        };
    }

    /** Empty strings → null so the server clears (and the hex validator passes). */
    function brandPayload(): Record<string, unknown> {
        return {
            logo_url: blank(form.logo_url),
            icon_url: blank(form.icon_url),
            logo_dark_url: blank(form.logo_dark_url),
            icon_dark_url: blank(form.icon_dark_url),
            accent_color: blank(form.accent_color),
            logo_bg_color: blank(form.logo_bg_color),
            font: blank(form.font),
            theme: blank(form.theme),
        };
    }

    // ------------------------------------------------------------------- tabs

    const tab = ref<BookTab>('brand');

    /** How many validation errors are waiting inside each tab (not the header). */
    const errorCount = computed<Record<BookTab, number>>(() => {
        const keys = Object.keys(form.errors);

        return {
            brand: keys.filter((key) => BRAND_FIELDS.includes(key)).length,
            context: keys.filter(
                (key) =>
                    !BRAND_FIELDS.includes(key) && !HEADER_FIELDS.includes(key),
            ).length,
        };
    });

    function openTab(next: BookTab): void {
        tab.value = next;

        // Keep the address honest so a reload — or a link someone shares — comes
        // back to the tab they were on.
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', next);
            window.history.replaceState({}, '', url);
        }
    }

    /** Open the tab the URL asks for (the old per-book addresses redirect here). */
    function openInitialTab(): void {
        if (typeof window === 'undefined') return;

        const asked = new URL(window.location.href).searchParams.get('tab');
        if (asked === 'brand' || asked === 'context') {
            tab.value = asked;
        }
    }

    // -------------------------------------------------------------- Brandbook

    const uploading = ref<Record<AssetKind, boolean>>({
        logo: false,
        icon: false,
        logo_dark: false,
        icon_dark: false,
    });

    /**
     * A logo drawn in light ink, which a light surface renders as nothing at all.
     * The server reads the tone while it holds the bytes — on an upload as much as
     * on a site import, because someone picking their white logo by hand hits the
     * same wall. The Brandbook's answer is the backdrop colour; applying it stays
     * one deliberate click, since a tone reading is a signal, not a verdict.
     */
    const backdropAdvice = ref<string | null>(null);

    function noteTone(res: {
        needs_backdrop?: boolean;
        suggested_logo_bg_color?: string;
    }): void {
        backdropAdvice.value =
            res.needs_backdrop && !form.logo_bg_color
                ? (res.suggested_logo_bg_color ?? null)
                : null;
    }

    function applyBackdrop(): void {
        if (backdropAdvice.value) {
            form.logo_bg_color = backdropAdvice.value;
            backdropAdvice.value = null;
        }
    }

    /** Upload a logo/icon file (base or dark variant); on success store the URL. */
    async function uploadAsset(kind: AssetKind, event: Event): Promise<void> {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0];
        if (!file) return;

        uploading.value[kind] = true;
        try {
            const data = new FormData();
            data.append('kind', kind);
            data.append('file', file);
            const { data: res } = await axios.post(
                '/settings/organization/brand/asset',
                data,
            );
            writable[ASSET_FIELD[kind]] = res.url;
            noteTone(res);
            toast.success(t('settings.brand.asset_uploaded'));
        } catch {
            toast.error(t('settings.brand.asset_failed'));
        } finally {
            uploading.value[kind] = false;
            input.value = '';
        }
    }

    // The palette currently in effect, shown live: seeded from the saved accent
    // and re-derived (server-side, no colour-maths port) when the accent changes.
    const activePalette = ref<Palette>(props.palette);
    let deriveTimer: ReturnType<typeof setTimeout> | undefined;

    watch(
        () => form.accent_color,
        (accent) => {
            if (!HEX_RE.test(accent)) return;
            clearTimeout(deriveTimer);
            deriveTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.post(
                        '/settings/organization/brand/palette',
                        { accent },
                    );
                    activePalette.value = data.palette;
                } catch {
                    // Keep the last good palette on a transient failure.
                }
            }, 250);
        },
    );

    const paletteBrief = ref('');
    const paletteGenerating = ref(false);
    const paletteProposals = ref<PaletteProposal[]>([]);

    async function generatePalettes(): Promise<void> {
        paletteGenerating.value = true;
        try {
            const { data } = await axios.post(
                '/settings/organization/brand/palette-proposals',
                { brief: paletteBrief.value || null },
            );
            paletteProposals.value = data.proposals ?? [];
            if (data.generated_by === 'fallback') {
                toast.info(t('settings.brand.palette_fallback'));
            }
        } catch {
            toast.error(t('settings.brand.palette_failed'));
        } finally {
            paletteGenerating.value = false;
        }
    }

    /** Adopt a proposal's accent on the form; the Save button persists it. */
    function applyProposal(proposal: PaletteProposal): void {
        form.accent_color = proposal.accent;
        activePalette.value = proposal.palette;
        toast.success(t('settings.brand.palette_applied'));
    }

    // ------------------------------------------------------------ Contextbook

    // Live preview of the exact block the models will read, rendered server-side
    // so there is one renderer, not two that can drift.
    const preview = ref(props.preview);
    const tokens = ref(props.tokens);
    const previewing = ref(false);
    let previewTimer: ReturnType<typeof setTimeout> | undefined;

    watch(
        () => contextPayload(),
        () => {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(async () => {
                previewing.value = true;
                try {
                    const { data } = await axios.post(
                        '/settings/organization/context/preview',
                        contextPayload(),
                    );
                    preview.value = data.preview;
                    tokens.value = data.tokens;
                } catch {
                    // Keep the last good preview on a transient failure.
                } finally {
                    previewing.value = false;
                }
            }, 400);
        },
        { deep: true },
    );

    const overBudget = computed(() => tokens.value > props.maxTokens);

    function addRow(list: Pair[], keys: string[]): void {
        list.push(Object.fromEntries(keys.map((key) => [key, ''])));
    }

    function removeAt<T>(list: T[], index: number): void {
        list.splice(index, 1);
    }

    // ------------------------------------------------------------ site import

    const brief = ref('');
    const reading = ref(false);
    const status = ref<ImportStatus | null>(null);

    /** Decisions owed on fields the organization had already filled, per book. */
    const brandConflicts = ref<DiffEntry[]>([]);
    const contextConflicts = ref<DiffEntry[]>([]);

    /**
     * Assets whose copy-to-storage failed (no object storage configured, the host
     * went away mid-flow). They stay here rather than vanishing: a field silently
     * dropped is one the user cannot get back without re-reading the whole site.
     */
    const brandFailures = ref<
        { field: string; value: string; message: string }[]
    >([]);

    const FIELD_LABELS = computed<Record<string, string>>(() => ({
        logo_url: t('settings.brand.logo'),
        icon_url: t('settings.brand.icon'),
        accent_color: t('settings.brand.accent'),
        font: t('settings.brand.font'),
        theme: t('settings.brand.theme'),
        descriptor: t('settings.context.descriptor'),
        industry: t('settings.context.industry'),
        size: t('settings.context.size'),
        website: t('settings.context.website'),
        audience: t('settings.context.audience'),
        geographies: t('settings.context.geographies'),
        currency: t('settings.context.currency'),
        language: t('settings.context.language'),
        formality: t('settings.context.formality'),
        glossary: t('settings.context.glossary'),
        offerings: t('settings.context.offerings'),
        links: t('settings.context.links'),
    }));

    function label(field: string): string {
        return FIELD_LABELS.value[field] ?? field;
    }

    /**
     * Copy a remote image onto the tenant's disk before adopting it, so the brand
     * does not depend on someone else's server staying up. Only runs on accept.
     */
    async function adoptAsset(
        field: string,
        url: string,
    ): Promise<string | null> {
        try {
            const { data } = await axios.post(
                '/settings/organization/brand/asset/import',
                { kind: field === 'logo_url' ? 'logo' : 'icon', url },
            );
            noteTone(data);
            return data.url;
        } catch (error) {
            const message =
                (error as { response?: { data?: { message?: string } } })
                    .response?.data?.message ??
                t('settings.brand.asset_failed');
            // Remember it so the user can retry without re-reading the whole site.
            brandFailures.value = [
                ...brandFailures.value.filter((entry) => entry.field !== field),
                { field, value: url, message },
            ];
            toast.error(message);
            return null;
        }
    }

    /** Returns whether the brand field actually landed on the form. */
    async function applyBrandField(
        field: string,
        value: unknown,
    ): Promise<boolean> {
        if (typeof value !== 'string') return false;

        const stored =
            field === 'logo_url' || field === 'icon_url'
                ? await adoptAsset(field, value)
                : value;

        if (stored === null) return false;

        writable[field] = stored;
        brandFailures.value = brandFailures.value.filter(
            (entry) => entry.field !== field,
        );

        return true;
    }

    /** Write one drafted Contextbook field onto the form, whatever its shape. */
    function applyContextField(field: string, value: unknown): void {
        if (Array.isArray(value)) {
            writable[field] = value.map((entry) =>
                typeof entry === 'object' && entry !== null
                    ? { ...entry }
                    : entry,
            );
            return;
        }
        if (typeof value === 'string') {
            writable[field] = value;
        }
    }

    /**
     * One reading of the site, both books. Fields the organization left empty are
     * filled straight away; anything that would replace something it already said
     * becomes a decision in its own tab. Nothing is saved until Save.
     */
    async function readSite(): Promise<void> {
        reading.value = true;
        brandConflicts.value = [];
        contextConflicts.value = [];
        brandFailures.value = [];
        status.value = null;

        try {
            const { data } = await axios.post(
                '/settings/organization/site-import',
                {
                    website: form.website || null,
                    brief: brief.value || null,
                },
            );

            const brandDiff = (data.brand?.diff ?? []) as DiffEntry[];
            const contextDiff = (data.context?.diff ?? []) as DiffEntry[];
            const applied: string[] = [];

            for (const entry of brandDiff.filter((e) => e.status === 'new')) {
                if (await applyBrandField(entry.field, entry.proposed)) {
                    applied.push(label(entry.field));
                }
            }
            brandConflicts.value = brandDiff.filter(
                (entry) => entry.status === 'conflict',
            );

            contextDiff
                .filter((entry) => entry.status === 'new')
                .forEach((entry) => {
                    applyContextField(entry.field, entry.proposed);
                    applied.push(label(entry.field));
                });
            contextConflicts.value = contextDiff.filter(
                (entry) => entry.status === 'conflict',
            );

            status.value = {
                url: data.url ?? null,
                read: Boolean(data.read),
                // A brief alone is material enough: the draft did not need the
                // site, so a missing address is not a failure of this run.
                drafted: Boolean(data.read) || Boolean(data.context?.generated),
                reason: String(data.reason ?? ''),
                applied,
                pending: {
                    brand: brandConflicts.value.length,
                    context: contextConflicts.value.length,
                },
                sources: data.context?.sources ?? [],
                notes: data.brand?.notes ?? [],
                noProse: Boolean(data.read) && !data.context?.site_has_prose,
            };

            // Adopt what was actually fetched, so the stored website is the one
            // that works rather than the shorthand somebody typed.
            if (typeof data.url === 'string') {
                form.website = data.url;
            }

            // The summary is inline; only the decisions still owed are worth a
            // nudge the user might otherwise scroll past.
            const pending =
                brandConflicts.value.length + contextConflicts.value.length;
            if (pending) {
                toast.info(t('site_import.conflicts', { count: pending }));
            }
        } catch {
            toast.error(t('site_import.failed'));
        } finally {
            reading.value = false;
        }
    }

    async function acceptBrandConflict(
        field: string,
        value: unknown,
    ): Promise<void> {
        // A failed accept keeps its card: the user asked for this value and has to
        // be able to ask again, rather than watch it disappear.
        if (await applyBrandField(field, value)) {
            brandConflicts.value = brandConflicts.value.filter(
                (entry) => entry.field !== field,
            );
        }
    }

    function dismissBrandConflict(field: string): void {
        brandConflicts.value = brandConflicts.value.filter(
            (entry) => entry.field !== field,
        );
    }

    function acceptContextConflict(field: string, value: unknown): void {
        applyContextField(field, value);
        contextConflicts.value = contextConflicts.value.filter(
            (entry) => entry.field !== field,
        );
    }

    function dismissContextConflict(field: string): void {
        contextConflicts.value = contextConflicts.value.filter(
            (entry) => entry.field !== field,
        );
    }

    async function retryFailure(field: string, value: string): Promise<void> {
        await applyBrandField(field, value);
    }

    function dismissFailure(field: string): void {
        brandFailures.value = brandFailures.value.filter(
            (entry) => entry.field !== field,
        );
    }

    // ------------------------------------------------------------------- save

    /** One write for both books, so a reading of the site is saved in one go. */
    function submit(): void {
        form.transform(() => ({
            ...brandPayload(),
            ...contextPayload(),
            enabled: form.enabled,
        })).put('/settings/organization/identity', {
            preserveScroll: true,
            onSuccess: () => toast.success(t('settings.identity.saved')),
            onError: () => {
                // Never leave the objection in a tab the user cannot see.
                if (errorCount.value.brand && !errorCount.value.context) {
                    openTab('brand');
                } else if (errorCount.value.context) {
                    openTab('context');
                }
            },
        });
    }

    // The three slices are handed out as reactive objects rather than refs: the
    // panels that render them are templates, and `book.conflicts.length` there
    // beats `.value` on every line.
    return {
        form,
        tab,
        openTab,
        openInitialTab,
        errorCount,
        submit,
        /** The Brandbook tab's own state and actions. */
        brand: reactive({
            uploading,
            backdropAdvice,
            applyBackdrop,
            uploadAsset,
            activePalette,
            paletteBrief,
            paletteGenerating,
            paletteProposals,
            generatePalettes,
            applyProposal,
            conflicts: brandConflicts,
            failures: brandFailures,
            acceptConflict: acceptBrandConflict,
            dismissConflict: dismissBrandConflict,
            retryFailure,
            dismissFailure,
            label,
        }),
        /** The Contextbook tab's own state and actions. */
        context: reactive({
            preview,
            tokens,
            previewing,
            overBudget,
            addRow,
            removeAt,
            conflicts: contextConflicts,
            acceptConflict: acceptContextConflict,
            dismissConflict: dismissContextConflict,
            label,
        }),
        /** The one reading both books are drafted from. */
        site: reactive({
            brief,
            reading,
            status,
            readSite,
        }),
    };
}

export type OrganizationIdentity = ReturnType<typeof useOrganizationIdentity>;
