<script setup lang="ts">
import type { AiDriver } from '@/lib/admin/types';
import { computed } from 'vue';

/**
 * Brand-coloured pill for an AI driver. Per the handoff component map,
 * these colours come from the driver's own identity (Anthropic ochre,
 * OpenAI green, etc.) — they're the only place in the admin where brand
 * colours outside the Sapiensly palette are allowed.
 */
interface Props {
    driver: AiDriver;
    size?: 'sm' | 'md';
}

const props = withDefaults(defineProps<Props>(), { size: 'md' });

const config = computed(() => {
    const map: Record<AiDriver, { label: string; color: string; bg: string }> = {
        anthropic: { label: 'Anthropic', color: '#d97757', bg: '#d9775733' },
        openai: { label: 'OpenAI', color: '#10a37f', bg: '#10a37f33' },
        gemini: { label: 'Gemini', color: '#4285f4', bg: '#4285f433' },
        azure: { label: 'Azure', color: '#0078d4', bg: '#0078d433' },
        ollama: { label: 'Ollama', color: '#c084fc', bg: '#c084fc33' },
        custom: { label: 'Custom', color: '#8890a6', bg: '#8890a633' },
    };
    return map[props.driver] ?? map.custom;
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-pill border px-2 text-xs font-medium"
        :class="size === 'sm' ? 'py-0 text-[10px]' : 'py-0.5'"
        :style="{
            borderColor: `${config.color}55`,
            color: config.color,
            backgroundColor: config.bg,
        }"
    >
        <span
            class="inline-block size-1.5 rounded-pill"
            :style="{ backgroundColor: config.color }"
        />
        {{ config.label }}
    </span>
</template>
