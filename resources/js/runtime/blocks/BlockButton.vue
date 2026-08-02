<script setup lang="ts">
import { computed, inject } from 'vue';
import { confirmAction } from '../confirm';
import RuntimeIcon from '../RuntimeIcon.vue';
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
// Injected like the slug rather than threaded as a prop: a button is nested
// inside containers, modals and tabs, and every one of them would have to pass
// it down for the confirm dialog to speak the app's language.
const locale = inject<string>('runtimeLocale', 'en');
function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/[ra]\/([a-z0-9][a-z0-9_-]*)/);
    return m?.[1] ?? '';
}

const variantClass = computed(() => {
    switch (props.block.variant ?? 'secondary') {
        case 'primary':
            return 'bg-accent-blue text-white hover:bg-accent-blue-hover';
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
        case 'sm':
            return 'px-2.5 py-1 text-[11px]';
        case 'lg':
            return 'px-4 py-2 text-sm';
        default:
            return 'px-3.5 py-1.5 text-xs';
    }
});

async function click() {
    // The browser's confirm carried the origin, ignored the theme and threw the
    // title away — this block's `confirm.title` was authored and never shown.
    if (props.block.confirm?.message) {
        const ok = await confirmAction({
            title: props.block.confirm.title,
            message: props.block.confirm.message,
            locale,
            danger: props.block.variant === 'danger',
        });
        if (!ok) return;
    }
    await execute(props.block.on_click ?? [], { appSlug });
}
</script>

<template>
    <button
        type="button"
        @click="click"
        :class="[
            'inline-flex items-center gap-1.5 rounded-pill font-medium transition-colors',
            variantClass,
            sizeClass,
        ]"
    >
        <RuntimeIcon v-if="block.icon" :name="block.icon" :size="15" />
        {{ block.label }}
    </button>
</template>
