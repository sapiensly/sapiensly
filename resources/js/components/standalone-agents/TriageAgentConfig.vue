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
    <div class="space-y-6">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <Label>{{ t('agents.config.triage.temperature') }}</Label>
                <span class="text-sm text-muted-foreground">
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
            <p class="text-xs text-muted-foreground">
                {{ t('agents.config.triage.temperature_description') }}
            </p>
            <InputError :message="errors['config.temperature']" />
        </div>

        <div class="space-y-4">
            <Label>{{ t('agents.config.triage.guardrails') }}</Label>
            <div class="flex items-center justify-between rounded-lg border p-4">
                <div class="space-y-0.5">
                    <div class="text-sm font-medium">{{ t('agents.config.triage.content_filters') }}</div>
                    <div class="text-xs text-muted-foreground">
                        {{ t('agents.config.triage.content_filters_description') }}
                    </div>
                </div>
                <Switch v-model:checked="contentFilters" />
            </div>
        </div>
    </div>
</template>
