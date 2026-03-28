<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import ActionAgentConfig from '@/components/standalone-agents/ActionAgentConfig.vue';
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
    Agent,
    AgentTypeOption,
    KnowledgeBaseReference,
    ModelOption,
    RecommendedModels,
    ToolReference,
} from '@/types/agents';
import { Head, Link, useForm } from '@inertiajs/vue3';
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

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('agents.index.heading'), href: AgentController.index().url },
    {
        title: props.agent.name,
        href: AgentController.show({ agent: props.agent.id }).url,
    },
    { title: t('common.edit'), href: '#' },
]);

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
</script>

<template>
    <Head :title="`${t('agents.edit.title')} ${agent.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <Heading
                    :title="`${t('agents.edit.title')} ${agent.name}`"
                    :description="t('agents.edit.description')"
                />

                <form class="mt-8 space-y-8" @submit.prevent="submit">
                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('agents.edit.basic_info')"
                            :description="
                                t('agents.edit.basic_info_description')
                            "
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">{{
                                    t('agents.edit.agent_name')
                                }}</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    :placeholder="
                                        t('agents.edit.agent_name_placeholder')
                                    "
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">{{
                                    t('agents.edit.description_label')
                                }}</Label>
                                <Input
                                    id="description"
                                    v-model="form.description"
                                    :placeholder="
                                        t('agents.edit.description_placeholder')
                                    "
                                />
                                <InputError
                                    :message="form.errors.description"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="keywords">{{
                                    t('agents.edit.keywords_label')
                                }}</Label>
                                <KeywordsInput v-model="form.keywords" />
                                <p class="text-xs text-muted-foreground">
                                    {{ t('agents.edit.keywords_description') }}
                                </p>
                                <InputError :message="form.errors.keywords" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="status">{{
                                    t('common.status')
                                }}</Label>
                                <Select v-model="form.status">
                                    <SelectTrigger id="status">
                                        <SelectValue
                                            :placeholder="
                                                t('common.select_status')
                                            "
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

                            <div class="grid gap-2">
                                <Label for="model">{{
                                    t('agents.edit.model')
                                }}</Label>
                                <Select v-model="form.model">
                                    <SelectTrigger id="model">
                                        <SelectValue
                                            :placeholder="
                                                t('agents.edit.select_model')
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
                                                {{
                                                    t('agents.edit.recommended')
                                                }}
                                            </span>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.model" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="prompt_template">{{
                                    t('agents.edit.prompt_template')
                                }}</Label>
                                <Textarea
                                    id="prompt_template"
                                    v-model="form.prompt_template"
                                    :placeholder="
                                        t('agents.edit.prompt_placeholder')
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
                            :title="t('agents.edit.config_title')"
                            :description="t('agents.edit.config_description')"
                        />

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
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    AgentController.show({ agent: agent.id })
                                        .url
                                "
                            >
                                {{ t('common.cancel') }}
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ t('common.save_changes') }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
