<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ActionAgentConfig, ToolReference } from '@/types/agents';
import { Wrench } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: ActionAgentConfig;
    toolIds: string[];
    tools: ToolReference[];
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: ActionAgentConfig];
    'update:toolIds': [ids: string[]];
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

const toggleTool = (id: string, checked: boolean) => {
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

const isSelected = (id: string) => props.toolIds.includes(id);

const toolTypeLabel = (type: string) => {
    switch (type) {
        case 'function':
            return 'Function';
        case 'mcp':
            return 'MCP';
        case 'group':
            return 'Group';
        case 'rest_api':
            return 'REST API';
        case 'graphql':
            return 'GraphQL';
        case 'database':
            return 'Database';
        default:
            return type;
    }
};
</script>

<template>
    <div class="space-y-5">
        <!-- Tool picker. -->
        <div class="space-y-2">
            <Label class="text-xs text-ink-muted">
                {{ t('agents.config.action.tools') }}
            </Label>
            <div
                v-if="tools.length === 0"
                class="rounded-xs border border-dashed border-soft bg-navy/40 p-6 text-center"
            >
                <div
                    class="mx-auto flex size-9 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <Wrench class="size-4" />
                </div>
                <p class="mt-3 text-sm font-medium text-ink">
                    {{ t('agents.config.action.no_tools') }}
                </p>
                <p class="mt-0.5 text-[11px] text-ink-subtle">
                    {{ t('agents.config.action.create_tools_note') }}
                </p>
            </div>
            <div v-else class="space-y-1.5">
                <label
                    v-for="tool in tools"
                    :key="tool.id"
                    :for="`tool-${tool.id}`"
                    class="flex cursor-pointer items-center gap-3 rounded-xs border border-soft bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                >
                    <Checkbox
                        :id="`tool-${tool.id}`"
                        :model-value="isSelected(tool.id)"
                        @update:model-value="
                            toggleTool(tool.id, $event as boolean)
                        "
                    />
                    <span class="flex-1 text-sm text-ink">{{ tool.name }}</span>
                    <span
                        class="inline-flex items-center rounded-pill border border-medium px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                    >
                        {{ toolTypeLabel(tool.type) }}
                    </span>
                </label>
            </div>
            <p class="text-[11px] text-ink-subtle">
                If no tools are selected, the agent will operate in pass-through
                mode.
            </p>
        </div>

        <!-- Tool execution params. -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="space-y-1.5">
                <Label for="timeout" class="text-xs text-ink-muted">
                    Timeout (ms)
                </Label>
                <Input
                    id="timeout"
                    v-model.number="timeout"
                    type="number"
                    min="1000"
                    max="300000"
                    step="1000"
                    class="h-9 border-medium bg-white/5 text-sm text-ink"
                />
                <p class="text-[11px] text-ink-subtle">
                    Maximum time to wait for tool execution.
                </p>
                <InputError :message="errors['config.tool_execution.timeout']" />
            </div>

            <div class="space-y-1.5">
                <Label for="retry-count" class="text-xs text-ink-muted">
                    Retry Count
                </Label>
                <Input
                    id="retry-count"
                    v-model.number="retryCount"
                    type="number"
                    min="0"
                    max="5"
                    class="h-9 border-medium bg-white/5 text-sm text-ink"
                />
                <p class="text-[11px] text-ink-subtle">
                    Number of retries on failure.
                </p>
                <InputError :message="errors['config.tool_execution.retry_count']" />
            </div>
        </div>
    </div>
</template>
