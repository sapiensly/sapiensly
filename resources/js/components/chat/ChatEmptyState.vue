<script setup lang="ts">
import { Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, tm } = useI18n();

const emit = defineEmits<{ pick: [prompt: string] }>();

// Suggestion chips come from a localized array.
const suggestions = computed<string[]>(() => {
    const raw = tm('chat.suggestions') as unknown;
    return Array.isArray(raw) ? (raw as string[]).map((s) => String(s)) : [];
});
</script>

<template>
    <div class="flex flex-1 flex-col items-center justify-center px-4">
        <div class="mb-6 flex size-14 items-center justify-center rounded-2xl bg-accent-blue/15 text-accent-blue">
            <Sparkles class="size-7" />
        </div>
        <h1 class="text-center text-2xl font-semibold text-ink">{{ t('chat.empty.greeting') }}</h1>
        <p class="mt-2 text-center text-sm text-ink-muted">{{ t('chat.empty.subtitle') }}</p>

        <div v-if="suggestions.length" class="mt-7 flex max-w-xl flex-wrap justify-center gap-2">
            <button
                v-for="(s, i) in suggestions"
                :key="i"
                type="button"
                class="rounded-full border border-medium bg-surface px-3.5 py-2 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
                @click="emit('pick', s)"
            >
                {{ s }}
            </button>
        </div>
    </div>
</template>
