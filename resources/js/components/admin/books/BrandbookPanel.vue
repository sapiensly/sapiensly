<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DraftConflicts from '@/components/admin/DraftConflicts.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    DEFAULT_ACCENT,
    type OrganizationIdentity,
    type PaletteProposal,
} from '@/composables/useOrganizationIdentity';
import {
    Check,
    ImagePlus,
    Loader2,
    Palette,
    Sparkles,
    Type,
    Wand2,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The Brandbook tab of the organization identity: what the brand looks like.
 *
 * A template on purpose — the state and every action live in
 * {@see useOrganizationIdentity}, because reading the website proposes fields for
 * this book and the Contextbook at once and both are saved in one write.
 */
const props = defineProps<{
    form: OrganizationIdentity['form'];
    brand: OrganizationIdentity['brand'];
}>();

const { t } = useI18n();

const form = props.form;
const book = props.brand;

const FONT_STACKS: Record<string, string> = {
    sans: 'ui-sans-serif, system-ui, sans-serif',
    serif: 'ui-serif, Georgia, serif',
    rounded: '"SF Pro Rounded", ui-rounded, "Quicksand", system-ui, sans-serif',
    mono: 'ui-monospace, SFMono-Regular, Menlo, monospace',
};

const RAMP_STOPS = ['100', '300', '500', '700', '900'];

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

const conflictLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        book.conflicts
            .map((entry) => entry.field)
            .concat(book.failures.map((entry) => entry.field))
            .map((field) => [field, book.label(field)]),
    ),
);

function applyProposal(proposal: PaletteProposal): void {
    book.applyProposal(proposal);
}
</script>

<template>
    <div class="space-y-4">
        <!-- Decisions the site reading left for this book. First, because they
             are the reason the user came back to this tab. -->
        <SettingsCard
            v-if="book.conflicts.length || book.failures.length"
            :icon="Wand2"
            :title="t('settings.identity.decisions')"
            :description="t('settings.identity.decisions_hint')"
            tint="var(--sp-accent-green)"
        >
            <DraftConflicts
                :entries="book.conflicts"
                :labels="conflictLabels"
                @accept="book.acceptConflict"
                @dismiss="book.dismissConflict"
            />

            <!-- Assets we found but could not copy: kept, not dropped. -->
            <div
                v-for="failure in book.failures"
                :key="failure.field"
                class="space-y-2 rounded-sp-sm border border-soft p-3"
            >
                <p class="text-xs font-medium text-ink">
                    {{ book.label(failure.field) }}
                </p>
                <p class="text-xs text-ink-muted">{{ failure.message }}</p>
                <p class="text-xs break-all text-ink-muted">
                    {{ failure.value }}
                </p>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="h-8 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                        @click="book.dismissFailure(failure.field)"
                    >
                        {{ t('settings.brand.asset_skip') }}
                    </button>
                    <button
                        type="button"
                        class="h-8 rounded-xs bg-accent-blue px-3 text-xs font-medium text-white transition-opacity hover:opacity-90"
                        @click="book.retryFailure(failure.field, failure.value)"
                    >
                        {{ t('settings.brand.asset_retry') }}
                    </button>
                </div>
            </div>
        </SettingsCard>

        <!-- Live preview. -->
        <SettingsCard
            :icon="Sparkles"
            :title="t('settings.brand.title')"
            :description="t('settings.brand.description')"
        >
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
                                v-if="book.uploading.logo"
                                class="size-3.5 animate-spin"
                            />
                            <ImagePlus v-else class="size-3.5" />
                            {{ t('settings.brand.upload') }}
                            <input
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="book.uploadAsset('logo', $event)"
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
                                v-if="book.uploading.icon"
                                class="size-3.5 animate-spin"
                            />
                            <ImagePlus v-else class="size-3.5" />
                            {{ t('settings.brand.upload') }}
                            <input
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="book.uploadAsset('icon', $event)"
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

            <!-- A light-ink logo on a light surface is nothing at all, and the
                 dark variant cannot rescue it (light surfaces have no fallback).
                 The backdrop colour is the Brandbook's answer. -->
            <div
                v-if="book.backdropAdvice"
                class="mt-4 flex flex-wrap items-center gap-3 rounded-sp-sm border border-soft p-3"
            >
                <span
                    class="flex h-9 shrink-0 items-center rounded-xs px-3"
                    :style="{ background: book.backdropAdvice }"
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
                    @click="book.applyBackdrop()"
                >
                    {{ t('settings.brand.light_logo_apply') }}
                </button>
                <button
                    type="button"
                    class="h-8 shrink-0 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                    @click="book.backdropAdvice = null"
                >
                    {{ t('settings.brand.asset_skip') }}
                </button>
            </div>

            <!-- Dark-surface variants. Optional: when unset, dark surfaces fall
                 back to the base logo/icon above. -->
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
                                    v-if="book.uploading.logo_dark"
                                    class="size-3.5 animate-spin"
                                />
                                <ImagePlus v-else class="size-3.5" />
                                {{ t('settings.brand.upload') }}
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="
                                        book.uploadAsset('logo_dark', $event)
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
                                    v-if="book.uploading.icon_dark"
                                    class="size-3.5 animate-spin"
                                />
                                <ImagePlus v-else class="size-3.5" />
                                {{ t('settings.brand.upload') }}
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="
                                        book.uploadAsset('icon_dark', $event)
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

        <!-- AI palette generator: propose accents, preview the derived palette,
             adopt one into the form (Save persists it). -->
        <SettingsCard
            :icon="Wand2"
            :title="t('settings.brand.palette_title')"
            :description="t('settings.brand.palette_hint')"
            tint="var(--sp-accent-teal)"
        >
            <div class="flex items-center gap-2">
                <Input
                    v-model="book.paletteBrief"
                    class="h-9"
                    maxlength="600"
                    :placeholder="t('settings.brand.palette_placeholder')"
                    @keydown.enter.prevent="book.generatePalettes()"
                />
                <button
                    type="button"
                    :disabled="book.paletteGenerating"
                    class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-sp-sm border border-soft px-3 text-xs font-medium text-ink-muted transition-colors hover:bg-surface hover:text-ink disabled:opacity-50"
                    @click="book.generatePalettes()"
                >
                    <Loader2
                        v-if="book.paletteGenerating"
                        class="size-3.5 animate-spin"
                    />
                    <Wand2 v-else class="size-3.5" />
                    {{ t('settings.brand.palette_generate') }}
                </button>
            </div>

            <!-- The palette currently in effect, always shown so the admin sees
                 the active brand colours (updates live with the accent). -->
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
                        <span class="text-xs text-ink-muted">{{ accent }}</span>
                    </span>
                </div>
                <div class="mt-2 flex h-7 overflow-hidden rounded-xs">
                    <span
                        v-for="stop in RAMP_STOPS"
                        :key="stop"
                        class="flex-1"
                        :style="{
                            background: book.activePalette.ramp[stop],
                        }"
                    />
                </div>
                <div class="mt-1.5 flex items-center gap-1.5">
                    <span
                        v-for="color in book.activePalette.chart"
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
                v-if="book.paletteProposals.length"
                class="mt-4 grid gap-3 sm:grid-cols-2"
            >
                <div
                    v-for="proposal in book.paletteProposals"
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

                    <!-- The exact derived palette apps inherit: the tint/shade
                         ramp plus the chart series. -->
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
    </div>
</template>
