<script setup lang="ts">
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
import type {
    AgentHandoffNodeConfig,
    ConditionNodeConfig,
    ConditionRule,
    EndNodeConfig,
    FlowNodeType,
    MenuNodeConfig,
    MenuOption,
    MessageNodeConfig,
    StartNodeConfig,
} from '@/types/flows';
import type { Node } from '@vue-flow/core';
import { Plus, Trash2, X } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    node: Node;
}>();

const emit = defineEmits<{
    close: [];
    updateData: [id: string, data: Record<string, unknown>];
    removeNode: [id: string];
}>();

const nodeType = computed(() => props.node.type as FlowNodeType);

const startData = computed(() => props.node.data as StartNodeConfig);
const menuData = computed(() => props.node.data as MenuNodeConfig);
const conditionData = computed(() => props.node.data as ConditionNodeConfig);
const agentData = computed(() => props.node.data as AgentHandoffNodeConfig);
const messageData = computed(() => props.node.data as MessageNodeConfig);
const endData = computed(() => props.node.data as EndNodeConfig);

const update = (data: Record<string, unknown>) => {
    emit('updateData', props.node.id, data);
};

const addMenuOption = () => {
    const options: MenuOption[] = [...(menuData.value.options || [])];
    options.push({
        id: `option_${options.length}_${Date.now()}`,
        label: `Option ${options.length + 1}`,
    });
    update({ options });
};

const removeMenuOption = (index: number) => {
    const options = [...(menuData.value.options || [])];
    options.splice(index, 1);
    update({ options });
};

const updateMenuOption = (index: number, field: string, value: string) => {
    const options = [...(menuData.value.options || [])];
    options[index] = { ...options[index], [field]: value };
    update({ options });
};

const addConditionRule = () => {
    const rules: ConditionRule[] = [...(conditionData.value.rules || [])];
    rules.push({
        id: `match_${rules.length}_${Date.now()}`,
        pattern: '',
        label: '',
    });
    update({ rules });
};

const removeConditionRule = (index: number) => {
    const rules = [...(conditionData.value.rules || [])];
    rules.splice(index, 1);
    update({ rules });
};

const updateConditionRule = (index: number, field: string, value: string) => {
    const rules = [...(conditionData.value.rules || [])];
    rules[index] = { ...rules[index], [field]: value };
    update({ rules });
};
</script>

<template>
    <div class="flex h-full w-[300px] flex-col border-l bg-background">
        <div class="flex items-center justify-between border-b px-3 py-2">
            <h3
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('flows.panel.title') }}
            </h3>
            <Button
                variant="ghost"
                size="icon"
                class="h-6 w-6"
                @click="emit('close')"
            >
                <X class="h-3.5 w-3.5" />
            </Button>
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto p-3">
            <!-- Start Node -->
            <template v-if="nodeType === 'start'">
                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.trigger') }}</Label>
                    <Select
                        :model-value="startData.trigger"
                        @update:model-value="update({ trigger: $event })"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="conversation_start">
                                {{
                                    t('flows.panel.trigger_conversation_start')
                                }}
                            </SelectItem>
                            <SelectItem value="keyword">
                                {{ t('flows.panel.trigger_keyword') }}
                            </SelectItem>
                            <SelectItem value="always">
                                {{ t('flows.panel.trigger_always') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="startData.trigger === 'keyword'" class="grid gap-2">
                    <Label>{{ t('flows.panel.keywords') }}</Label>
                    <Input
                        :model-value="(startData.keywords || []).join(', ')"
                        :placeholder="t('flows.panel.keywords_placeholder')"
                        @update:model-value="
                            update({
                                keywords: ($event as string)
                                    .split(',')
                                    .map((k: string) => k.trim())
                                    .filter(Boolean),
                            })
                        "
                    />
                </div>
            </template>

            <!-- Menu Node -->
            <template v-if="nodeType === 'menu'">
                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.menu_message') }}</Label>
                    <Textarea
                        :model-value="menuData.message"
                        :placeholder="t('flows.panel.menu_message_placeholder')"
                        rows="3"
                        @update:model-value="update({ message: $event })"
                    />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>{{ t('flows.panel.options') }}</Label>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-6 px-2 text-xs"
                            @click="addMenuOption"
                        >
                            <Plus class="mr-1 h-3 w-3" />
                            {{ t('flows.panel.add_option') }}
                        </Button>
                    </div>

                    <div
                        v-for="(option, index) in menuData.options"
                        :key="option.id"
                        class="flex items-center gap-2"
                    >
                        <Input
                            :model-value="option.label"
                            :placeholder="`${t('flows.panel.option')} ${index + 1}`"
                            class="h-8 text-xs"
                            @update:model-value="
                                updateMenuOption(
                                    index,
                                    'label',
                                    $event as string,
                                )
                            "
                        />
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 shrink-0"
                            :disabled="menuData.options.length <= 1"
                            @click="removeMenuOption(index)"
                        >
                            <Trash2 class="h-3 w-3" />
                        </Button>
                    </div>
                </div>
            </template>

            <!-- Condition Node -->
            <template v-if="nodeType === 'condition'">
                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.match_type') }}</Label>
                    <Select
                        :model-value="conditionData.match_type"
                        @update:model-value="update({ match_type: $event })"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="exact">{{
                                t('flows.panel.match_exact')
                            }}</SelectItem>
                            <SelectItem value="contains">{{
                                t('flows.panel.match_contains')
                            }}</SelectItem>
                            <SelectItem value="regex">{{
                                t('flows.panel.match_regex')
                            }}</SelectItem>
                            <SelectItem value="llm_classification">{{
                                t('flows.panel.match_llm')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>{{ t('flows.panel.rules') }}</Label>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-6 px-2 text-xs"
                            @click="addConditionRule"
                        >
                            <Plus class="mr-1 h-3 w-3" />
                            {{ t('flows.panel.add_rule') }}
                        </Button>
                    </div>

                    <div
                        v-for="(rule, index) in conditionData.rules"
                        :key="rule.id"
                        class="space-y-1"
                    >
                        <div class="flex items-center gap-2">
                            <Input
                                :model-value="rule.label"
                                :placeholder="t('flows.panel.rule_label')"
                                class="h-8 text-xs"
                                @update:model-value="
                                    updateConditionRule(
                                        index,
                                        'label',
                                        $event as string,
                                    )
                                "
                            />
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 shrink-0"
                                :disabled="conditionData.rules.length <= 1"
                                @click="removeConditionRule(index)"
                            >
                                <Trash2 class="h-3 w-3" />
                            </Button>
                        </div>
                        <Input
                            :model-value="rule.pattern"
                            :placeholder="t('flows.panel.rule_pattern')"
                            class="h-8 text-xs"
                            @update:model-value="
                                updateConditionRule(
                                    index,
                                    'pattern',
                                    $event as string,
                                )
                            "
                        />
                    </div>
                </div>
            </template>

            <!-- Agent Handoff Node -->
            <template v-if="nodeType === 'agent_handoff'">
                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.target_agent') }}</Label>
                    <Select
                        :model-value="agentData.target_agent"
                        @update:model-value="update({ target_agent: $event })"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="knowledge">{{
                                t('flows.nodes.agent_knowledge')
                            }}</SelectItem>
                            <SelectItem value="action">{{
                                t('flows.nodes.agent_action')
                            }}</SelectItem>
                            <SelectItem value="triage_llm">{{
                                t('flows.nodes.agent_triage_llm')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.context') }}</Label>
                    <Textarea
                        :model-value="agentData.context || ''"
                        :placeholder="t('flows.panel.context_placeholder')"
                        rows="3"
                        @update:model-value="update({ context: $event })"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.handoff_message') }}</Label>
                    <Textarea
                        :model-value="agentData.message || ''"
                        :placeholder="
                            t('flows.panel.handoff_message_placeholder')
                        "
                        rows="2"
                        @update:model-value="update({ message: $event })"
                    />
                </div>
            </template>

            <!-- Message Node -->
            <template v-if="nodeType === 'message'">
                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.message_text') }}</Label>
                    <Textarea
                        :model-value="messageData.message"
                        :placeholder="t('flows.panel.message_placeholder')"
                        rows="4"
                        @update:model-value="update({ message: $event })"
                    />
                </div>
            </template>

            <!-- End Node -->
            <template v-if="nodeType === 'end'">
                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.end_action') }}</Label>
                    <Select
                        :model-value="endData.action"
                        @update:model-value="update({ action: $event })"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="resume_conversation">
                                {{ t('flows.panel.end_resume') }}
                            </SelectItem>
                            <SelectItem value="close_conversation">
                                {{ t('flows.panel.end_close') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.end_message') }}</Label>
                    <Textarea
                        :model-value="endData.message || ''"
                        :placeholder="t('flows.panel.end_message_placeholder')"
                        rows="2"
                        @update:model-value="update({ message: $event })"
                    />
                </div>
            </template>
        </div>

        <div class="border-t p-3">
            <Button
                variant="destructive"
                size="sm"
                class="w-full"
                :disabled="nodeType === 'start'"
                @click="emit('removeNode', node.id)"
            >
                <Trash2 class="mr-1.5 h-3.5 w-3.5" />
                {{ t('flows.panel.delete_node') }}
            </Button>
        </div>
    </div>
</template>
