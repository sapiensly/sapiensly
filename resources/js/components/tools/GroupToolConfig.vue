<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { ToolReference } from '@/types/tools';
import { Code, Info, Server, Wrench } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    toolIds: string[];
    availableTools: ToolReference[];
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:toolIds': [ids: string[]];
}>();

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

const toolIcon = (type: string) => {
    switch (type) {
        case 'function':
            return Code;
        case 'mcp':
            return Server;
        default:
            return Wrench;
    }
};
</script>

<template>
    <div class="space-y-4">
        <p
            class="flex items-start gap-2 rounded-xs border border-soft bg-white/[0.02] p-2.5 text-[11px] leading-snug text-ink-muted"
        >
            <Info class="mt-px size-3.5 shrink-0 text-ink-subtle" />
            <span>{{ t('tools.config.group.guidance') }}</span>
        </p>

        <Label>{{ t('tools.config.group.select_tools') }}</Label>

        <div
            v-if="availableTools.length === 0"
            class="rounded-xs border border-dashed border-soft p-6 text-center"
        >
            <Wrench class="mx-auto h-8 w-8 text-ink-subtle" />
            <p class="mt-2 text-sm text-ink-muted">
                {{ t('tools.config.group.no_tools') }}
            </p>
        </div>

        <div v-else class="space-y-2">
            <div
                v-for="tool in availableTools"
                :key="tool.id"
                class="flex items-center space-x-3 rounded-xs border border-soft p-3"
            >
                <Checkbox
                    :id="`tool-${tool.id}`"
                    :model-value="isSelected(tool.id)"
                    @update:model-value="toggleTool(tool.id, $event as boolean)"
                />
                <div class="flex size-7 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue">
                    <component :is="toolIcon(tool.type)" class="size-3.5" />
                </div>
                <Label :for="`tool-${tool.id}`" class="flex-1 cursor-pointer">
                    {{ tool.name }}
                </Label>
                <span class="text-xs text-ink-muted capitalize">
                    {{ tool.type }}
                </span>
            </div>
        </div>

        <p class="text-xs text-ink-muted">
            Group tools together for easier management and assignment to agents.
        </p>
        <InputError :message="errors['tool_ids']" />
    </div>
</template>
