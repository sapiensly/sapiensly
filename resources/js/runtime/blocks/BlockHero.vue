<script setup lang="ts">
import { computed, inject } from 'vue';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';

interface HeroBlock {
    id: string;
    type: 'hero';
    title: string;
    subtitle?: string;
    background_image?: string;
    overlay?: boolean;
    align?: 'left' | 'center';
    min_height?: number;
    cta?: { label: string; on_click: RuntimeAction[] };
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: HeroBlock }>();
const { execute } = useActionExecutor();

const appSlug = inject<string>('appSlug', deriveSlugFromUrl());
function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/r\/([a-z][a-z0-9_]*)/);
    return m?.[1] ?? '';
}

const sectionStyle = computed(() => {
    const styles: Record<string, string> = {
        minHeight: `${props.block.min_height ?? 420}px`,
    };
    if (props.block.background_image) {
        styles.backgroundImage = `url("${props.block.background_image}")`;
        styles.backgroundSize = 'cover';
        styles.backgroundPosition = 'center';
    }
    return styles;
});

const alignClass = computed(() => (props.block.align === 'left' ? 'items-start text-left' : 'items-center text-center'));
const showOverlay = computed(() => props.block.overlay !== false && !!props.block.background_image);

async function clickCta() {
    if (props.block.cta) {
        await execute(props.block.cta.on_click ?? [], { appSlug });
    }
}
</script>

<template>
    <section
        class="relative flex w-full flex-col justify-center overflow-hidden rounded-sp-md bg-slate-900 px-6 py-16 sm:px-12"
        :style="sectionStyle"
    >
        <div v-if="showOverlay" class="absolute inset-0 bg-gradient-to-b from-black/50 via-black/40 to-black/70"></div>

        <div :class="['relative z-10 mx-auto flex max-w-3xl flex-col gap-5', alignClass]">
            <h1 class="text-balance text-4xl font-bold leading-tight text-white sm:text-5xl">
                {{ block.title }}
            </h1>
            <p v-if="block.subtitle" class="max-w-2xl text-pretty text-base text-white/80 sm:text-lg">
                {{ block.subtitle }}
            </p>
            <div v-if="block.cta">
                <button
                    type="button"
                    @click="clickCta"
                    class="mt-2 inline-flex items-center gap-1.5 rounded-pill px-5 py-2.5 text-sm font-semibold text-white shadow-btn-primary transition-transform hover:scale-[1.03]"
                    :style="{ backgroundColor: 'var(--sp-accent, #3b82f6)' }"
                >
                    {{ block.cta.label }}
                </button>
            </div>
        </div>
    </section>
</template>
