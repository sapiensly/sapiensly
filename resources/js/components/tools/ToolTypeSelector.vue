<script setup lang="ts">
import type { ToolType, ToolTypeOption } from '@/types/tools';
import { Braces, Code, Database, Globe, Layers, Server } from 'lucide-vue-next';
import type { Component } from 'vue';

defineProps<{
    toolTypes: ToolTypeOption[];
}>();

const emit = defineEmits<{
    select: [type: ToolType];
}>();

function toolIcon(type: ToolType): Component {
    switch (type) {
        case 'function':
            return Code;
        case 'mcp':
            return Server;
        case 'group':
            return Layers;
        case 'rest_api':
            return Globe;
        case 'graphql':
            return Braces;
        case 'database':
            return Database;
        default:
            return Code;
    }
}

/**
 * Per-type tint — reused by the "Configuration" SettingsCard tint in
 * tools/Create.vue + tools/Edit.vue so the tool type reads consistently
 * from the selector step through the form step.
 */
function toolTint(type: ToolType): string {
    switch (type) {
        case 'function':
            return 'var(--sp-accent-blue)';
        case 'mcp':
            return 'var(--sp-success)';
        case 'group':
            return 'var(--sp-spectrum-magenta)';
        case 'rest_api':
            return 'var(--sp-warning)';
        case 'graphql':
            return 'var(--sp-spectrum-indigo)';
        case 'database':
            return 'var(--sp-accent-cyan)';
        default:
            return 'var(--sp-text-secondary)';
    }
}
</script>

<template>
    <div class="grid gap-3 md:grid-cols-3">
        <button
            v-for="type in toolTypes"
            :key="type.value"
            type="button"
            class="flex cursor-pointer flex-col items-start gap-2 rounded-sp-sm border border-soft bg-white/[0.03] p-5 text-left transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
            @click="emit('select', type.value)"
        >
            <div
                class="flex size-9 items-center justify-center rounded-xs"
                :style="{
                    backgroundColor: `color-mix(in oklab, ${toolTint(type.value)} 15%, transparent)`,
                    color: toolTint(type.value),
                }"
            >
                <component :is="toolIcon(type.value)" class="size-4" />
            </div>
            <h3 class="text-sm font-semibold text-ink">{{ type.label }}</h3>
            <p class="text-xs text-ink-muted">{{ type.description }}</p>
        </button>
    </div>
</template>
