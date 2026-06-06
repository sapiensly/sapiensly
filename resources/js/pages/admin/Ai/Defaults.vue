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
import { Bot, Cpu, Sparkles, Zap } from '@/lib/admin/icons';
import type { AiModel, UUID } from '@/lib/admin/types';
import { Head, router } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';

type Slot = 'primary' | 'fallback';
type ModuleDefaults = { primary: UUID | null; fallback: UUID | null };

interface Props {
    modules: string[];
    defaults: Record<string, ModuleDefaults>;
    chatModels: AiModel[];
}

const props = defineProps<Props>();
const { t } = useI18n();

// Per-module presentation. Icon + tint only; copy comes from i18n keyed by module.
const moduleMeta: Record<string, { icon: Component; tint?: string }> = {
    chat: { icon: Sparkles },
    builder: { icon: Cpu, tint: 'var(--sp-accent-cyan)' },
    flows: { icon: Zap, tint: 'var(--sp-spectrum-indigo)' },
    chatbots: { icon: Bot, tint: 'var(--sp-spectrum-magenta)' },
};

// Local editable copy so a failed PATCH can roll back to the last good value.
const form = reactive<Record<string, ModuleDefaults>>(
    Object.fromEntries(
        props.modules.map((m) => [m, { ...props.defaults[m] }]),
    ),
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

            <div class="grid gap-4 xl:grid-cols-2">
                <SettingsCard
                    v-for="m in modules"
                    :key="m"
                    :icon="moduleMeta[m]?.icon ?? Sparkles"
                    :tint="moduleMeta[m]?.tint"
                    :title="t(`admin.ai.defaults.module.${m}.title`)"
                    :description="t(`admin.ai.defaults.module.${m}.description`)"
                >
                    <div
                        v-for="slot in (['primary', 'fallback'] as const)"
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
                            <SelectTrigger class="h-9 border-medium bg-surface">
                                <SelectValue
                                    :placeholder="t('admin.ai.defaults.unset')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="model in chatModels"
                                    :key="model.id"
                                    :value="model.id"
                                >
                                    <span class="inline-flex items-center gap-2">
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
        </div>
    </AdminLayout>
</template>
