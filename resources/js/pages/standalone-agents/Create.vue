<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import ActionAgentConfig from '@/components/standalone-agents/ActionAgentConfig.vue';
import AgentTypeSelector from '@/components/standalone-agents/AgentTypeSelector.vue';
import KnowledgeAgentConfig from '@/components/standalone-agents/KnowledgeAgentConfig.vue';
import TriageAgentConfig from '@/components/standalone-agents/TriageAgentConfig.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type {
    AgentType,
    AgentTypeOption,
    KnowledgeBaseReference,
    ModelOption,
    RecommendedModels,
    ToolReference,
} from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    selectedType: AgentType | null;
    agentTypes: AgentTypeOption[];
    availableModels: ModelOption[];
    recommendedModels: RecommendedModels;
    knowledgeBases: KnowledgeBaseReference[];
    tools: ToolReference[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('agents.index.heading'), href: AgentController.index().url },
    { title: t('common.create'), href: '#' },
]);

const currentType = ref<AgentType | null>(props.selectedType);

const currentTypeLabel = computed(() => {
    if (!currentType.value) return '';
    const found = props.agentTypes.find((t) => t.value === currentType.value);
    return found?.label ?? '';
});

const headingTitle = computed(() => {
    if (!currentTypeLabel.value) return t('agents.create.heading');
    return `${t('agents.create.heading')}: ${currentTypeLabel.value}`;
});

const form = useForm({
    type: props.selectedType ?? 'triage',
    name: '',
    description: '',
    keywords: [] as string[],
    prompt_template: '',
    model: '',
    config: {} as Record<string, unknown>,
    knowledge_base_ids: [] as string[],
    tool_ids: [] as string[],
});

const selectType = (type: AgentType) => {
    currentType.value = type;
    form.type = type;

    const recommended = props.recommendedModels[type];
    if (recommended && recommended.length > 0) {
        form.model = recommended[0];
    }

    form.config = getDefaultConfig(type);
    form.prompt_template = getDefaultPromptTemplate(type);
};

const getDefaultConfig = (type: AgentType): Record<string, unknown> => {
    switch (type) {
        case 'triage':
            return { temperature: 0.3 };
        case 'knowledge':
            return {
                rag_params: {
                    top_k: 5,
                    similarity_threshold: 0.7,
                },
            };
        case 'action':
            return {
                tool_execution: {
                    timeout: 30000,
                    retry_count: 2,
                },
            };
        default:
            return {};
    }
};

const getDefaultPromptTemplate = (type: AgentType): string => {
    switch (type) {
        case 'knowledge':
            return `You are an expert assistant that answers questions based on the provided documentation.

## Instructions

1. **Use the context**: Base your answers on the information from the provided context. If the context contains the answer, use it.

2. **Cite sources**: When using information from the context, mention the source (e.g., "According to [document name]...").

3. **Be honest**: If the context doesn't contain enough information to answer, say so clearly. Don't make up information.

4. **Be concise**: Answer clearly and directly. Use lists or steps when appropriate.

5. **Language**: Respond in the same language as the user.

## When information is not available

If the question cannot be answered with the available context:
- Indicate that you couldn't find that information in the documentation
- If you have relevant general knowledge, you may share it while clarifying it doesn't come from the documentation
- Suggest what type of document might contain that information`;
        default:
            return '';
    }
};

const recommendedModelsList = computed(() => {
    if (!currentType.value) return [];
    return props.recommendedModels[currentType.value] || [];
});

const isRecommended = (modelValue: string) => {
    return recommendedModelsList.value.includes(modelValue);
};

const submit = () => {
    form.post(AgentController.store().url);
};

watch(currentType, (type) => {
    if (type) {
        selectType(type);
    }
});

if (props.selectedType) {
    selectType(props.selectedType);
}
</script>

<template>
    <Head :title="t('agents.create.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <Heading
                    :title="headingTitle"
                    :description="t('agents.create.description')"
                />

                <div v-if="!currentType" class="mt-8">
                    <HeadingSmall
                        :title="t('agents.create.select_type')"
                        :description="
                            t('agents.create.select_type_description')
                        "
                    />
                    <AgentTypeSelector
                        :agent-types="agentTypes"
                        class="mt-4"
                        @select="selectType"
                    />
                </div>

                <form v-else class="mt-8 space-y-8" @submit.prevent="submit">
                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('agents.create.basic_info')"
                            :description="
                                t('agents.create.basic_info_description')
                            "
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">{{
                                    t('agents.create.agent_name')
                                }}</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    :placeholder="
                                        t(
                                            'agents.create.agent_name_placeholder',
                                        )
                                    "
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">{{
                                    t('agents.create.description_label')
                                }}</Label>
                                <Input
                                    id="description"
                                    v-model="form.description"
                                    :placeholder="
                                        t(
                                            'agents.create.description_placeholder',
                                        )
                                    "
                                />
                                <InputError
                                    :message="form.errors.description"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="keywords">{{
                                    t('agents.create.keywords_label')
                                }}</Label>
                                <KeywordsInput v-model="form.keywords" />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t('agents.create.keywords_description')
                                    }}
                                </p>
                                <InputError :message="form.errors.keywords" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="model">{{
                                    t('agents.create.model')
                                }}</Label>
                                <Select v-model="form.model">
                                    <SelectTrigger id="model">
                                        <SelectValue
                                            :placeholder="
                                                t('agents.create.select_model')
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="model in availableModels"
                                            :key="model.value"
                                            :value="model.value"
                                        >
                                            {{ model.label }}
                                            <span
                                                v-if="
                                                    isRecommended(model.value)
                                                "
                                                class="ml-2 text-xs text-green-600"
                                            >
                                                (Recommended)
                                            </span>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.model" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="prompt_template">{{
                                    t('agents.create.prompt_template')
                                }}</Label>
                                <Textarea
                                    id="prompt_template"
                                    v-model="form.prompt_template"
                                    :placeholder="
                                        t('agents.create.prompt_placeholder')
                                    "
                                    rows="6"
                                />
                                <InputError
                                    :message="form.errors.prompt_template"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('agents.create.config_title')"
                            :description="t('agents.create.config_description')"
                        />

                        <TriageAgentConfig
                            v-if="currentType === 'triage'"
                            v-model:config="form.config"
                            :errors="form.errors"
                        />

                        <KnowledgeAgentConfig
                            v-else-if="currentType === 'knowledge'"
                            v-model:config="form.config"
                            v-model:knowledge-base-ids="form.knowledge_base_ids"
                            :knowledge-bases="knowledgeBases"
                            :errors="form.errors"
                        />

                        <ActionAgentConfig
                            v-else-if="currentType === 'action'"
                            v-model:config="form.config"
                            v-model:tool-ids="form.tool_ids"
                            :tools="tools"
                            :errors="form.errors"
                        />
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button
                            variant="outline"
                            type="button"
                            @click="currentType = null"
                        >
                            {{ t('common.change_type') }}
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="AgentController.index().url">
                                {{ t('common.cancel') }}
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ t('agents.create.submit') }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
