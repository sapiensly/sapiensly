<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import type {
    KnowledgeAgentConfig,
    KnowledgeBaseReference,
} from '@/types/agents';
import { Database } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: KnowledgeAgentConfig;
    knowledgeBaseIds: string[];
    knowledgeBases: KnowledgeBaseReference[];
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: KnowledgeAgentConfig];
    'update:knowledgeBaseIds': [ids: string[]];
}>();

const topK = computed({
    get: () => props.config.rag_params?.top_k ?? 5,
    set: (value: number) => {
        emit('update:config', {
            ...props.config,
            rag_params: {
                ...props.config.rag_params,
                top_k: value,
            },
        });
    },
});

const similarityThreshold = computed({
    get: () => [props.config.rag_params?.similarity_threshold ?? 0.7],
    set: (value: number[]) => {
        emit('update:config', {
            ...props.config,
            rag_params: {
                ...props.config.rag_params,
                similarity_threshold: value[0],
            },
        });
    },
});

const toggleKnowledgeBase = (id: string, checked: boolean) => {
    const currentIds = [...props.knowledgeBaseIds];
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
    emit('update:knowledgeBaseIds', currentIds);
};

const isSelected = (id: string) => props.knowledgeBaseIds.includes(id);
</script>

<template>
    <div class="space-y-5">
        <!-- Knowledge base picker. -->
        <div class="space-y-2">
            <Label class="text-xs text-ink-muted">
                {{ t('agents.config.knowledge.knowledge_bases') }}
            </Label>
            <div
                v-if="knowledgeBases.length === 0"
                class="rounded-xs border border-dashed border-soft bg-navy/40 p-6 text-center"
            >
                <div
                    class="mx-auto flex size-9 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
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
                    :for="`kb-${kb.id}`"
                    class="flex cursor-pointer items-center gap-3 rounded-xs border border-soft bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                >
                    <Checkbox
                        :id="`kb-${kb.id}`"
                        :model-value="isSelected(kb.id)"
                        @update:model-value="
                            toggleKnowledgeBase(kb.id, $event as boolean)
                        "
                    />
                    <span class="flex-1 text-sm text-ink">{{ kb.name }}</span>
                </label>
            </div>
            <p class="text-[11px] text-ink-subtle">
                {{ t('agents.config.knowledge.passthrough_note') }}
            </p>
        </div>

        <!-- Top K. -->
        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <Label for="top-k" class="text-xs text-ink-muted">
                    {{ t('agents.config.knowledge.top_k') }}
                </Label>
                <Input
                    id="top-k"
                    v-model.number="topK"
                    type="number"
                    min="1"
                    max="20"
                    class="h-9 w-20 border-medium bg-white/5 text-sm text-ink"
                />
            </div>
            <p class="text-[11px] text-ink-subtle">
                Number of relevant chunks to retrieve from the knowledge base.
            </p>
            <InputError :message="errors['config.rag_params.top_k']" />
        </div>

        <!-- Similarity threshold slider. -->
        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <Label class="text-xs text-ink-muted">Similarity Threshold</Label>
                <span class="font-mono text-xs text-ink">
                    {{ similarityThreshold[0].toFixed(2) }}
                </span>
            </div>
            <Slider
                v-model="similarityThreshold"
                :min="0"
                :max="1"
                :step="0.01"
                class="w-full"
            />
            <p class="text-[11px] text-ink-subtle">
                Minimum similarity score for retrieved chunks. Higher values
                return more relevant results.
            </p>
            <InputError :message="errors['config.rag_params.similarity_threshold']" />
        </div>
    </div>
</template>
