<script setup lang="ts">
import { computed, inject } from 'vue';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';

interface CtaBlock {
    id: string;
    type: 'cta';
    title: string;
    subtitle?: string;
    align?: 'left' | 'center';
    button?: { label: string; on_click: RuntimeAction[] };
}

const props = defineProps<{ block: CtaBlock }>();
const { execute } = useActionExecutor();

const appSlug = inject<string>('appSlug', deriveSlugFromUrl());
function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/r\/([a-z][a-z0-9_]*)/);
    return m?.[1] ?? '';
}

const alignClass = computed(() => (props.block.align === 'left' ? 'items-start text-left' : 'items-center text-center'));

async function click() {
    if (props.block.button) {
        await execute(props.block.button.on_click ?? [], { appSlug });
    }
}
</script>

<template>
    <div :class="['flex flex-col gap-4', alignClass]">
        <h2 class="text-2xl font-bold sm:text-3xl">{{ block.title }}</h2>
        <p v-if="block.subtitle" class="max-w-2xl text-base" :style="{ opacity: 0.8 }">
            {{ block.subtitle }}
        </p>
        <div v-if="block.button">
            <button
                type="button"
                @click="click"
                class="mt-1 inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-5 py-2.5 text-sm font-semibold text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
            >
                {{ block.button.label }}
            </button>
        </div>
    </div>
</template>
