<script setup lang="ts">
import { ref } from 'vue';
import { ChevronDown } from '@lucide/vue';

interface FaqItem { id?: string; question: string; answer: string }
interface FaqBlock { id: string; type: 'faq'; items: FaqItem[] }

defineOptions({ inheritAttrs: false });

defineProps<{ block: FaqBlock }>();

const open = ref<number | null>(0);
function toggle(i: number) {
    open.value = open.value === i ? null : i;
}
</script>

<template>
    <div class="mx-auto flex max-w-2xl flex-col">
        <div
            v-for="(item, i) in block.items"
            :key="item.id ?? i"
            class="border-b"
            :style="{ borderColor: 'color-mix(in srgb, currentColor 14%, transparent)' }"
        >
            <button
                type="button"
                class="flex w-full items-center justify-between gap-4 py-4 text-left text-base font-semibold"
                @click="toggle(i)"
            >
                {{ item.question }}
                <ChevronDown class="size-4 shrink-0 transition-transform" :class="open === i ? 'rotate-180' : ''" />
            </button>
            <p v-show="open === i" class="pb-4 text-sm leading-relaxed" :style="{ opacity: 0.8 }">{{ item.answer }}</p>
        </div>
    </div>
</template>
