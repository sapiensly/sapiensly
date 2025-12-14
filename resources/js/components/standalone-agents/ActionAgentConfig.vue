<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ActionAgentConfig, ToolReference } from '@/types/agents';
import { Wrench } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    config: ActionAgentConfig;
    toolIds: number[];
    tools: ToolReference[];
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: ActionAgentConfig];
    'update:toolIds': [ids: number[]];
}>();

const timeout = computed({
    get: () => props.config.tool_execution?.timeout ?? 30000,
    set: (value: number) => {
        emit('update:config', {
            ...props.config,
            tool_execution: {
                ...props.config.tool_execution,
                timeout: value,
            },
        });
    },
});

const retryCount = computed({
    get: () => props.config.tool_execution?.retry_count ?? 2,
    set: (value: number) => {
        emit('update:config', {
            ...props.config,
            tool_execution: {
                ...props.config.tool_execution,
                retry_count: value,
            },
        });
    },
});

const toggleTool = (id: number, checked: boolean) => {
    const currentIds = [...props.toolIds];
    if (checked) {
        if (!currentIds.includes(id)) {
            currentIds.push(id);
        }
    } else {
        const index = currentIds.indexOf(id);
        if (index > -1) {
            currentIds.splice(index, 1);
        }
    }
    emit('update:toolIds', currentIds);
};

const isSelected = (id: number) => props.toolIds.includes(id);

const toolTypeLabel = (type: string) => {
    switch (type) {
        case 'function':
            return 'Function';
        case 'mcp':
            return 'MCP';
        case 'group':
            return 'Group';
        default:
            return type;
    }
};
</script>

<template>
    <div class="space-y-6">
        <div class="space-y-4">
            <Label>Tools</Label>
            <div v-if="tools.length === 0" class="rounded-lg border border-dashed p-6 text-center">
                <Wrench class="mx-auto h-8 w-8 text-muted-foreground" />
                <p class="mt-2 text-sm text-muted-foreground">
                    No tools available. Create tools to enable action capabilities.
                </p>
            </div>
            <div v-else class="space-y-2">
                <div
                    v-for="tool in tools"
                    :key="tool.id"
                    class="flex items-center space-x-3 rounded-lg border p-3"
                >
                    <Checkbox
                        :id="`tool-${tool.id}`"
                        :checked="isSelected(tool.id)"
                        @update:checked="toggleTool(tool.id, $event)"
                    />
                    <Label :for="`tool-${tool.id}`" class="flex-1 cursor-pointer">
                        {{ tool.name }}
                    </Label>
                    <span class="text-xs text-muted-foreground">
                        {{ toolTypeLabel(tool.type) }}
                    </span>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                If no tools are selected, the agent will operate in pass-through mode.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-2">
                <Label for="timeout">Timeout (ms)</Label>
                <Input
                    id="timeout"
                    v-model.number="timeout"
                    type="number"
                    min="1000"
                    max="300000"
                    step="1000"
                />
                <p class="text-xs text-muted-foreground">
                    Maximum time to wait for tool execution.
                </p>
                <InputError :message="errors['config.tool_execution.timeout']" />
            </div>

            <div class="space-y-2">
                <Label for="retry-count">Retry Count</Label>
                <Input
                    id="retry-count"
                    v-model.number="retryCount"
                    type="number"
                    min="0"
                    max="5"
                />
                <p class="text-xs text-muted-foreground">
                    Number of retries on failure.
                </p>
                <InputError :message="errors['config.tool_execution.retry_count']" />
            </div>
        </div>
    </div>
</template>
