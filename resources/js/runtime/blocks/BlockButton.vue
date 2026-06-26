<script setup lang="ts">
import { computed, inject } from 'vue';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';

interface ButtonBlock {
    id: string;
    type: 'button';
    label: string;
    icon?: string;
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    size?: 'sm' | 'md' | 'lg';
    on_click: RuntimeAction[];
    confirm?: { title?: string; message?: string };
}

const props = defineProps<{ block: ButtonBlock }>();
const { execute } = useActionExecutor();

const appSlug = inject<string>('appSlug', deriveSlugFromUrl());
function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/r\/([a-z][a-z0-9_]*)/);
    return m?.[1] ?? '';
}

const variantClass = computed(() => {
    switch (props.block.variant ?? 'secondary') {
        case 'primary':
            return 'bg-accent-blue text-white shadow-btn-primary hover:bg-accent-blue-hover';
        case 'danger':
            return 'border border-red-500/40 bg-red-500/5 text-red-400 hover:border-red-500/70 hover:bg-red-500/10';
        case 'ghost':
            return 'text-ink-muted hover:bg-surface hover:text-ink';
        default:
            return 'border border-medium bg-surface text-ink transition-colors hover:border-strong hover:bg-surface-hover';
    }
});

const sizeClass = computed(() => {
    switch (props.block.size ?? 'md') {
        case 'sm': return 'px-2.5 py-1 text-[11px]';
        case 'lg': return 'px-4 py-2 text-sm';
        default: return 'px-3.5 py-1.5 text-xs';
    }
});

async function click() {
    if (props.block.confirm?.message) {
        if (! window.confirm(props.block.confirm.message)) return;
    }
    await execute(props.block.on_click ?? [], { appSlug });
}
</script>

<template>
    <button
        type="button"
        @click="click"
        :class="['inline-flex items-center gap-1.5 rounded-pill font-medium transition-colors', variantClass, sizeClass]"
    >
        {{ block.label }}
    </button>
</template>
