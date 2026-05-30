<script setup lang="ts">
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { computed } from 'vue';
import type { AnyBlock, BlockData, ObjectDef } from '../types/manifest';
import AppRenderer from '../AppRenderer.vue';

interface Tab {
    id: string;
    label: string;
    icon?: string;
    blocks: AnyBlock[];
}

interface TabsBlock {
    id: string;
    type: 'tabs';
    tabs: Tab[];
    default_tab_id?: string;
}

const props = defineProps<{
    block: TabsBlock;
    blockData: BlockData;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const defaultValue = computed(() => props.block.default_tab_id ?? props.block.tabs[0]?.id ?? '');
</script>

<template>
    <Tabs :default-value="defaultValue" class="w-full">
        <TabsList>
            <TabsTrigger v-for="tab in block.tabs" :key="tab.id" :value="tab.id">
                {{ tab.label }}
            </TabsTrigger>
        </TabsList>
        <TabsContent v-for="tab in block.tabs" :key="tab.id" :value="tab.id" class="space-y-4 pt-4">
            <AppRenderer
                :blocks="tab.blocks"
                :block-data="blockData"
                :objects="objects"
                :locale="locale"
                :default-currency="defaultCurrency"
            />
        </TabsContent>
    </Tabs>
</template>
