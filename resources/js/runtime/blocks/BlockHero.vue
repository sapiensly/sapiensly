<script setup lang="ts">
import { computed, inject } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';

interface HeroStat {
    label?: string;
    format?: 'number' | 'currency' | 'percentage' | 'duration';
}

interface HeroBlock {
    id: string;
    type: 'hero';
    title: string;
    eyebrow?: string;
    eyebrow_icon?: string;
    subtitle?: string;
    background_image?: string;
    overlay?: boolean;
    align?: 'left' | 'center';
    min_height?: number;
    stat?: HeroStat;
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

const props = defineProps<{
    block: HeroBlock;
    data?: { stat?: { value?: number; error?: string } };
    locale?: string;
    defaultCurrency?: string;
}>();
const { execute } = useActionExecutor();

// The hero's live headline figure (its `stat`), formatted per its own format.
// The `unit` (a trailing % / currency symbol) is split out so the template can
// render it smaller than the number — the executive "96.7%" treatment.
const heroStat = computed(() => {
    const stat = props.block.stat;
    const value = props.data?.stat?.value;
    if (!stat || value == null || props.data?.stat?.error) {
        return null;
    }
    const locale = props.locale ?? 'es-MX';
    let display: string;
    let unit: string | null = null;
    if (stat.format === 'percentage') {
        display = new Intl.NumberFormat(locale, {
            maximumFractionDigits: 1,
        }).format(value * 100);
        unit = '%';
    } else if (stat.format === 'currency') {
        display = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: props.defaultCurrency ?? 'MXN',
        }).format(value);
    } else {
        display = new Intl.NumberFormat(locale).format(value);
    }
    return { display, unit, label: stat.label ?? '' };
});

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

// Faint graph-paper grid over the gradient — the signature texture of the
// executive dashboard hero. Skipped for image backgrounds (the photo IS the
// texture). Masked to fade toward the bottom so it never fights the title.
const showGrid = computed(() => !props.block.background_image);
const gridStyle = {
    backgroundImage:
        'linear-gradient(to right, rgba(255,255,255,0.9) 1px, transparent 1px),' +
        'linear-gradient(to bottom, rgba(255,255,255,0.9) 1px, transparent 1px)',
    backgroundSize: '34px 34px',
    // Fade the grid out toward the lower-left where the title sits.
    maskImage:
        'radial-gradient(120% 130% at 100% 0%, black 20%, transparent 75%)',
    WebkitMaskImage:
        'radial-gradient(120% 130% at 100% 0%, black 20%, transparent 75%)',
};

// A small min_height signals a dashboard banner rather than a landing hero:
// slim padding, smaller type, tighter gap — so it introduces the page without
// stealing space or focus from the data below.
const compact = computed(() => (props.block.min_height ?? 420) <= 240);

// Left-aligned heroes hug the section's left edge (mr-auto) instead of sitting
// in a centred column (mx-auto), so the title lines up with the content beneath.
const contentClass = computed(() => {
    const align =
        props.block.align === 'left'
            ? 'mr-auto items-start text-left'
            : 'mx-auto items-center text-center';
    return `${align} ${compact.value ? 'gap-2' : 'gap-5'}`;
});

const sectionPadding = computed(() =>
    compact.value ? 'px-6 py-6 sm:px-8' : 'px-6 py-16 sm:px-12',
);
const titleClass = computed(() =>
    compact.value ? 'text-2xl sm:text-3xl' : 'text-4xl sm:text-5xl',
);
const subtitleClass = computed(() =>
    compact.value ? 'text-sm sm:text-base' : 'text-base sm:text-lg',
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
            'relative flex w-full flex-col justify-center overflow-hidden rounded-sp-md',
            sectionPadding,
            hasCustomBackground ? '' : 'bg-slate-900',
        ]"
        :style="sectionStyle"
    >
        <div
            v-if="showOverlay"
            class="absolute inset-0 bg-gradient-to-b from-black/50 via-black/40 to-black/70"
        ></div>

        <!-- Graph-paper texture + a soft top-right highlight for depth. -->
        <template v-if="showGrid">
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.12]"
                :style="gridStyle"
            ></div>
            <div
                class="pointer-events-none absolute inset-0"
                style="
                    background: radial-gradient(
                        90% 120% at 100% 0%,
                        rgba(255, 255, 255, 0.18),
                        transparent 60%
                    );
                "
            ></div>
        </template>

        <div
            class="relative z-10 flex w-full items-center justify-between gap-6"
        >
            <div :class="['flex max-w-3xl flex-col', contentClass]">
                <p
                    v-if="block.eyebrow"
                    class="flex items-center gap-1.5 text-[11px] font-semibold tracking-[0.14em] text-white/70 uppercase"
                >
                    <RuntimeIcon
                        v-if="block.eyebrow_icon"
                        :name="block.eyebrow_icon"
                        :size="13"
                    />
                    {{ block.eyebrow }}
                </p>
                <h1
                    :class="[
                        'leading-tight font-bold text-balance text-white',
                        titleClass,
                    ]"
                >
                    {{ block.title }}
                </h1>
                <p
                    v-if="block.subtitle"
                    :class="[
                        'max-w-2xl text-pretty text-white/80',
                        subtitleClass,
                    ]"
                >
                    {{ block.subtitle }}
                </p>
                <div v-if="block.cta">
                    <button
                        type="button"
                        @click="clickCta"
                        class="mt-2 inline-flex items-center gap-1.5 rounded-pill px-5 py-2.5 text-sm font-semibold text-white transition-transform hover:scale-[1.03]"
                        :style="{
                            backgroundColor: 'var(--sp-accent, #3b82f6)',
                        }"
                    >
                        {{ block.cta.label }}
                    </button>
                </div>
            </div>

            <!-- Live headline figure, floated as a frosted-glass card. -->
            <div
                v-if="heroStat"
                class="hidden shrink-0 rounded-2xl border border-white/25 bg-white/12 px-6 py-4 text-right shadow-[inset_0_1px_0_0_rgba(255,255,255,0.25)] backdrop-blur-md sm:block"
            >
                <p
                    class="text-4xl leading-none font-extrabold tracking-tight text-white tabular-nums"
                >
                    {{ heroStat.display
                    }}<span
                        v-if="heroStat.unit"
                        class="ml-0.5 text-2xl font-bold text-white/80"
                        >{{ heroStat.unit }}</span
                    >
                </p>
                <p
                    v-if="heroStat.label"
                    class="mt-1.5 text-[10px] font-semibold tracking-[0.12em] text-white/70 uppercase"
                >
                    {{ heroStat.label }}
                </p>
            </div>
        </div>
    </section>
</template>
