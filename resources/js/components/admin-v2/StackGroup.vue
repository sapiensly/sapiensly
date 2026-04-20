<script setup lang="ts">
import StackItemCard from '@/components/admin-v2/StackItemCard.vue';

interface StackItem {
    name: string;
    version: string;
    description: string;
    status: 'ok' | 'outdated' | 'missing';
    docsUrl?: string;
}

defineProps<{
    label: string;
    items: StackItem[];
}>();
</script>

<template>
    <section class="space-y-3">
        <h2
            class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
        >
            {{ label }}
        </h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <StackItemCard
                v-for="item in items"
                :key="item.name"
                :name="item.name"
                :version="item.version"
                :description="item.description"
                :status="item.status"
                :docs-url="item.docsUrl"
            />
        </div>
    </section>
</template>
