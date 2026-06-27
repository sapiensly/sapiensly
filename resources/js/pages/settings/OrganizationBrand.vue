<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    ImagePlus,
    Loader2,
    Palette,
    Plus,
    Sparkles,
    Type,
    X,
} from '@lucide/vue';
import axios from 'axios';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';

interface Brand {
    logo_url: string | null;
    icon_url: string | null;
    icon_emoji: string | null;
    primary_color: string | null;
    background_color: string | null;
    text_color: string | null;
    font: string | null;
    theme: string | null;
}

const props = defineProps<{ brand: Brand }>();

const { t } = useI18n();

const form = useForm({
    logo_url: props.brand.logo_url ?? '',
    icon_url: props.brand.icon_url ?? '',
    icon_emoji: props.brand.icon_emoji ?? '',
    primary_color: props.brand.primary_color ?? '',
    background_color: props.brand.background_color ?? '',
    text_color: props.brand.text_color ?? '',
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

// Live preview tokens.
const previewStyle = computed(() => ({
    background:
        form.theme === 'dark' ? '#0b1220' : form.background_color || '#ffffff',
    color: form.theme === 'dark' ? '#e5e7eb' : form.text_color || '#1f2937',
    fontFamily: form.font ? FONT_STACKS[form.font] : 'inherit',
}));
const accent = computed(() => form.primary_color || '#3b82f6');
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

            <!-- Colours. -->
            <SettingsCard
                :icon="Palette"
                :title="t('settings.brand.colors')"
                :description="t('settings.brand.colors_hint')"
                tint="var(--sp-accent-violet)"
            >
                <div class="grid gap-3 sm:grid-cols-3">
                    <div
                        v-for="c in [
                            {
                                key: 'primary_color',
                                label: t('settings.brand.primary'),
                            },
                            {
                                key: 'background_color',
                                label: t('settings.brand.background'),
                            },
                            {
                                key: 'text_color',
                                label: t('settings.brand.text'),
                            },
                        ]"
                        :key="c.key"
                        class="space-y-1.5"
                    >
                        <Label>{{ c.label }}</Label>
                        <div class="flex items-center gap-2">
                            <label
                                class="relative grid size-9 shrink-0 cursor-pointer place-items-center rounded-xs border bg-surface"
                                :class="
                                    (form as any)[c.key]
                                        ? 'border-soft'
                                        : 'border-dashed border-medium'
                                "
                                :style="
                                    (form as any)[c.key]
                                        ? { background: (form as any)[c.key] }
                                        : {}
                                "
                                :title="t('settings.brand.pick_color')"
                            >
                                <Plus
                                    v-if="!(form as any)[c.key]"
                                    class="size-3.5 text-ink-muted"
                                />
                                <input
                                    type="color"
                                    :value="(form as any)[c.key] || '#3b82f6'"
                                    class="absolute inset-0 size-full cursor-pointer opacity-0"
                                    @input="
                                        (form as any)[c.key] = (
                                            $event.target as HTMLInputElement
                                        ).value
                                    "
                                />
                            </label>
                            <Input
                                :model-value="(form as any)[c.key]"
                                class="h-9"
                                placeholder="#RRGGBB"
                                @update:model-value="
                                    (form as any)[c.key] = $event
                                "
                            />
                            <button
                                v-if="(form as any)[c.key]"
                                type="button"
                                class="inline-flex size-9 shrink-0 items-center justify-center rounded-xs border border-soft text-ink-muted transition-colors hover:text-ink"
                                :title="t('settings.brand.clear')"
                                @click="(form as any)[c.key] = ''"
                            >
                                <X class="size-3.5" />
                            </button>
                        </div>
                        <InputError :message="(form.errors as any)[c.key]" />
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
