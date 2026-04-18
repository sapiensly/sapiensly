<script setup lang="ts">
import * as FlowController from '@/actions/App/Http/Controllers/FlowController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import ActionAgentConfig from '@/components/standalone-agents/ActionAgentConfig.vue';
import KnowledgeAgentConfig from '@/components/standalone-agents/KnowledgeAgentConfig.vue';
import TriageAgentConfig from '@/components/standalone-agents/TriageAgentConfig.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import type {
    KnowledgeBaseReference,
    ModelOption,
    ToolReference,
} from '@/types/agents';
import axios from 'axios';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';

const { t } = useI18n();

interface Props {
    open: boolean;
    type: 'triage' | 'knowledge' | 'action';
    availableModels: ModelOption[];
    knowledgeBases?: KnowledgeBaseReference[];
    tools?: ToolReference[];
}

const props = withDefaults(defineProps<Props>(), {
    knowledgeBases: () => [],
    tools: () => [],
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    created: [agentId: string, agentName: string];
}>();

const typeLabels: Record<string, string> = {
    triage: 'Triage Agent',
    knowledge: 'Knowledge Agent',
    action: 'Action Agent',
};

const defaultPrompts: Record<string, string> = {
    triage:
        'You are a triage agent. Classify the user\'s intent, urgency and sentiment, then route to the right specialist.',
    knowledge:
        `You are an expert assistant that answers questions based on the provided documentation.

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
- Suggest what type of document might contain that information`,
    action:
        'You are an action agent. Execute the requested operations using the available tools. Confirm with the user before taking irreversible actions.',
};

const defaultConfigs: Record<string, Record<string, unknown>> = {
    triage: { temperature: 0.3 },
    knowledge: { rag_params: { top_k: 5, similarity_threshold: 0.7 } },
    action: { tool_execution: { timeout: 30000, retry_count: 2 } },
};

const name = ref('');
const description = ref('');
const model = ref('');
const promptTemplate = ref('');
const keywords = ref<string[]>([]);
const config = ref<Record<string, unknown>>({});
const knowledgeBaseIds = ref<string[]>([]);
const toolIds = ref<string[]>([]);
const errors = ref<Record<string, string>>({});
const processing = ref(false);

function reset() {
    name.value = '';
    description.value = '';
    model.value = props.availableModels[0]?.value ?? '';
    promptTemplate.value = defaultPrompts[props.type] ?? '';
    keywords.value = [];
    config.value = { ...(defaultConfigs[props.type] ?? {}) };
    knowledgeBaseIds.value = [];
    toolIds.value = [];
    errors.value = {};
}

watch(
    () => props.open,
    (open) => {
        if (open) reset();
    },
);

async function submit() {
    processing.value = true;
    errors.value = {};

    try {
        const response = await axios.post(FlowController.createAgentForLayer().url, {
            type: props.type,
            name: name.value,
            description: description.value,
            model: model.value,
            prompt_template: promptTemplate.value,
            keywords: keywords.value,
            config: config.value,
            knowledge_base_ids: knowledgeBaseIds.value,
            tool_ids: toolIds.value,
        });

        const agentId = response.data?.agent?.id ?? null;
        const agentName = response.data?.agent?.name ?? name.value;
        toast.success(t('flows.modal.agent_created'));
        if (agentId) emit('created', agentId, agentName);
        emit('update:open', false);
    } catch (e: unknown) {
        const axiosError = e as {
            response?: { status?: number; data?: { errors?: Record<string, string[]>; message?: string } };
        };

        if (axiosError.response?.status === 422 && axiosError.response.data?.errors) {
            const flat: Record<string, string> = {};
            Object.entries(axiosError.response.data.errors).forEach(([key, val]) => {
                flat[key] = Array.isArray(val) ? val[0] : (val as string);
            });
            errors.value = flat;
        } else {
            toast.error(axiosError.response?.data?.message ?? 'Failed to create agent.');
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ t('flows.modal.create_title', { type: typeLabels[type] }) }}</DialogTitle>
                <DialogDescription>
                    {{ t('flows.modal.create_description') }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-6" @submit.prevent="submit">
                <!-- Basic Info -->
                <div class="space-y-4">
                    <HeadingSmall
                        :title="t('agents.create.basic_info')"
                        :description="t('agents.create.basic_info_description')"
                    />

                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="agent-name">{{ t('agents.create.agent_name') }}</Label>
                            <Input
                                id="agent-name"
                                v-model="name"
                                required
                                :placeholder="t('agents.create.agent_name_placeholder')"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="agent-description">{{ t('agents.create.description_label') }}</Label>
                            <Input
                                id="agent-description"
                                v-model="description"
                                :placeholder="t('agents.create.description_placeholder')"
                            />
                            <InputError :message="errors.description" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="agent-keywords">{{ t('agents.create.keywords_label') }}</Label>
                            <KeywordsInput v-model="keywords" />
                            <p class="text-xs text-muted-foreground">
                                {{ t('agents.create.keywords_description') }}
                            </p>
                            <InputError :message="errors.keywords" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="agent-model">{{ t('agents.create.model') }}</Label>
                            <Select v-model="model">
                                <SelectTrigger id="agent-model">
                                    <SelectValue :placeholder="t('agents.create.select_model')" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="m in availableModels"
                                        :key="m.value"
                                        :value="m.value"
                                    >
                                        {{ m.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.model" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="agent-prompt">{{ t('agents.create.prompt_template') }}</Label>
                            <Textarea
                                id="agent-prompt"
                                v-model="promptTemplate"
                                :placeholder="t('agents.create.prompt_placeholder')"
                                rows="6"
                            />
                            <InputError :message="errors.prompt_template" />
                        </div>
                    </div>
                </div>

                <!-- Type-Specific Config -->
                <div class="space-y-4">
                    <HeadingSmall
                        :title="t('agents.create.config_title')"
                        :description="t('agents.create.config_description')"
                    />

                    <TriageAgentConfig
                        v-if="type === 'triage'"
                        v-model:config="config"
                        :errors="errors"
                    />

                    <KnowledgeAgentConfig
                        v-else-if="type === 'knowledge'"
                        v-model:config="config"
                        v-model:knowledge-base-ids="knowledgeBaseIds"
                        :knowledge-bases="knowledgeBases"
                        :errors="errors"
                    />

                    <ActionAgentConfig
                        v-else-if="type === 'action'"
                        v-model:config="config"
                        v-model:tool-ids="toolIds"
                        :tools="tools"
                        :errors="errors"
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        {{ t('common.cancel') }}
                    </Button>
                    <Button type="submit" :disabled="processing || !name || !model">
                        {{ processing ? t('flows.modal.creating') : t('flows.modal.create_agent') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
