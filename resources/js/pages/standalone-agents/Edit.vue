<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import ActionAgentConfig from '@/components/standalone-agents/ActionAgentConfig.vue';
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
    Agent,
    AgentTypeOption,
    KnowledgeBaseReference,
    ModelOption,
    RecommendedModels,
    ToolReference,
} from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bot, Brain, Settings2, Zap } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface ActiveFlow {
    id: string;
    name: string;
}

interface Props {
    agent: Agent;
    agentTypes: AgentTypeOption[];
    availableModels: ModelOption[];
    recommendedModels: RecommendedModels;
    knowledgeBases: KnowledgeBaseReference[];
    tools: ToolReference[];
    activeFlow?: ActiveFlow | null;
}

const props = defineProps<Props>();

const form = useForm({
    name: props.agent.name,
    description: props.agent.description ?? '',
    keywords: props.agent.keywords ?? [],
    status: props.agent.status,
    prompt_template: props.agent.prompt_template ?? '',
    model: props.agent.model,
    config: props.agent.config ?? {},
    knowledge_base_ids:
        props.agent.knowledge_bases?.map((kb) => kb.id) ?? ([] as string[]),
    tool_ids: props.agent.tools?.map((t) => t.id) ?? ([] as string[]),
});

const statusOptions = computed(() => [
    { value: 'draft', label: t('common.draft') },
    { value: 'active', label: t('common.active') },
    { value: 'inactive', label: t('common.inactive') },
]);

const recommendedModelsList = computed(() => {
    return props.recommendedModels[props.agent.type] || [];
});

const isRecommended = (modelValue: string) => {
    return recommendedModelsList.value.includes(modelValue);
};

const submit = () => {
    form.put(AgentController.update({ agent: props.agent.id }).url);
};

// Per-type visual cues — same mapping as the create page so edit cards
// pick up the same tint + icon as the original agent's type.
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

const typeTint = computed(() => typeTintMap[props.agent.type] ?? 'var(--sp-accent-blue)');
const typeIcon = computed<Component>(() => typeIconMap[props.agent.type] ?? Bot);
</script>

<template>
    <Head :title="`${t('agents.edit.title')} ${agent.name}`" />

    <AppLayoutV2 :title="t('app_v2.nav.agents')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="`${t('agents.edit.title')} ${agent.name}`"
                :description="t('agents.edit.description')"
            />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- Basic info. -->
                <SettingsCard
                    :icon="typeIcon"
                    :title="t('agents.edit.basic_info')"
                    :description="t('agents.edit.basic_info_description')"
                    :tint="typeTint"
                >
                    <div class="space-y-1.5">
                        <Label for="name" class="text-xs text-ink-muted">
                            {{ t('agents.edit.agent_name') }}
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            :placeholder="t('agents.edit.agent_name_placeholder')"
                            class="h-9 border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description" class="text-xs text-ink-muted">
                            {{ t('agents.edit.description_label') }}
                        </Label>
                        <Input
                            id="description"
                            v-model="form.description"
                            :placeholder="t('agents.edit.description_placeholder')"
                            class="h-9 border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="keywords" class="text-xs text-ink-muted">
                            {{ t('agents.edit.keywords_label') }}
                        </Label>
                        <KeywordsInput v-model="form.keywords" />
                        <p class="text-[11px] text-ink-subtle">
                            {{ t('agents.edit.keywords_description') }}
                        </p>
                        <InputError :message="form.errors.keywords" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="status" class="text-xs text-ink-muted">
                            {{ t('common.status') }}
                        </Label>
                        <Select v-model="form.status">
                            <SelectTrigger
                                id="status"
                                class="h-9 border-medium bg-white/5"
                            >
                                <SelectValue
                                    :placeholder="t('common.select_status')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in statusOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.status" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="model" class="text-xs text-ink-muted">
                            {{ t('agents.edit.model') }}
                        </Label>
                        <Select v-model="form.model">
                            <SelectTrigger
                                id="model"
                                class="h-9 border-medium bg-white/5"
                            >
                                <SelectValue
                                    :placeholder="t('agents.edit.select_model')"
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
                                        {{ t('agents.edit.recommended') }}
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.model" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="prompt_template" class="text-xs text-ink-muted">
                            {{ t('agents.edit.prompt_template') }}
                        </Label>
                        <Textarea
                            id="prompt_template"
                            v-model="form.prompt_template"
                            :placeholder="t('agents.edit.prompt_placeholder')"
                            rows="6"
                            class="border-medium bg-white/5 text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <InputError :message="form.errors.prompt_template" />
                    </div>
                </SettingsCard>

                <!-- Type-specific configuration. -->
                <SettingsCard
                    :icon="Settings2"
                    :title="t('agents.edit.config_title')"
                    :description="t('agents.edit.config_description')"
                    :tint="typeTint"
                >
                    <TriageAgentConfig
                        v-if="agent.type === 'triage'"
                        v-model:config="form.config"
                        :errors="form.errors"
                        :agent-id="agent.id"
                        :has-flow="!!activeFlow"
                        :flow-url="activeFlow ? `/agents/${agent.id}/flows/${activeFlow.id}/edit` : null"
                    />

                    <KnowledgeAgentConfig
                        v-else-if="agent.type === 'knowledge'"
                        v-model:config="form.config"
                        v-model:knowledge-base-ids="form.knowledge_base_ids"
                        :knowledge-bases="knowledgeBases"
                        :errors="form.errors"
                    />

                    <ActionAgentConfig
                        v-else-if="agent.type === 'action'"
                        v-model:config="form.config"
                        v-model:tool-ids="form.tool_ids"
                        :tools="tools"
                        :errors="form.errors"
                    />
                </SettingsCard>

                <!-- Footer actions. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link :href="AgentController.show({ agent: agent.id }).url">
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
                        {{ t('common.save_changes') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
