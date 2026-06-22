<script setup lang="ts">
import AiTabs from '@/components/admin/AiTabs.vue';
import DriverChip from '@/components/admin/DriverChip.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout.vue';
import {
    Bot,
    Brain,
    Cpu,
    Database,
    Eye,
    FileText,
    NavStack,
    Radio,
    ScrollText,
    Sparkles,
    Star,
    Zap,
} from '@/lib/admin/icons';
import type { AiModel, UUID } from '@/lib/admin/types';
import { Head, router } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';

type Slot = 'primary' | 'fallback';
type ModuleDefaults = { primary: UUID | null; fallback: UUID | null };

interface Props {
    modules: string[];
    capabilityModules: string[];
    moduleCapability: Record<string, string>;
    defaults: Record<string, ModuleDefaults>;
    modelsByCapability: Record<string, AiModel[]>;
}

const props = defineProps<Props>();
const { t } = useI18n();

// Per-module presentation. Icon + tint only; copy comes from i18n keyed by module.
const moduleMeta: Record<string, { icon: Component; tint?: string }> = {
    chat: { icon: Sparkles },
    summary_short: { icon: FileText, tint: 'var(--sp-spectrum-cyan)' },
    summary_large: { icon: ScrollText, tint: 'var(--sp-spectrum-indigo)' },
    builder: { icon: Cpu, tint: 'var(--sp-accent-cyan)' },
    flows: { icon: Zap, tint: 'var(--sp-spectrum-indigo)' },
    chatbots: { icon: Bot, tint: 'var(--sp-spectrum-magenta)' },
    embeddings: { icon: Database, tint: 'var(--sp-spectrum-cyan)' },
    coding: { icon: Brain, tint: 'var(--sp-accent-cyan)' },
    ocr_pdf: { icon: FileText, tint: 'var(--sp-spectrum-indigo)' },
    ocr_image: { icon: Eye, tint: 'var(--sp-spectrum-indigo)' },
    image_generation: { icon: Star, tint: 'var(--sp-spectrum-magenta)' },
    vision: { icon: Eye, tint: 'var(--sp-spectrum-cyan)' },
    audio_recognition: { icon: Radio, tint: 'var(--sp-spectrum-indigo)' },
    speech_generation: { icon: Radio, tint: 'var(--sp-spectrum-magenta)' },
    reranking: { icon: NavStack, tint: 'var(--sp-accent-cyan)' },
};

// The product modules (everything that isn't a specialized capability handler).
const productModules = computed(() =>
    props.modules.filter((m) => !props.capabilityModules.includes(m)),
);

function modelsFor(module: string): AiModel[] {
    return props.modelsByCapability[props.moduleCapability[module]] ?? [];
}

// Local editable copy so a failed PATCH can roll back to the last good value.
const form = reactive<Record<string, ModuleDefaults>>(
    Object.fromEntries(props.modules.map((m) => [m, { ...props.defaults[m] }])),
);

function updateModel(module: string, slot: Slot, id: string | null) {
    const prev = form[module][slot];
    form[module][slot] = id;

    router.patch(
        '/admin/ai/defaults',
        { [module]: { [slot]: id } },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['defaults'],
            onSuccess: () => {
                form[module] = { ...props.defaults[module] };
            },
            onError: () => {
                form[module][slot] = prev;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('admin.nav.ai')" />

    <AdminLayout :title="t('admin.nav.ai')">
        <div class="mx-auto max-w-5xl space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] leading-tight font-semibold text-ink">
                    {{ t('admin.ai.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin.ai.defaults.description') }}
                </p>
            </header>

            <AiTabs current="defaults" />

            <section
                v-for="group in [
                    { key: 'product', items: productModules },
                    { key: 'capabilities', items: capabilityModules },
                ]"
                :key="group.key"
                class="space-y-3"
            >
                <h2
                    class="text-xs font-semibold tracking-wide text-ink-muted uppercase"
                >
                    {{ t(`admin.ai.defaults.group.${group.key}`) }}
                </h2>
                <div class="grid gap-4 xl:grid-cols-2">
                    <SettingsCard
                        v-for="m in group.items"
                        :key="m"
                        :icon="moduleMeta[m]?.icon ?? Sparkles"
                        :tint="moduleMeta[m]?.tint"
                        :title="t(`admin.ai.defaults.module.${m}.title`)"
                        :description="
                            t(`admin.ai.defaults.module.${m}.description`)
                        "
                    >
                        <div
                            v-for="slot in ['primary', 'fallback'] as const"
                            :key="slot"
                            class="space-y-1.5"
                        >
                            <Label class="text-xs text-ink-muted">
                                {{ t(`admin.ai.defaults.${slot}_label`) }}
                            </Label>
                            <Select
                                :model-value="form[m][slot] ?? ''"
                                @update:model-value="
                                    (v) =>
                                        updateModel(
                                            m,
                                            slot,
                                            v === '' ? null : String(v),
                                        )
                                "
                            >
                                <SelectTrigger
                                    class="h-9 border-medium bg-surface"
                                >
                                    <SelectValue
                                        :placeholder="
                                            modelsFor(m).length
                                                ? t('admin.ai.defaults.unset')
                                                : t(
                                                      'admin.ai.defaults.no_models',
                                                  )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="model in modelsFor(m)"
                                        :key="model.id"
                                        :value="model.id"
                                    >
                                        <span
                                            class="inline-flex items-center gap-2"
                                        >
                                            <DriverChip
                                                :driver="model.driver"
                                                size="sm"
                                            />
                                            {{ model.name }}
                                        </span>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <p class="text-[10px] text-ink-subtle">
                            {{ t('admin.ai.defaults.fallback_hint') }}
                        </p>
                    </SettingsCard>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
