<script setup lang="ts">
import type { FlowNodeType } from '@/types/flows';
import {
    ArrowRightLeft,
    CircleStop,
    CornerDownLeft,
    GitBranch,
    GripVertical,
    ListOrdered,
    MessageSquare,
} from 'lucide-vue-next';
import { type Component } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface PaletteItem {
    type: FlowNodeType;
    label: string;
    description: string;
    icon: Component;
    /** Tint drives the icon tile bg + icon color. Matches the node colour on canvas. */
    tint: string;
}

const items: PaletteItem[] = [
    {
        type: 'menu',
        label: 'flows.nodes.menu',
        description: 'flows.palette.menu_description',
        icon: ListOrdered,
        tint: 'var(--sp-warning)',
    },
    {
        type: 'condition',
        label: 'flows.nodes.condition',
        description: 'flows.palette.condition_description',
        icon: GitBranch,
        tint: 'var(--sp-accent-cyan)',
    },
    {
        type: 'agent_handoff',
        label: 'flows.nodes.agent_handoff',
        description: 'flows.palette.agent_handoff_description',
        icon: ArrowRightLeft,
        tint: 'var(--sp-spectrum-magenta)',
    },
    {
        type: 'message',
        label: 'flows.nodes.message',
        description: 'flows.palette.message_description',
        icon: MessageSquare,
        tint: 'var(--sp-accent-blue)',
    },
    {
        type: 'connector',
        label: 'flows.nodes.connector',
        description: 'flows.palette.connector_description',
        icon: CornerDownLeft,
        tint: 'var(--sp-spectrum-indigo)',
    },
    {
        type: 'end',
        label: 'flows.nodes.end',
        description: 'flows.palette.end_description',
        icon: CircleStop,
        tint: 'var(--sp-danger)',
    },
];

const onDragStart = (event: DragEvent, type: FlowNodeType) => {
    if (event.dataTransfer) {
        event.dataTransfer.setData('application/vueflow', type);
        event.dataTransfer.effectAllowed = 'move';
    }
};
</script>

<template>
    <div class="flex h-full w-[240px] flex-col border-r border-soft bg-navy">
        <div class="border-b border-soft px-3 py-3">
            <h3
                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
            >
                {{ t('flows.palette.title') }}
            </h3>
        </div>

        <div class="flex-1 space-y-1.5 overflow-y-auto p-2">
            <div
                v-for="item in items"
                :key="item.type"
                class="group flex cursor-grab items-center gap-3 rounded-sp-sm border border-soft bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06] active:cursor-grabbing"
                draggable="true"
                @dragstart="onDragStart($event, item.type)"
            >
                <!-- Colored icon tile — bg = tint @ 15% over navy, icon in tint. -->
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-xs"
                    :style="{
                        backgroundColor: `color-mix(in oklab, ${item.tint} 18%, transparent)`,
                        color: item.tint,
                    }"
                >
                    <component :is="item.icon" class="size-4" />
                </div>

                <div class="min-w-0 flex-1 leading-tight">
                    <p class="truncate text-[13px] font-medium text-ink">
                        {{ t(item.label) }}
                    </p>
                    <p class="truncate text-[11px] text-ink-subtle">
                        {{ t(item.description) }}
                    </p>
                </div>

                <!-- Drag grip — subtle, brightens on hover. -->
                <GripVertical
                    class="size-3.5 shrink-0 text-ink-subtle opacity-60 transition-opacity group-hover:opacity-100"
                />
            </div>
        </div>
    </div>
</template>
