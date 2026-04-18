<script setup lang="ts">
import type { FlowNodeType } from '@/types/flows';
import {
    ArrowRightLeft,
    CircleStop,
    CornerDownLeft,
    GitBranch,
    ListOrdered,
    MessageSquare,
} from 'lucide-vue-next';
import { type Component } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface PaletteItem {
    type: FlowNodeType;
    label: string;
    icon: Component;
}

const items: PaletteItem[] = [
    { type: 'menu', label: 'flows.nodes.menu', icon: ListOrdered },
    { type: 'condition', label: 'flows.nodes.condition', icon: GitBranch },
    {
        type: 'agent_handoff',
        label: 'flows.nodes.agent_handoff',
        icon: ArrowRightLeft,
    },
    { type: 'message', label: 'flows.nodes.message', icon: MessageSquare },
    { type: 'connector', label: 'flows.nodes.connector', icon: CornerDownLeft },
    { type: 'end', label: 'flows.nodes.end', icon: CircleStop },
];

const onDragStart = (event: DragEvent, type: FlowNodeType) => {
    if (event.dataTransfer) {
        event.dataTransfer.setData('application/vueflow', type);
        event.dataTransfer.effectAllowed = 'move';
    }
};
</script>

<template>
    <div class="flex h-full w-[200px] flex-col border-r bg-background">
        <div class="border-b px-3 py-2">
            <h3
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('flows.palette.title') }}
            </h3>
        </div>

        <div class="flex-1 space-y-1 overflow-y-auto p-2">
            <div
                v-for="item in items"
                :key="item.type"
                class="flex cursor-grab items-center gap-2 rounded-md border bg-card px-3 py-2 text-sm transition-colors hover:bg-accent active:cursor-grabbing"
                draggable="true"
                @dragstart="onDragStart($event, item.type)"
            >
                <component
                    :is="item.icon"
                    class="h-4 w-4 text-muted-foreground"
                />
                <span>{{ t(item.label) }}</span>
            </div>
        </div>
    </div>
</template>
