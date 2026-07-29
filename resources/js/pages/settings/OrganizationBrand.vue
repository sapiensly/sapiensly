<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DraftConflicts from '@/components/admin/DraftConflicts.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import SiteImport, {
    type ImportStatus,
} from '@/components/admin/SiteImport.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Check,
    Globe,
    ImagePlus,
    Loader2,
    Palette,
    Sparkles,
    Type,
    Wand2,
} from '@lucide/vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';

interface Brand {
    logo_url: string | null;
    icon_url: string | null;
    logo_dark_url: string | null;
    icon_dark_url: string | null;
    accent_color: string | null;
    logo_bg_color: string | null;
    font: string | null;
    theme: string | null;
}

// The deterministic palette every app surface derives from an accent — the same
// shape the backend (ColorPalette) and the AI proposal endpoint return.
interface Palette {
    ramp: Record<string, string>;
    soft: string;
    contrast: string;
    chart: string[];
}

// `palette` is the one currently in effect (derived from the saved accent).
// `website` is what the Contextbook records, so the import starts from the URL
// the admin already gave us instead of asking for it a second time.
const props = defineProps<{
    brand: Brand;
    palette: Palette;
    website: string | null;
    lastImport: { url: string | null; at: string } | null;
}>();

const { t } = useI18n();

// The platform default accent (the --sp-accent-blue token); the accent picker
// starts here so the brand colour defaults to the standard blue.
const DEFAULT_ACCENT = '#0096ff';

const form = useForm({
    logo_url: props.brand.logo_url ?? '',
    icon_url: props.brand.icon_url ?? '',
    logo_dark_url: props.brand.logo_dark_url ?? '',
    icon_dark_url: props.brand.icon_dark_url ?? '',
    accent_color: props.brand.accent_color ?? DEFAULT_ACCENT,
    logo_bg_color: props.brand.logo_bg_color ?? '',
    font: props.brand.font ?? '',
    theme: props.brand.theme ?? '',
});

const FONT_STACKS: Record<string, string> = {
    sans: 'ui-sans-serif, system-ui, sans-serif',
    serif: 'ui-serif, Georgia, serif',
    rounded: '"SF Pro Rounded", ui-rounded, "Quicksand", system-ui, sans-serif',
    mono: 'ui-monospace, SFMono-Regular, Menlo, monospace',
};

type AssetKind = 'logo' | 'icon' | 'logo_dark' | 'icon_dark';

// Maps an upload kind to the form field that holds its URL, so the base and
// dark-surface variants share one upload path.
const ASSET_FIELD: Record<
    AssetKind,
    'logo_url' | 'icon_url' | 'logo_dark_url' | 'icon_dark_url'
> = {
    logo: 'logo_url',
    icon: 'icon_url',
    logo_dark: 'logo_dark_url',
    icon_dark: 'icon_dark_url',
};

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

/** Upload a logo/icon file (base or dark variant); on success store the returned URL. */
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
        (form as unknown as Record<string, unknown>)[ASSET_FIELD[kind]] =
            res.url;
        noteTone(res);
        toast.success(t('settings.brand.asset_uploaded'));
    } catch {
        toast.error(t('settings.brand.asset_failed'));
    } finally {
        uploading.value[kind] = false;
        input.value = '';
    }
}

function submit(): void {
    // Empty strings → null so the server clears (and the hex validator passes).
    form.transform((data) =>
        Object.fromEntries(
            Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]),
        ),
    ).put('/settings/organization/brand', {
        preserveScroll: true,
        onSuccess: () => toast.success(t('settings.brand.saved')),
    });
}

// Live preview tokens — background/text follow the light/dark theme; the brand
// owns the accent only.
const previewStyle = computed(() => ({
    background: form.theme === 'dark' ? '#0b1220' : '#ffffff',
    color: form.theme === 'dark' ? '#e5e7eb' : '#1f2937',
    fontFamily: form.font ? FONT_STACKS[form.font] : 'inherit',
}));
const accent = computed(() => form.accent_color || DEFAULT_ACCENT);

// The preview honours the selected default theme: a dark preview shows the dark
// logo/icon variant, falling back to the base asset when the variant is unset.
const previewLogo = computed<string>(
    () =>
        (form.theme === 'dark'
            ? form.logo_dark_url || form.logo_url
            : form.logo_url) || '',
);
const previewIcon = computed<string>(
    () =>
        (form.theme === 'dark'
            ? form.icon_dark_url || form.icon_url
            : form.icon_url) || '',
);

// The preview header strip adopts the logo bg colour with a readable text colour.
function readableText(hex: string): string {
    const c = hex.replace('#', '');
    if (c.length !== 6) return '';
    const r = parseInt(c.slice(0, 2), 16);
    const g = parseInt(c.slice(2, 4), 16);
    const b = parseInt(c.slice(4, 6), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6
        ? '#0f172a'
        : '#f8fafc';
}
const previewHeaderStyle = computed(() =>
    form.logo_bg_color
        ? {
              background: form.logo_bg_color,
              color: readableText(form.logo_bg_color),
          }
        : {},
);

// AI palette proposals: the model picks accent directions; the server expands
// each into the exact derived palette apps inherit (ramp + chart series).
interface PaletteProposal {
    name: string;
    accent: string;
    rationale: string;
    palette: Palette;
}

const paletteBrief = ref('');
const paletteGenerating = ref(false);
const paletteProposals = ref<PaletteProposal[]>([]);

// The palette currently in effect, shown live: seeded from the saved accent and
// re-derived (server-side, no colour-maths port) whenever the accent changes.
const activePalette = ref<Palette>(props.palette);
const HEX_RE = /^#[0-9A-Fa-f]{6}$/;
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

const RAMP_STOPS = ['100', '300', '500', '700', '900'];

// Read the brand off the organization's own website. The server labels every
// field: `new` (the brand left it unset — safe to fill) or `conflict` (the brand
// already says something else). Conflicts are never applied here; they go to the
// review and wait for the user. Nothing is saved until Save either way.
interface DiffEntry {
    field: string;
    status: string;
    current: unknown;
    proposed: unknown;
}

const siteUrl = ref(props.website ?? '');
const siteReading = ref(false);
const siteStatus = ref<ImportStatus | null>(null);
const siteConflicts = ref<DiffEntry[]>([]);

/**
 * Assets whose copy-to-storage failed (no object storage configured, the host
 * went away mid-flow). They stay here rather than vanishing: a field silently
 * dropped is one the user cannot get back without re-reading the whole site.
 */
const siteFailures = ref<{ field: string; value: string; message: string }[]>(
    [],
);

const CONFLICT_LABELS = computed<Record<string, string>>(() => ({
    logo_url: t('settings.brand.logo'),
    icon_url: t('settings.brand.icon'),
    accent_color: t('settings.brand.accent'),
    font: t('settings.brand.font'),
    theme: t('settings.brand.theme'),
}));

/**
 * Copy a remote image onto the tenant's disk before adopting it, so the brand
 * does not depend on someone else's server staying up. Only ever runs on accept.
 */
async function adoptAsset(field: string, url: string): Promise<string | null> {
    try {
        const { data } = await axios.post(
            '/settings/organization/brand/asset/import',
            { kind: field === 'logo_url' ? 'logo' : 'icon', url },
        );
        noteTone(data);
        return data.url;
    } catch (error) {
        const message =
            (error as { response?: { data?: { message?: string } } }).response
                ?.data?.message ?? t('settings.brand.asset_failed');
        // Remember it so the user can retry without re-reading the whole site.
        siteFailures.value = [
            ...siteFailures.value.filter((f) => f.field !== field),
            { field, value: url, message },
        ];
        toast.error(message);
        return null;
    }
}

/** Returns whether the field actually landed on the form. */
async function applySiteField(field: string, value: unknown): Promise<boolean> {
    if (typeof value !== 'string') return false;

    const stored =
        field === 'logo_url' || field === 'icon_url'
            ? await adoptAsset(field, value)
            : value;

    if (stored === null) return false;

    (form as unknown as Record<string, unknown>)[field] = stored;
    siteFailures.value = siteFailures.value.filter((f) => f.field !== field);

    return true;
}

/**
 * One reading of the site proposes BOTH books; this page takes the brand half
 * and reports how much of the same reading is waiting on the Contextbook, so
 * nobody pays to read the page twice.
 */
async function readSite(): Promise<void> {
    siteReading.value = true;
    siteConflicts.value = [];
    siteFailures.value = [];
    siteStatus.value = null;
    try {
        const { data } = await axios.post(
            '/settings/organization/site-import',
            { website: siteUrl.value },
        );

        const diff = (data.brand?.diff ?? []) as DiffEntry[];

        // Empty fields fill straight away; what would replace something the
        // organization already set waits below for a decision.
        const applied: string[] = [];
        for (const entry of diff.filter((e) => e.status === 'new')) {
            if (await applySiteField(entry.field, entry.proposed)) {
                applied.push(CONFLICT_LABELS.value[entry.field] ?? entry.field);
            }
        }
        siteConflicts.value = diff.filter((e) => e.status === 'conflict');

        siteStatus.value = {
            url: data.url ?? null,
            read: Boolean(data.read),
            reason: String(data.reason ?? ''),
            applied,
            conflicts: siteConflicts.value.length,
            sources: data.context?.sources ?? [],
            otherCount: (data.context?.diff ?? []).length,
            notes: data.brand?.notes ?? [],
        };

        // Adopt what was actually fetched, so a retry reads the same address.
        if (typeof data.url === 'string') {
            siteUrl.value = data.url;
        }

        // The summary is inline; only the decisions still owed are worth a nudge
        // the user might otherwise scroll past.
        if (siteConflicts.value.length) {
            toast.info(
                t('site_import.conflicts', {
                    count: siteConflicts.value.length,
                }),
            );
        }
    } catch {
        toast.error(t('site_import.failed'));
    } finally {
        siteReading.value = false;
    }
}

async function acceptSiteField(field: string, value: unknown): Promise<void> {
    // A failed accept keeps its card: the user asked for this value and has to
    // be able to ask again, rather than watch it disappear.
    if (await applySiteField(field, value)) {
        siteConflicts.value = siteConflicts.value.filter(
            (e) => e.field !== field,
        );
    }
}

async function retryFailure(field: string, value: string): Promise<void> {
    await applySiteField(field, value);
}

function dismissFailure(field: string): void {
    siteFailures.value = siteFailures.value.filter((f) => f.field !== field);
}

function dismissSiteField(field: string): void {
    siteConflicts.value = siteConflicts.value.filter((e) => e.field !== field);
}
</script>

<template>
    <Head :title="t('settings.brand.title')" />

    <SettingsLayout>
        <form class="space-y-4" @submit.prevent="submit">
            <SettingsCard
                :icon="Sparkles"
                :title="t('settings.brand.title')"
                :description="t('settings.brand.description')"
            >
                <!-- Live preview. -->
                <div
                    class="overflow-hidden rounded-sp-sm border border-soft"
                    :style="previewStyle"
                >
                    <div
                        class="flex items-center gap-2 border-b border-black/10 px-4 py-3"
                        :style="previewHeaderStyle"
                    >
                        <img
                            v-if="previewLogo"
                            :src="previewLogo"
                            alt="logo"
                            class="h-6 max-w-[140px] object-contain"
                        />
                        <img
                            v-else-if="previewIcon"
                            :src="previewIcon"
                            alt="icon"
                            class="size-6 rounded object-contain"
                        />
                        <span v-else class="text-sm font-semibold opacity-70">{{
                            t('settings.brand.preview_brand')
                        }}</span>
                    </div>
                    <div class="space-y-3 p-4">
                        <p class="text-sm">
                            {{ t('settings.brand.preview_text') }}
                        </p>
                        <button
                            type="button"
                            class="rounded-sp-sm px-3 py-1.5 text-xs font-medium text-white"
                            :style="{ background: accent }"
                        >
                            {{ t('settings.brand.preview_button') }}
                        </button>
                    </div>
                </div>
            </SettingsCard>

            <!-- Read the brand off the organization's own website. The card
                 carries no description of its own: SiteImport states the terms
                 (what is read, what is never replaced) for both books at once. -->
            <SettingsCard
                :icon="Globe"
                :title="t('settings.brand.from_site')"
                tint="var(--sp-accent-green)"
            >
                <SiteImport
                    v-model:url="siteUrl"
                    book="brand"
                    :loading="siteReading"
                    :status="siteStatus"
                    :last-import="props.lastImport"
                    @read="readSite"
                >
                    <DraftConflicts
                        :entries="siteConflicts"
                        :labels="CONFLICT_LABELS"
                        @accept="acceptSiteField"
                        @dismiss="dismissSiteField"
                    />

                    <!-- Assets we found but could not copy: kept, not dropped. -->
                    <div
                        v-for="failure in siteFailures"
                        :key="failure.field"
                        class="space-y-2 rounded-sp-sm border border-soft p-3"
                    >
                        <p class="text-xs font-medium text-ink">
                            {{ CONFLICT_LABELS[failure.field] }}
                        </p>
                        <p class="text-xs text-ink-muted">
                            {{ failure.message }}
                        </p>
                        <p class="text-xs break-all text-ink-muted">
                            {{ failure.value }}
                        </p>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="h-8 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                @click="dismissFailure(failure.field)"
                            >
                                {{ t('settings.brand.asset_skip') }}
                            </button>
                            <button
                                type="button"
                                class="h-8 rounded-xs bg-accent-blue px-3 text-xs font-medium text-white transition-opacity hover:opacity-90"
                                @click="
                                    retryFailure(failure.field, failure.value)
                                "
                            >
                                {{ t('settings.brand.asset_retry') }}
                            </button>
                        </div>
                    </div>
                </SiteImport>
            </SettingsCard>

            <!-- Logo & icon. -->
            <SettingsCard
                :icon="ImagePlus"
                :title="t('settings.brand.assets')"
                :description="t('settings.brand.assets_hint')"
                tint="var(--sp-accent-cyan)"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.brand.logo') }}</Label>
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-sp-sm border border-soft bg-surface"
                            >
                                <img
                                    v-if="form.logo_url"
                                    :src="form.logo_url"
                                    alt="logo"
                                    class="size-full object-contain"
                                />
                                <ImagePlus
                                    v-else
                                    class="size-4 text-ink-muted/60"
                                />
                            </span>
                            <label
                                class="inline-flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                            >
                                <Loader2
                                    v-if="uploading.logo"
                                    class="size-3.5 animate-spin"
                                />
                                <ImagePlus v-else class="size-3.5" />
                                {{ t('settings.brand.upload') }}
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="uploadAsset('logo', $event)"
                                />
                            </label>
                            <button
                                v-if="form.logo_url"
                                type="button"
                                class="shrink-0 text-xs text-ink-muted underline-offset-2 hover:text-ink hover:underline"
                                @click="form.logo_url = ''"
                            >
                                {{ t('settings.brand.clear') }}
                            </button>
                        </div>
                        <InputError :message="form.errors.logo_url" />
                    </div>

                    <div class="space-y-1.5">
                        <Label>{{ t('settings.brand.icon') }}</Label>
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-sp-sm border border-soft bg-surface"
                            >
                                <img
                                    v-if="form.icon_url"
                                    :src="form.icon_url"
                                    alt="icon"
                                    class="size-full object-contain"
                                />
                                <ImagePlus
                                    v-else
                                    class="size-4 text-ink-muted/60"
                                />
                            </span>
                            <label
                                class="inline-flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                            >
                                <Loader2
                                    v-if="uploading.icon"
                                    class="size-3.5 animate-spin"
                                />
                                <ImagePlus v-else class="size-3.5" />
                                {{ t('settings.brand.upload') }}
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="uploadAsset('icon', $event)"
                                />
                            </label>
                            <button
                                v-if="form.icon_url"
                                type="button"
                                class="shrink-0 text-xs text-ink-muted underline-offset-2 hover:text-ink hover:underline"
                                @click="form.icon_url = ''"
                            >
                                {{ t('settings.brand.clear') }}
                            </button>
                        </div>
                        <InputError :message="form.errors.icon_url" />
                    </div>
                </div>

                <!-- A light-ink logo on a light surface is nothing at all, and
                     the dark variant cannot rescue it (light surfaces have no
                     fallback). The backdrop colour is the Brandbook's answer. -->
                <div
                    v-if="backdropAdvice"
                    class="mt-4 flex flex-wrap items-center gap-3 rounded-sp-sm border border-soft p-3"
                >
                    <span
                        class="flex h-9 shrink-0 items-center rounded-xs px-3"
                        :style="{ background: backdropAdvice }"
                    >
                        <img
                            v-if="form.logo_url"
                            :src="form.logo_url"
                            alt=""
                            class="h-5 max-w-[110px] object-contain"
                        />
                    </span>
                    <p class="min-w-[12rem] flex-1 text-xs text-ink-muted">
                        {{ t('settings.brand.light_logo_hint') }}
                    </p>
                    <button
                        type="button"
                        class="h-8 shrink-0 rounded-xs bg-accent-blue px-3 text-xs font-medium text-white transition-opacity hover:opacity-90"
                        @click="applyBackdrop"
                    >
                        {{ t('settings.brand.light_logo_apply') }}
                    </button>
                    <button
                        type="button"
                        class="h-8 shrink-0 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                        @click="backdropAdvice = null"
                    >
                        {{ t('settings.brand.asset_skip') }}
                    </button>
                </div>

                <!-- Dark-surface variants. Optional: when unset, dark surfaces
                     fall back to the base logo/icon above. -->
                <div class="mt-5 border-t border-soft pt-4">
                    <p class="text-sm font-medium">
                        {{ t('settings.brand.dark_variants') }}
                    </p>
                    <p class="mt-0.5 text-xs text-ink-muted">
                        {{ t('settings.brand.dark_variants_hint') }}
                    </p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label>{{ t('settings.brand.logo_dark') }}</Label>
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-sp-sm border border-soft bg-[#0b1220]"
                                >
                                    <img
                                        v-if="form.logo_dark_url"
                                        :src="form.logo_dark_url"
                                        alt="dark logo"
                                        class="size-full object-contain"
                                    />
                                    <ImagePlus
                                        v-else
                                        class="size-4 text-white/40"
                                    />
                                </span>
                                <label
                                    class="inline-flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                >
                                    <Loader2
                                        v-if="uploading.logo_dark"
                                        class="size-3.5 animate-spin"
                                    />
                                    <ImagePlus v-else class="size-3.5" />
                                    {{ t('settings.brand.upload') }}
                                    <input
                                        type="file"
                                        accept="image/*"
                                        class="hidden"
                                        @change="
                                            uploadAsset('logo_dark', $event)
                                        "
                                    />
                                </label>
                                <button
                                    v-if="form.logo_dark_url"
                                    type="button"
                                    class="shrink-0 text-xs text-ink-muted underline-offset-2 hover:text-ink hover:underline"
                                    @click="form.logo_dark_url = ''"
                                >
                                    {{ t('settings.brand.clear') }}
                                </button>
                            </div>
                            <InputError :message="form.errors.logo_dark_url" />
                        </div>

                        <div class="space-y-1.5">
                            <Label>{{ t('settings.brand.icon_dark') }}</Label>
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-sp-sm border border-soft bg-[#0b1220]"
                                >
                                    <img
                                        v-if="form.icon_dark_url"
                                        :src="form.icon_dark_url"
                                        alt="dark icon"
                                        class="size-full object-contain"
                                    />
                                    <ImagePlus
                                        v-else
                                        class="size-4 text-white/40"
                                    />
                                </span>
                                <label
                                    class="inline-flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                >
                                    <Loader2
                                        v-if="uploading.icon_dark"
                                        class="size-3.5 animate-spin"
                                    />
                                    <ImagePlus v-else class="size-3.5" />
                                    {{ t('settings.brand.upload') }}
                                    <input
                                        type="file"
                                        accept="image/*"
                                        class="hidden"
                                        @change="
                                            uploadAsset('icon_dark', $event)
                                        "
                                    />
                                </label>
                                <button
                                    v-if="form.icon_dark_url"
                                    type="button"
                                    class="shrink-0 text-xs text-ink-muted underline-offset-2 hover:text-ink hover:underline"
                                    @click="form.icon_dark_url = ''"
                                >
                                    {{ t('settings.brand.clear') }}
                                </button>
                            </div>
                            <InputError :message="form.errors.icon_dark_url" />
                        </div>
                    </div>
                </div>
            </SettingsCard>

            <!-- Accent colour (the single brand colour). -->
            <SettingsCard
                :icon="Palette"
                :title="t('settings.brand.colors')"
                :description="t('settings.brand.colors_hint')"
                tint="var(--sp-accent-violet)"
            >
                <div class="space-y-1.5 sm:max-w-xs">
                    <Label>{{ t('settings.brand.accent') }}</Label>
                    <div class="flex items-center gap-2">
                        <label
                            class="size-9 shrink-0 cursor-pointer rounded-xs border border-soft"
                            :style="{
                                background: form.accent_color || DEFAULT_ACCENT,
                            }"
                            :title="t('settings.brand.pick_color')"
                        >
                            <input
                                type="color"
                                :value="form.accent_color || DEFAULT_ACCENT"
                                class="size-0 opacity-0"
                                @input="
                                    form.accent_color = (
                                        $event.target as HTMLInputElement
                                    ).value
                                "
                            />
                        </label>
                        <Input
                            v-model="form.accent_color"
                            class="h-9"
                            placeholder="#RRGGBB"
                        />
                    </div>
                    <InputError :message="form.errors.accent_color" />
                </div>

                <div class="mt-4 space-y-1.5 sm:max-w-xs">
                    <Label>{{ t('settings.brand.logo_bg') }}</Label>
                    <div class="flex items-center gap-2">
                        <label
                            class="size-9 shrink-0 cursor-pointer rounded-xs border border-soft"
                            :style="{
                                background: form.logo_bg_color || '#ffffff',
                            }"
                            :title="t('settings.brand.pick_color')"
                        >
                            <input
                                type="color"
                                :value="form.logo_bg_color || '#ffffff'"
                                class="size-0 opacity-0"
                                @input="
                                    form.logo_bg_color = (
                                        $event.target as HTMLInputElement
                                    ).value
                                "
                            />
                        </label>
                        <Input
                            v-model="form.logo_bg_color"
                            class="h-9"
                            placeholder="#RRGGBB"
                        />
                        <button
                            v-if="form.logo_bg_color"
                            type="button"
                            class="shrink-0 text-xs text-ink-muted underline-offset-2 hover:text-ink hover:underline"
                            @click="form.logo_bg_color = ''"
                        >
                            {{ t('settings.brand.clear') }}
                        </button>
                    </div>
                    <p class="text-xs text-ink-muted">
                        {{ t('settings.brand.logo_bg_hint') }}
                    </p>
                    <InputError :message="form.errors.logo_bg_color" />
                </div>
            </SettingsCard>

            <!-- AI palette generator: propose accents, preview the derived
                 palette, adopt one into the form (Save persists it). -->
            <SettingsCard
                :icon="Wand2"
                :title="t('settings.brand.palette_title')"
                :description="t('settings.brand.palette_hint')"
                tint="var(--sp-accent-teal)"
            >
                <div class="flex items-center gap-2">
                    <Input
                        v-model="paletteBrief"
                        class="h-9"
                        maxlength="600"
                        :placeholder="t('settings.brand.palette_placeholder')"
                        @keydown.enter.prevent="generatePalettes"
                    />
                    <button
                        type="button"
                        :disabled="paletteGenerating"
                        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-sp-sm border border-soft px-3 text-xs font-medium text-ink-muted transition-colors hover:bg-surface hover:text-ink disabled:opacity-50"
                        @click="generatePalettes"
                    >
                        <Loader2
                            v-if="paletteGenerating"
                            class="size-3.5 animate-spin"
                        />
                        <Wand2 v-else class="size-3.5" />
                        {{ t('settings.brand.palette_generate') }}
                    </button>
                </div>

                <!-- The palette currently in effect, always shown so the admin
                     sees the active brand colours (updates live with the accent). -->
                <div class="mt-4 rounded-sp-sm border border-soft p-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium">{{
                            t('settings.brand.palette_current')
                        }}</span>
                        <span class="flex items-center gap-1.5">
                            <span
                                class="size-4 rounded-full border border-soft"
                                :style="{ background: accent }"
                            />
                            <span class="text-xs text-ink-muted">{{
                                accent
                            }}</span>
                        </span>
                    </div>
                    <div class="mt-2 flex h-7 overflow-hidden rounded-xs">
                        <span
                            v-for="stop in RAMP_STOPS"
                            :key="stop"
                            class="flex-1"
                            :style="{ background: activePalette.ramp[stop] }"
                        />
                    </div>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <span
                            v-for="color in activePalette.chart"
                            :key="color"
                            class="size-3.5 rounded-full"
                            :style="{ background: color }"
                        />
                        <span class="ml-1 text-[10px] text-ink-muted">{{
                            t('settings.brand.palette_charts')
                        }}</span>
                    </div>
                </div>

                <div
                    v-if="paletteProposals.length"
                    class="mt-4 grid gap-3 sm:grid-cols-2"
                >
                    <div
                        v-for="proposal in paletteProposals"
                        :key="proposal.accent"
                        class="rounded-sp-sm border p-3 transition-colors"
                        :class="
                            form.accent_color === proposal.accent
                                ? 'border-accent-blue'
                                : 'border-soft'
                        "
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium">{{
                                proposal.name
                            }}</span>
                            <span class="text-xs text-ink-muted">{{
                                proposal.accent
                            }}</span>
                        </div>

                        <!-- The exact derived palette apps inherit: the tint/
                             shade ramp plus the chart series. -->
                        <div class="mt-2 flex h-7 overflow-hidden rounded-xs">
                            <span
                                v-for="stop in RAMP_STOPS"
                                :key="stop"
                                class="flex-1"
                                :style="{
                                    background: proposal.palette.ramp[stop],
                                }"
                            />
                        </div>
                        <div class="mt-1.5 flex items-center gap-1.5">
                            <span
                                v-for="color in proposal.palette.chart"
                                :key="color"
                                class="size-3.5 rounded-full"
                                :style="{ background: color }"
                            />
                            <span class="ml-1 text-[10px] text-ink-muted">{{
                                t('settings.brand.palette_charts')
                            }}</span>
                        </div>

                        <p class="mt-2 text-xs text-ink-muted">
                            {{ proposal.rationale }}
                        </p>

                        <button
                            type="button"
                            class="mt-2 inline-flex h-8 items-center gap-1.5 rounded-sp-sm px-3 text-xs font-medium transition-opacity hover:opacity-90"
                            :style="{
                                background: proposal.accent,
                                color: proposal.palette.contrast,
                            }"
                            @click="applyProposal(proposal)"
                        >
                            <Check
                                v-if="form.accent_color === proposal.accent"
                                class="size-3.5"
                            />
                            {{
                                form.accent_color === proposal.accent
                                    ? t('settings.brand.palette_selected')
                                    : t('settings.brand.palette_use')
                            }}
                        </button>
                    </div>
                </div>
            </SettingsCard>

            <!-- Typography & theme. -->
            <SettingsCard
                :icon="Type"
                :title="t('settings.brand.typography')"
                :description="t('settings.brand.typography_hint')"
                tint="var(--sp-accent-amber)"
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.brand.font') }}</Label>
                        <select
                            v-model="form.font"
                            class="h-9 w-full rounded-sp-sm border border-medium bg-surface px-2 text-sm text-ink focus:border-accent-blue focus:outline-none"
                        >
                            <option value="">
                                {{ t('settings.brand.unset') }}
                            </option>
                            <option value="sans">Sans</option>
                            <option value="serif">Serif</option>
                            <option value="rounded">Rounded</option>
                            <option value="mono">Mono</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.brand.theme') }}</Label>
                        <select
                            v-model="form.theme"
                            class="h-9 w-full rounded-sp-sm border border-medium bg-surface px-2 text-sm text-ink focus:border-accent-blue focus:outline-none"
                        >
                            <option value="">
                                {{ t('settings.brand.unset') }}
                            </option>
                            <option value="light">
                                {{ t('settings.brand.theme_light') }}
                            </option>
                            <option value="dark">
                                {{ t('settings.brand.theme_dark') }}
                            </option>
                        </select>
                    </div>
                </div>
            </SettingsCard>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex h-9 items-center gap-1.5 rounded-sp-sm bg-accent-blue px-4 text-sm font-medium text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <Loader2
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    {{ t('settings.brand.save') }}
                </button>
            </div>
        </form>
    </SettingsLayout>
</template>
