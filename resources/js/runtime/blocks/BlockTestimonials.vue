<script setup lang="ts">
import { computed } from 'vue';

interface Testimonial { id?: string; quote: string; author?: string; role?: string; avatar?: string }
interface TestimonialsBlock { id: string; type: 'testimonials'; columns?: 1 | 2 | 3; items: Testimonial[] }

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: TestimonialsBlock }>();

const colsClass = computed(
    () => ({ 1: '', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-2 lg:grid-cols-3' })[props.block.columns ?? 3],
);
</script>

<template>
    <div :class="['grid grid-cols-1 gap-4', colsClass]">
        <figure
            v-for="(item, i) in block.items"
            :key="item.id ?? i"
            class="flex flex-col gap-4 rounded-xl border p-6"
            :style="{
                borderColor: 'color-mix(in srgb, currentColor 14%, transparent)',
                backgroundColor: 'color-mix(in srgb, currentColor 4%, transparent)',
            }"
        >
            <blockquote class="text-base leading-relaxed">“{{ item.quote }}”</blockquote>
            <figcaption v-if="item.author || item.role" class="mt-auto flex items-center gap-3">
                <img
                    v-if="item.avatar"
                    :src="item.avatar"
                    :alt="item.author ?? ''"
                    class="size-10 rounded-full object-cover"
                    loading="lazy"
                />
                <div>
                    <div v-if="item.author" class="text-sm font-semibold">{{ item.author }}</div>
                    <div v-if="item.role" class="text-xs" :style="{ opacity: 0.7 }">{{ item.role }}</div>
                </div>
            </figcaption>
        </figure>
    </div>
</template>
