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
    style?: {
        background?: string;
        gradient?: { from: string; to: string; direction?: string };
    };
}

// Match the renderer's gradient direction vocabulary so a hero honours the same
// style.gradient authors set on any other block.
const GRADIENT_DIR: Record<string, string> = {
    'to-b': 'to bottom',
    'to-r': 'to right',
    'to-br': 'to bottom right',
    'to-tr': 'to top right',
};

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
    const s = props.block.style;
    if (props.block.background_image) {
        styles.backgroundImage = `url("${props.block.background_image}")`;
        styles.backgroundSize = 'cover';
        styles.backgroundPosition = 'center';
    } else if (s?.gradient) {
        const dir =
            GRADIENT_DIR[s.gradient.direction ?? 'to-br'] ?? 'to bottom right';
        styles.backgroundImage = `linear-gradient(${dir}, ${s.gradient.from}, ${s.gradient.to})`;
    } else if (s?.background) {
        styles.backgroundColor = s.background;
    }
    return styles;
});

// A brand gradient/colour replaces the default dark panel; without one we keep
// the slate fallback so a bare hero (or one with a background image) still reads.
const hasCustomBackground = computed(
    () => !!(props.block.style?.gradient || props.block.style?.background),
);

const alignClass = computed(() =>
    props.block.align === 'left'
        ? 'items-start text-left'
        : 'items-center text-center',
);
const showOverlay = computed(
    () => props.block.overlay !== false && !!props.block.background_image,
);

async function clickCta() {
    if (props.block.cta) {
        await execute(props.block.cta.on_click ?? [], { appSlug });
    }
}
</script>

<template>
    <section
        :class="[
            'relative flex w-full flex-col justify-center overflow-hidden rounded-sp-md px-6 py-16 sm:px-12',
            hasCustomBackground ? '' : 'bg-slate-900',
        ]"
        :style="sectionStyle"
    >
        <div
            v-if="showOverlay"
            class="absolute inset-0 bg-gradient-to-b from-black/50 via-black/40 to-black/70"
        ></div>

        <div
            :class="[
                'relative z-10 mx-auto flex max-w-3xl flex-col gap-5',
                alignClass,
            ]"
        >
            <h1
                class="text-4xl leading-tight font-bold text-balance text-white sm:text-5xl"
            >
                {{ block.title }}
            </h1>
            <p
                v-if="block.subtitle"
                class="max-w-2xl text-base text-pretty text-white/80 sm:text-lg"
            >
                {{ block.subtitle }}
            </p>
            <div v-if="block.cta">
                <button
                    type="button"
                    @click="clickCta"
                    class="mt-2 inline-flex items-center gap-1.5 rounded-pill px-5 py-2.5 text-sm font-semibold text-white transition-transform hover:scale-[1.03]"
                    :style="{ backgroundColor: 'var(--sp-accent, #3b82f6)' }"
                >
                    {{ block.cta.label }}
                </button>
            </div>
        </div>
    </section>
</template>
