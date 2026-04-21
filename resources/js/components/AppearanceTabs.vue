<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { appearance, updateAppearance } = useAppearance();

const tabs = computed(() => [
    {
        value: 'light' as const,
        Icon: Sun,
        label: t('settings.appearance.light'),
    },
    {
        value: 'dark' as const,
        Icon: Moon,
        label: t('settings.appearance.dark'),
    },
    {
        value: 'system' as const,
        Icon: Monitor,
        label: t('settings.appearance.system'),
    },
]);
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-pill border border-soft bg-white/5 p-1"
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :class="[
                'inline-flex items-center gap-1.5 rounded-pill px-3 py-1 text-xs font-medium transition-colors',
                appearance === value
                    ? 'bg-accent-blue/15 text-ink'
                    : 'text-ink-muted hover:bg-white/5 hover:text-ink',
            ]"
            @click="updateAppearance(value)"
        >
            <component :is="Icon" class="size-3.5" />
            {{ label }}
        </button>
    </div>
</template>
