<script setup lang="ts">
/**
 * Full-surface loading state for a dashboard: frosted-glass veil over the
 * (skeleton) content with the Claude-design loader card in front — comet-arc
 * ring cycling bar → line → donut mini-charts, title, and a blinking status.
 * Reusable anywhere a board resolves live data (runtime deferred load,
 * builder preview refresh).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        accent?: string;
        /** Pin the card to the viewport (runtime) or to the veil's own
         *  container (builder preview pane). */
        viewport?: boolean;
        lang?: string;
        title?: string;
        subtitle?: string;
    }>(),
    {
        accent: '#0059ff',
        viewport: true,
        lang: 'es',
        title: undefined,
        subtitle: undefined,
    },
);

const idx = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        idx.value = (idx.value + 1) % 3;
    }, 1500);
});
onBeforeUnmount(() => clearInterval(timer));

function rgba(hex: string, a: number): string {
    const h = (hex || '#0059ff').replace('#', '');
    const full = h.length === 3 ? [...h].map((c) => c + c).join('') : h;
    const n = parseInt(full, 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`;
}

const accentFaint = computed(() => rgba(props.accent, 0.28));
const copy = computed(() =>
    props.lang?.startsWith('en')
        ? {
              title: 'Preparing your dashboard',
              subtitle:
                  'Gathering your metrics and reports — this will only take a moment.',
              status: 'Loading',
          }
        : {
              title: 'Preparando tu dashboard',
              subtitle:
                  'Reuniendo tus métricas y reportes — solo tomará un momento.',
              status: 'Cargando',
          },
);

const chartStyle = (i: number) => ({
    opacity: idx.value === i ? 1 : 0,
    transform: idx.value === i ? 'scale(1)' : 'scale(0.78)',
});
</script>

<template>
    <div class="dl-veil absolute inset-0 z-30">
        <!-- The card pins to the TOP of the viewport: always in sight on
             page load, clear of the (blurred) content behind. -->
        <div
            class="pointer-events-none inset-x-0 top-0 z-40 flex justify-center pt-20"
            :class="viewport ? 'fixed' : 'absolute'"
        >
            <div class="dl-card pointer-events-auto">
                <!-- spinner with cycling mini-charts -->
                <div
                    class="relative mb-[30px] flex size-[92px] items-center justify-center"
                >
                    <div
                        class="dl-track absolute inset-0 rounded-full border-[2.5px]"
                    />
                    <div
                        class="dl-arc absolute inset-0 rounded-full"
                        :style="{
                            background: `conic-gradient(from 0deg, rgba(0,0,0,0) 0deg, ${accentFaint} 140deg, ${accent} 320deg)`,
                        }"
                    />

                    <!-- bar chart -->
                    <div
                        class="dl-mini absolute inset-0 flex items-end justify-center gap-1 px-[30px] py-[32px]"
                        :style="chartStyle(0)"
                    >
                        <span
                            v-for="(h, i) in [42, 74, 56, 100]"
                            :key="i"
                            class="w-1.5 rounded-[3px]"
                            :style="{ height: h + '%', background: accent }"
                        />
                    </div>

                    <!-- line chart -->
                    <div
                        class="dl-mini absolute inset-0 flex items-center justify-center"
                        :style="chartStyle(1)"
                    >
                        <svg
                            width="34"
                            height="28"
                            viewBox="0 0 34 28"
                            fill="none"
                        >
                            <polyline
                                points="2,22 9,14 16,17 24,7 32,2"
                                :stroke="accent"
                                stroke-width="2.6"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                            <circle cx="32" cy="2" r="3" :fill="accent" />
                        </svg>
                    </div>

                    <!-- donut chart -->
                    <div
                        class="dl-mini absolute inset-0 flex items-center justify-center"
                        :style="chartStyle(2)"
                    >
                        <span
                            class="dl-donut block size-[30px] rounded-full"
                            :style="{
                                background: `conic-gradient(${accent} 0 66%, var(--sp-tint-strong) 0 100%)`,
                            }"
                        />
                    </div>
                </div>

                <p
                    class="dl-title mb-[9px] text-[18px] font-semibold tracking-[-0.35px]"
                >
                    {{ title ?? copy.title }}
                </p>
                <p
                    class="dl-subtitle mb-[30px] max-w-[290px] text-[13.5px] leading-relaxed"
                >
                    {{ subtitle ?? copy.subtitle }}
                </p>

                <div
                    class="dl-status flex items-center gap-1.5 text-[12px] font-medium tracking-[0.2px]"
                >
                    <span>{{ copy.status }}</span>
                    <span class="inline-flex gap-[3px]">
                        <span
                            v-for="i in 3"
                            :key="i"
                            class="dl-dot size-[3px] rounded-full"
                            :style="{
                                background: accent,
                                animationDelay: (i - 1) * 0.2 + 's',
                            }"
                        />
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Every surface/ink here rides the --sp-* tokens, so the veil tints with the
   page (frosted white on light, frosted navy on dark) instead of washing a dark
   board out to grey. The tokens come from the nearest scope: `.dark` on <html>
   in the runtime, `.theme-light`/`.theme-dark` on the Builder's preview pane. */
.dl-veil {
    background: color-mix(
        in srgb,
        var(--sp-bg-primary, #f3f5fb) 42%,
        transparent
    );
    backdrop-filter: blur(7px);
    -webkit-backdrop-filter: blur(7px);
}
.dl-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    width: 404px;
    max-width: calc(100% - 40px);
    background: var(--sp-bg-secondary, #ffffff);
    border: 1px solid var(--sp-border-soft, #eef0f5);
    border-radius: 24px;
    box-shadow: var(--sp-shadow-image, 0 20px 60px rgba(11, 15, 41, 0.18));
    padding: 50px 46px 40px;
    animation: dl-rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.dl-track {
    border-color: var(--sp-tint-strong, rgba(11, 15, 41, 0.12));
}
.dl-title {
    color: var(--sp-text-primary, #0b0f29);
}
.dl-subtitle {
    color: var(--sp-text-secondary, #56607a);
}
.dl-status {
    color: var(--sp-text-tertiary, rgba(11, 15, 41, 0.45));
}
.dl-arc {
    -webkit-mask: radial-gradient(
        farthest-side,
        transparent calc(100% - 2.5px),
        #000 calc(100% - 2.5px)
    );
    mask: radial-gradient(
        farthest-side,
        transparent calc(100% - 2.5px),
        #000 calc(100% - 2.5px)
    );
    animation: dl-spin 1.5s linear infinite;
}
.dl-mini {
    transition:
        opacity 0.5s ease,
        transform 0.5s cubic-bezier(0.16, 1, 0.3, 1);
}
.dl-donut {
    -webkit-mask: radial-gradient(
        circle 8.5px at center,
        transparent 98%,
        #000 100%
    );
    mask: radial-gradient(circle 8.5px at center, transparent 98%, #000 100%);
}
.dl-dot {
    animation: dl-blink 1.4s ease-in-out infinite;
}
@keyframes dl-spin {
    to {
        transform: rotate(360deg);
    }
}
@keyframes dl-rise {
    from {
        opacity: 0;
        transform: translateY(12px) scale(0.985);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
@keyframes dl-blink {
    0%,
    100% {
        opacity: 0.25;
    }
    50% {
        opacity: 1;
    }
}
</style>
