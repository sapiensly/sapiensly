<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import type { KnowledgeAgentConfig, KnowledgeBaseReference } from '@/types/agents';
import { Database } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    config: KnowledgeAgentConfig;
    knowledgeBaseIds: number[];
    knowledgeBases: KnowledgeBaseReference[];
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: KnowledgeAgentConfig];
    'update:knowledgeBaseIds': [ids: number[]];
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

const toggleKnowledgeBase = (id: number, checked: boolean) => {
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

const isSelected = (id: number) => props.knowledgeBaseIds.includes(id);
</script>

<template>
    <div class="space-y-6">
        <div class="space-y-4">
            <Label>Knowledge Bases</Label>
            <div v-if="knowledgeBases.length === 0" class="rounded-lg border border-dashed p-6 text-center">
                <Database class="mx-auto h-8 w-8 text-muted-foreground" />
                <p class="mt-2 text-sm text-muted-foreground">
                    No knowledge bases available. Create one to enable RAG capabilities.
                </p>
            </div>
            <div v-else class="space-y-2">
                <div
                    v-for="kb in knowledgeBases"
                    :key="kb.id"
                    class="flex items-center space-x-3 rounded-lg border p-3"
                >
                    <Checkbox
                        :id="`kb-${kb.id}`"
                        :checked="isSelected(kb.id)"
                        @update:checked="toggleKnowledgeBase(kb.id, $event)"
                    />
                    <Label :for="`kb-${kb.id}`" class="flex-1 cursor-pointer">
                        {{ kb.name }}
                    </Label>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                If no knowledge bases are selected, the agent will operate in pass-through mode.
            </p>
        </div>

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <Label for="top-k">Top K Results</Label>
                <Input
                    id="top-k"
                    v-model.number="topK"
                    type="number"
                    min="1"
                    max="20"
                    class="w-20"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                Number of relevant chunks to retrieve from the knowledge base.
            </p>
            <InputError :message="errors['config.rag_params.top_k']" />
        </div>

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <Label>Similarity Threshold</Label>
                <span class="text-sm text-muted-foreground">
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
            <p class="text-xs text-muted-foreground">
                Minimum similarity score for retrieved chunks. Higher values return more relevant results.
            </p>
            <InputError :message="errors['config.rag_params.similarity_threshold']" />
        </div>
    </div>
</template>
