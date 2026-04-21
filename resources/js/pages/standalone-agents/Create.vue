<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import ActionAgentConfig from '@/components/standalone-agents/ActionAgentConfig.vue';
import AgentTypeSelector from '@/components/standalone-agents/AgentTypeSelector.vue';
import KnowledgeAgentConfig from '@/components/standalone-agents/KnowledgeAgentConfig.vue';
import TriageAgentConfig from '@/components/standalone-agents/TriageAgentConfig.vue';
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
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type {
    AgentType,
    AgentTypeOption,
    KnowledgeBaseReference,
    ModelOption,
    RecommendedModels,
    ToolReference,
} from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Bot,
    Brain,
    Hash,
    Settings2,
    Sparkles,
    User,
    Zap,
} from 'lucide-vue-next';
import type { Component } from 'vue';
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

// Per-type visual metadata so the form cards read as "triage vs knowledge vs
// action" at a glance without the user re-reading the description each time.
const typeTintMap: Record<string, string> = {
    triage: 'var(--sp-accent-blue)',
    knowledge: 'var(--sp-spectrum-magenta)',
    action: 'var(--sp-warning)',
};

const typeIconMap: Record<string, Component> = {
    triage: Bot,
    knowledge: Brain,
    action: Zap,
};

const typeTint = computed(() => typeTintMap[currentType.value ?? ''] ?? 'var(--sp-accent-blue)');
const typeIcon = computed<Component>(() => typeIconMap[currentType.value ?? ''] ?? Bot);
</script>

<template>
    <Head :title="t('agents.create.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.agents')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="headingTitle"
                :description="t('agents.create.description')"
            />

            <!-- Type picker shown until a type is selected. -->
            <SettingsCard
                v-if="!currentType"
                :icon="Sparkles"
                :title="t('agents.create.select_type')"
                :description="t('agents.create.select_type_description')"
                tint="var(--sp-accent-cyan)"
            >
                <AgentTypeSelector
                    :agent-types="agentTypes"
                    @select="selectType"
                />
            </SettingsCard>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <!-- Basic info. -->
                <SettingsCard
                    :icon="typeIcon"
                    :title="t('agents.create.basic_info')"
                    :description="t('agents.create.basic_info_description')"
                    :tint="typeTint"
                >
                    <div class="space-y-1.5">
                        <Label for="name" class="text-xs text-ink-muted">
                            {{ t('agents.create.agent_name') }}
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            :placeholder="t('agents.create.agent_name_placeholder')"
                            class="h-9 border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description" class="text-xs text-ink-muted">
                            {{ t('agents.create.description_label') }}
                        </Label>
                        <Input
                            id="description"
                            v-model="form.description"
                            :placeholder="t('agents.create.description_placeholder')"
                            class="h-9 border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="keywords" class="text-xs text-ink-muted">
                            {{ t('agents.create.keywords_label') }}
                        </Label>
                        <KeywordsInput v-model="form.keywords" />
                        <p class="text-[11px] text-ink-subtle">
                            {{ t('agents.create.keywords_description') }}
                        </p>
                        <InputError :message="form.errors.keywords" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="model" class="text-xs text-ink-muted">
                            {{ t('agents.create.model') }}
                        </Label>
                        <Select v-model="form.model">
                            <SelectTrigger
                                id="model"
                                class="h-9 border-medium bg-white/5"
                            >
                                <SelectValue
                                    :placeholder="t('agents.create.select_model')"
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
                                        v-if="isRecommended(model.value)"
                                        class="ml-2 text-[10px] text-sp-success"
                                    >
                                        (Recommended)
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.model" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="prompt_template" class="text-xs text-ink-muted">
                            {{ t('agents.create.prompt_template') }}
                        </Label>
                        <Textarea
                            id="prompt_template"
                            v-model="form.prompt_template"
                            :placeholder="t('agents.create.prompt_placeholder')"
                            rows="6"
                            class="border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.prompt_template" />
                    </div>
                </SettingsCard>

                <!-- Type-specific configuration. -->
                <SettingsCard
                    :icon="Settings2"
                    :title="t('agents.create.config_title')"
                    :description="t('agents.create.config_description')"
                    :tint="typeTint"
                >
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
                </SettingsCard>

                <!-- Footer actions — pill pair + "change type" ghost left. -->
                <div class="flex items-center justify-between gap-2 pt-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
                        @click="currentType = null"
                    >
                        {{ t('common.change_type') }}
                    </button>
                    <div class="flex items-center gap-2">
                        <Link :href="AgentController.index().url">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                            >
                                {{ t('common.cancel') }}
                            </button>
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                        >
                            {{ t('agents.create.submit') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
