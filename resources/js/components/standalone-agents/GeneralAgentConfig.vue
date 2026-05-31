<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import type {
    GeneralAgentConfig,
    KnowledgeBaseReference,
    ToolReference,
} from '@/types/agents';
import { Database, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: GeneralAgentConfig;
    knowledgeBaseIds: string[];
    knowledgeBases: KnowledgeBaseReference[];
    toolIds: string[];
    tools: ToolReference[];
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: GeneralAgentConfig];
    'update:knowledgeBaseIds': [ids: string[]];
    'update:toolIds': [ids: string[]];
}>();

// --- Knowledge base controls ---
const topK = computed({
    get: () => props.config.rag_params?.top_k ?? 5,
    set: (value: number) =>
        emit('update:config', {
            ...props.config,
            rag_params: { ...props.config.rag_params, top_k: value },
        }),
});

const similarityThreshold = computed({
    get: () => [props.config.rag_params?.similarity_threshold ?? 0.7],
    set: (value: number[]) =>
        emit('update:config', {
            ...props.config,
            rag_params: {
                ...props.config.rag_params,
                similarity_threshold: value[0],
            },
        }),
});

const toggleKnowledgeBase = (id: string, checked: boolean) => {
    const ids = [...props.knowledgeBaseIds];
    if (checked) {
        if (!ids.includes(id)) ids.push(id);
    } else {
        const i = ids.indexOf(id);
        if (i > -1) ids.splice(i, 1);
    }
    emit('update:knowledgeBaseIds', ids);
};

const isKbSelected = (id: string) => props.knowledgeBaseIds.includes(id);

// --- Tool controls ---
const timeout = computed({
    get: () => props.config.tool_execution?.timeout ?? 30000,
    set: (value: number) =>
        emit('update:config', {
            ...props.config,
            tool_execution: { ...props.config.tool_execution, timeout: value },
        }),
});

const retryCount = computed({
    get: () => props.config.tool_execution?.retry_count ?? 2,
    set: (value: number) =>
        emit('update:config', {
            ...props.config,
            tool_execution: {
                ...props.config.tool_execution,
                retry_count: value,
            },
        }),
});

const toggleTool = (id: string, checked: boolean) => {
    const ids = [...props.toolIds];
    if (checked) {
        if (!ids.includes(id)) ids.push(id);
    } else {
        const i = ids.indexOf(id);
        if (i > -1) ids.splice(i, 1);
    }
    emit('update:toolIds', ids);
};

const isToolSelected = (id: string) => props.toolIds.includes(id);

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
    <div class="space-y-6">
        <p class="text-xs text-ink-subtle">
            {{ t('agents.config.general.intro') }}
        </p>

        <!-- Knowledge bases -->
        <div class="space-y-2">
            <Label class="flex items-center gap-1.5 text-xs text-ink-muted">
                <Database class="size-3.5" />
                {{ t('agents.config.knowledge.knowledge_bases') }}
            </Label>
            <div
                v-if="knowledgeBases.length === 0"
                class="rounded-xs border border-dashed border-soft bg-navy/40 p-6 text-center"
            >
                <div
                    class="mx-auto flex size-9 items-center justify-center rounded-xs bg-surface text-ink-muted"
                >
                    <Database class="size-4" />
                </div>
                <p class="mt-3 text-xs text-ink-muted">
                    {{ t('agents.config.knowledge.no_kbs') }}
                </p>
            </div>
            <div v-else class="space-y-1.5">
                <label
                    v-for="kb in knowledgeBases"
                    :key="kb.id"
                    :for="`gen-kb-${kb.id}`"
                    class="flex cursor-pointer items-center gap-3 rounded-xs border border-soft bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                >
                    <Checkbox
                        :id="`gen-kb-${kb.id}`"
                        :model-value="isKbSelected(kb.id)"
                        @update:model-value="
                            toggleKnowledgeBase(kb.id, $event as boolean)
                        "
                    />
                    <span class="flex-1 text-sm text-ink">{{ kb.name }}</span>
                </label>
            </div>
        </div>

        <!-- RAG params (only meaningful with KBs attached) -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="space-y-1.5">
                <Label for="gen-top-k" class="text-xs text-ink-muted">
                    {{ t('agents.config.knowledge.top_k') }}
                </Label>
                <Input
                    id="gen-top-k"
                    v-model.number="topK"
                    type="number"
                    min="1"
                    max="20"
                    class="h-9 border-medium bg-surface text-sm text-ink"
                />
                <InputError :message="errors['config.rag_params.top_k']" />
            </div>
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <Label class="text-xs text-ink-muted">{{
                        t('agents.config.general.similarity_threshold')
                    }}</Label>
                    <span class="font-mono text-xs text-ink">{{
                        similarityThreshold[0].toFixed(2)
                    }}</span>
                </div>
                <Slider
                    v-model="similarityThreshold"
                    :min="0"
                    :max="1"
                    :step="0.01"
                    class="w-full pt-2"
                />
                <InputError
                    :message="errors['config.rag_params.similarity_threshold']"
                />
            </div>
        </div>

        <!-- Tools -->
        <div class="space-y-2 border-t border-soft pt-5">
            <Label class="flex items-center gap-1.5 text-xs text-ink-muted">
                <Wrench class="size-3.5" />
                {{ t('agents.config.action.tools') }}
            </Label>
            <div
                v-if="tools.length === 0"
                class="rounded-xs border border-dashed border-soft bg-navy/40 p-6 text-center"
            >
                <div
                    class="mx-auto flex size-9 items-center justify-center rounded-xs bg-surface text-ink-muted"
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
                    :for="`gen-tool-${tool.id}`"
                    class="flex cursor-pointer items-center gap-3 rounded-xs border border-soft bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                >
                    <Checkbox
                        :id="`gen-tool-${tool.id}`"
                        :model-value="isToolSelected(tool.id)"
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
        </div>

        <!-- Tool execution params -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="space-y-1.5">
                <Label for="gen-timeout" class="text-xs text-ink-muted">{{
                    t('agents.config.general.timeout')
                }}</Label>
                <Input
                    id="gen-timeout"
                    v-model.number="timeout"
                    type="number"
                    min="1000"
                    max="300000"
                    step="1000"
                    class="h-9 border-medium bg-surface text-sm text-ink"
                />
                <InputError
                    :message="errors['config.tool_execution.timeout']"
                />
            </div>
            <div class="space-y-1.5">
                <Label for="gen-retry" class="text-xs text-ink-muted">{{
                    t('agents.config.general.retry_count')
                }}</Label>
                <Input
                    id="gen-retry"
                    v-model.number="retryCount"
                    type="number"
                    min="0"
                    max="5"
                    class="h-9 border-medium bg-surface text-sm text-ink"
                />
                <InputError
                    :message="errors['config.tool_execution.retry_count']"
                />
            </div>
        </div>
    </div>
</template>
