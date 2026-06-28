<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';

interface Crumb {
    id?: string;
    label: string;
    href?: string;
}
interface BreadcrumbBlock {
    id: string;
    type: 'breadcrumb';
    items: Crumb[];
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: BreadcrumbBlock }>();

function isLast(i: number): boolean {
    return i === props.block.items.length - 1;
}
</script>

<template>
    <nav class="flex items-center gap-1 text-sm" :style="{ opacity: 0.85 }">
        <template v-for="(item, i) in block.items" :key="item.id ?? i">
            <a
                v-if="item.href && !isLast(i)"
                :href="item.href"
                class="rounded px-1 transition-opacity hover:opacity-70"
                :style="{ opacity: 0.7 }"
                >{{ item.label }}</a
            >
            <span
                v-else
                class="px-1"
                :class="isLast(i) ? 'font-semibold' : ''"
                :style="isLast(i) ? {} : { opacity: 0.7 }"
                :aria-current="isLast(i) ? 'page' : undefined"
                >{{ item.label }}</span
            >
            <ChevronRight
                v-if="!isLast(i)"
                class="size-3.5 shrink-0"
                :style="{ opacity: 0.4 }"
            />
        </template>
    </nav>
</template>
