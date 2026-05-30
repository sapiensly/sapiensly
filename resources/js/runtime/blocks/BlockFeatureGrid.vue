<script setup lang="ts">
import { computed } from 'vue';

interface FeatureItem {
    id?: string;
    icon?: string;
    title: string;
    description?: string;
}

interface FeatureGridBlock {
    id: string;
    type: 'feature_grid';
    columns?: 2 | 3 | 4;
    items: FeatureItem[];
}

const props = defineProps<{ block: FeatureGridBlock }>();

const colsClass = computed(
    () =>
        ({
            2: 'sm:grid-cols-2',
            3: 'sm:grid-cols-2 lg:grid-cols-3',
            4: 'sm:grid-cols-2 lg:grid-cols-4',
        })[props.block.columns ?? 3],
);
</script>

<template>
    <!-- Cards use currentColor so they adapt to whatever section colour they
         sit on (legible on light or dark backgrounds without per-theme code). -->
    <div :class="['grid grid-cols-1 gap-4', colsClass]">
        <div
            v-for="(item, i) in block.items"
            :key="item.id ?? i"
            class="rounded-xl border p-5"
            :style="{
                borderColor: 'color-mix(in srgb, currentColor 16%, transparent)',
                backgroundColor: 'color-mix(in srgb, currentColor 5%, transparent)',
            }"
        >
            <div v-if="item.icon" class="mb-3 text-3xl leading-none">{{ item.icon }}</div>
            <h3 class="text-base font-semibold">{{ item.title }}</h3>
            <p v-if="item.description" class="mt-1.5 text-sm" :style="{ opacity: 0.75 }">
                {{ item.description }}
            </p>
        </div>
    </div>
</template>
