<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { ToolReference } from '@/types/tools';
import { Code, Server, Wrench } from 'lucide-vue-next';
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
        <Label>{{ t('tools.config.group.select_tools') }}</Label>

        <div
            v-if="availableTools.length === 0"
            class="rounded-lg border border-dashed p-6 text-center"
        >
            <Wrench class="mx-auto h-8 w-8 text-muted-foreground" />
            <p class="mt-2 text-sm text-muted-foreground">
                {{ t('tools.config.group.no_tools') }}
            </p>
        </div>

        <div v-else class="space-y-2">
            <div
                v-for="tool in availableTools"
                :key="tool.id"
                class="flex items-center space-x-3 rounded-lg border p-3"
            >
                <Checkbox
                    :id="`tool-${tool.id}`"
                    :model-value="isSelected(tool.id)"
                    @update:model-value="toggleTool(tool.id, $event as boolean)"
                />
                <component
                    :is="toolIcon(tool.type)"
                    class="h-4 w-4 text-muted-foreground"
                />
                <Label :for="`tool-${tool.id}`" class="flex-1 cursor-pointer">
                    {{ tool.name }}
                </Label>
                <span class="text-xs text-muted-foreground capitalize">
                    {{ tool.type }}
                </span>
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            Group tools together for easier management and assignment to agents.
        </p>
        <InputError :message="errors['tool_ids']" />
    </div>
</template>
