<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Check,
    ImagePlus,
    Loader2,
    Palette,
    Sparkles,
    Type,
    Wand2,
} from '@lucide/vue';
import axios from 'axios';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';

interface Brand {
    logo_url: string | null;
    icon_url: string | null;
    icon_emoji: string | null;
    accent_color: string | null;
    logo_bg_color: string | null;
    font: string | null;
    theme: string | null;
}

const props = defineProps<{ brand: Brand }>();

const { t } = useI18n();

// The platform default accent (the --sp-accent-blue token); the accent picker
// starts here so the brand colour defaults to the standard blue.
const DEFAULT_ACCENT = '#0096ff';

const form = useForm({
    logo_url: props.brand.logo_url ?? '',
    icon_url: props.brand.icon_url ?? '',
    icon_emoji: props.brand.icon_emoji ?? '',
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

const uploading = ref<Record<string, boolean>>({ logo: false, icon: false });

/** Upload a logo/icon file; on success store the returned URL on the form. */
async function uploadAsset(kind: 'logo' | 'icon', event: Event): Promise<void> {
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
        if (kind === 'logo') form.logo_url = res.url;
        else form.icon_url = res.url;
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
    palette: {
        ramp: Record<string, string>;
        soft: string;
        contrast: string;
        chart: string[];
    };
}

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
    toast.success(t('settings.brand.palette_applied'));
}

const RAMP_STOPS = ['100', '300', '500', '700', '900'];
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
                            v-if="form.logo_url"
                            :src="form.logo_url"
                            alt="logo"
                            class="h-6 max-w-[140px] object-contain"
                        />
                        <span v-else-if="form.icon_emoji" class="text-xl">{{
                            form.icon_emoji
                        }}</span>
                        <img
                            v-else-if="form.icon_url"
                            :src="form.icon_url"
                            alt="icon"
                            class="size-6 rounded object-cover"
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
                <div class="space-y-1.5">
                    <Label>{{ t('settings.brand.logo') }}</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            v-model="form.logo_url"
                            class="h-9"
                            placeholder="https://… or upload"
                        />
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
                    </div>
                    <InputError :message="form.errors.logo_url" />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.brand.icon') }}</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="form.icon_url"
                                class="h-9"
                                placeholder="https://… or upload"
                            />
                            <label
                                class="inline-flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                            >
                                <Loader2
                                    v-if="uploading.icon"
                                    class="size-3.5 animate-spin"
                                />
                                <ImagePlus v-else class="size-3.5" />
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="uploadAsset('icon', $event)"
                                />
                            </label>
                        </div>
                        <InputError :message="form.errors.icon_url" />
                    </div>
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.brand.icon_emoji') }}</Label>
                        <Input
                            v-model="form.icon_emoji"
                            class="h-9"
                            maxlength="8"
                            placeholder="🚀"
                        />
                        <InputError :message="form.errors.icon_emoji" />
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
