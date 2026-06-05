<script setup lang="ts">
import type { Artifact } from '@/lib/artifacts';
import { Code2, FileText, Image, Loader2, PanelRightOpen, Globe } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{ artifact: Artifact; active?: boolean }>();
const emit = defineEmits<{ open: [artifact: Artifact] }>();

const icon = computed(() => {
    switch (props.artifact.type) {
        case 'html':
            return Globe;
        case 'svg':
            return Image;
        case 'markdown':
            return FileText;
        default:
            return Code2;
    }
});

const subtitle = computed(() => {
    if (!props.artifact.closed) return t('chat.artifact.writing');
    if (props.artifact.language) return props.artifact.language;
    return t(`chat.artifact.type_${props.artifact.type}`);
});
</script>

<template>
    <button
        type="button"
        :class="[
            'my-3 flex w-full items-center gap-3 rounded-xl border p-3 text-left transition-colors',
            active ? 'border-accent-blue bg-accent-blue/5' : 'border-medium bg-surface hover:border-strong hover:bg-surface-hover',
        ]"
        @click="emit('open', artifact)"
    >
        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-blue/12 text-accent-blue">
            <Loader2 v-if="!artifact.closed" class="size-4 animate-spin" />
            <component :is="icon" v-else class="size-4" />
        </div>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-ink">{{ artifact.title }}</p>
            <p class="truncate text-xs text-ink-subtle">{{ subtitle }}</p>
        </div>
        <PanelRightOpen class="size-4 shrink-0 text-ink-subtle" />
    </button>
</template>
