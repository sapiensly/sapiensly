<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Slide {
    id?: string;
    image: string;
    title?: string;
    caption?: string;
    href?: string;
}
interface CarouselBlock {
    id: string;
    type: 'carousel';
    items: Slide[];
    autoplay?: boolean;
    interval_ms?: number;
    height_px?: number;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: CarouselBlock }>();

const index = ref(0);
const count = computed(() => props.block.items.length);
const height = computed(() => (props.block.height_px ?? 360) + 'px');

function go(to: number) {
    index.value = (to + count.value) % count.value;
}

let timer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    if (props.block.autoplay && count.value > 1) {
        timer = setInterval(
            () => go(index.value + 1),
            Math.min(15000, Math.max(2000, props.block.interval_ms ?? 5000)),
        );
    }
});
onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <div class="relative overflow-hidden rounded-xl" :style="{ height }">
        <component
            :is="block.items[index].href ? 'a' : 'div'"
            :href="block.items[index].href"
            class="block size-full"
        >
            <img
                :src="block.items[index].image"
                :alt="block.items[index].title ?? ''"
                class="size-full object-cover"
            />
            <div
                v-if="block.items[index].title || block.items[index].caption"
                class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-5 text-white"
            >
                <div
                    v-if="block.items[index].title"
                    class="text-lg font-semibold"
                >
                    {{ block.items[index].title }}
                </div>
                <p
                    v-if="block.items[index].caption"
                    class="mt-1 text-sm opacity-90"
                >
                    {{ block.items[index].caption }}
                </p>
            </div>
        </component>

        <template v-if="count > 1">
            <button
                type="button"
                class="absolute top-1/2 left-2 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-black/40 text-white transition-colors hover:bg-black/60"
                aria-label="Previous"
                @click="go(index - 1)"
            >
                <ChevronLeft class="size-5" />
            </button>
            <button
                type="button"
                class="absolute top-1/2 right-2 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-black/40 text-white transition-colors hover:bg-black/60"
                aria-label="Next"
                @click="go(index + 1)"
            >
                <ChevronRight class="size-5" />
            </button>
            <div
                class="absolute inset-x-0 bottom-2 flex items-center justify-center gap-1.5"
            >
                <button
                    v-for="(s, i) in block.items"
                    :key="s.id ?? i"
                    type="button"
                    class="size-2 rounded-full transition-colors"
                    :class="i === index ? 'bg-white' : 'bg-white/40'"
                    :aria-label="`Go to slide ${i + 1}`"
                    @click="go(i)"
                />
            </div>
        </template>
    </div>
</template>
