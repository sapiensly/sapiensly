<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import type { TriageAgentConfig } from '@/types/agents';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: TriageAgentConfig;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: TriageAgentConfig];
}>();

const temperature = computed({
    get: () => [props.config.temperature ?? 0.3],
    set: (value: number[]) => {
        emit('update:config', {
            ...props.config,
            temperature: value[0],
        });
    },
});

const contentFilters = computed({
    get: () => props.config.guardrails?.content_filters ?? false,
    set: (value: boolean) => {
        emit('update:config', {
            ...props.config,
            guardrails: {
                ...props.config.guardrails,
                content_filters: value,
            },
        });
    },
});
</script>

<template>
    <div class="space-y-5">
        <!-- Temperature slider. -->
        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <Label class="text-xs text-ink-muted">
                    {{ t('agents.config.triage.temperature') }}
                </Label>
                <span class="font-mono text-xs text-ink">
                    {{ temperature[0].toFixed(2) }}
                </span>
            </div>
            <Slider
                v-model="temperature"
                :min="0"
                :max="1"
                :step="0.01"
                class="w-full"
            />
            <p class="text-[11px] text-ink-subtle">
                {{ t('agents.config.triage.temperature_description') }}
            </p>
            <InputError :message="errors['config.temperature']" />
        </div>

        <!-- Guardrails — content filters toggle row. -->
        <div class="space-y-2">
            <Label class="text-xs text-ink-muted">
                {{ t('agents.config.triage.guardrails') }}
            </Label>
            <div
                class="flex items-center justify-between gap-3 rounded-xs border border-soft bg-white/[0.03] p-3"
            >
                <div class="min-w-0 space-y-0.5">
                    <p class="text-sm font-medium text-ink">
                        {{ t('agents.config.triage.content_filters') }}
                    </p>
                    <p class="text-[11px] text-ink-subtle">
                        {{ t('agents.config.triage.content_filters_description') }}
                    </p>
                </div>
                <Switch v-model="contentFilters" />
            </div>
        </div>
    </div>
</template>
