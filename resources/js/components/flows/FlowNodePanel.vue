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
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import type {
    AgentHandoffNodeConfig,
    AgentLayerConfig,
    ConditionNodeConfig,
    ConditionRule,
    ConnectorNodeConfig,
    EndNodeConfig,
    FlowNodeType,
    MenuNodeConfig,
    MenuOption,
    MessageNodeConfig,
    StartNodeConfig,
} from '@/types/flows';
import AgentCreateModal from '@/components/flows/AgentCreateModal.vue';
import type { Node } from '@vue-flow/core';
import { AlertTriangle, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface AvailableModel {
    value: string;
    label: string;
    provider: string;
}

interface AgentRef {
    id: string;
    name: string;
    model: string;
}

interface AvailableAgents {
    triage: AgentRef[];
    knowledge: AgentRef[];
    action: AgentRef[];
}

interface KBRef {
    id: string;
    name: string;
}

interface ToolRef {
    id: string;
    name: string;
    type: string;
}

const props = withDefaults(
    defineProps<{
        node: Node;
        allNodes?: Node[];
        availableModels?: AvailableModel[];
        availableAgents?: AvailableAgents;
        knowledgeBases?: KBRef[];
        tools?: ToolRef[];
    }>(),
    {
        allNodes: () => [],
        availableModels: () => [],
        availableAgents: () => ({ triage: [], knowledge: [], action: [] }),
        knowledgeBases: () => [],
        tools: () => [],
    },
);

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
const connectorData = computed(() => props.node.data as ConnectorNodeConfig);
const endData = computed(() => props.node.data as EndNodeConfig);

// Connector: list of targets (start + all menu nodes)
const connectorTargets = computed(() => {
    const targets: { id: string; label: string }[] = [
        { id: '__start__', label: t('flows.panel.connector_to_start') },
    ];

    for (const n of props.allNodes) {
        if (n.type === 'menu' && n.id !== props.node.id) {
            const menuMsg = (n.data as MenuNodeConfig).message;
            const label = menuMsg
                ? menuMsg.substring(0, 40) + (menuMsg.length > 40 ? '...' : '')
                : `Menu (${n.id})`;
            targets.push({ id: n.id, label });
        }
    }

    return targets;
});

function selectConnectorTarget(targetId: string) {
    const target = connectorTargets.value.find((t) => t.id === targetId);
    update({
        target_node_id: targetId,
        target_label: target?.label ?? '',
    });
}

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

// --- AI Agents (3-layer team) ---

const agentLayers = computed(() => {
    const layers = (props.node.data as AgentHandoffNodeConfig).layers;
    return {
        triage: layers?.triage ?? { enabled: true, agent_id: null },
        knowledge: layers?.knowledge ?? { enabled: false, agent_id: null },
        tools: layers?.tools ?? { enabled: false, agent_id: null },
    };
});

const triageMissing = computed(
    () => agentLayers.value.triage.enabled && !agentLayers.value.triage.agent_id,
);

function updateLayer(
    layer: 'triage' | 'knowledge' | 'tools',
    patch: Partial<AgentLayerConfig>,
) {
    const layers = {
        triage: { ...agentLayers.value.triage },
        knowledge: { ...agentLayers.value.knowledge },
        tools: { ...agentLayers.value.tools },
    };
    layers[layer] = { ...layers[layer], ...patch };
    update({ layers });
}

// Agent type mapping per layer
const layerAgentType: Record<string, 'triage' | 'knowledge' | 'action'> = {
    triage: 'triage',
    knowledge: 'knowledge',
    tools: 'action',
};

function agentsForLayer(layer: 'triage' | 'knowledge' | 'tools') {
    return props.availableAgents[layerAgentType[layer]] ?? [];
}

// Create agent modal state
const createModalOpen = ref(false);
const createModalLayer = ref<'triage' | 'knowledge' | 'tools'>('triage');

function openCreateModal(layer: 'triage' | 'knowledge' | 'tools') {
    createModalLayer.value = layer;
    createModalOpen.value = true;
}

function selectAgent(layer: 'triage' | 'knowledge' | 'tools', agentId: string) {
    const agents = agentsForLayer(layer);
    const agent = agents.find((a) => a.id === agentId);
    updateLayer(layer, {
        agent_id: agentId || null,
        agent_name: agent?.name ?? null,
    });
}

function onAgentCreated(agentId: string, agentName: string) {
    updateLayer(createModalLayer.value, {
        agent_id: agentId,
        agent_name: agentName,
    });
}
</script>

<template>
    <div class="flex h-full w-[320px] flex-col border-l border-soft bg-navy">
        <div class="flex items-center justify-between border-b border-soft px-3 py-3">
            <h3
                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
            >
                {{ t('flows.panel.title') }}
            </h3>
            <button
                type="button"
                class="flex size-7 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
                @click="emit('close')"
            >
                <X class="size-3.5" />
            </button>
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

            <!-- AI Agents Node (3-layer team: Triage / Knowledge / Tools) -->
            <template v-if="nodeType === 'agent_handoff'">
                <p class="text-xs text-muted-foreground">
                    {{ t('flows.panel.agents_team_description') }}
                </p>

                <Tabs default-value="triage" class="w-full">
                    <TabsList class="grid w-full grid-cols-3">
                        <TabsTrigger value="triage" class="text-xs gap-1">
                            <AlertTriangle
                                v-if="triageMissing"
                                class="h-3 w-3 text-amber-500"
                            />
                            {{ t('flows.panel.layer_triage') }}
                        </TabsTrigger>
                        <TabsTrigger value="knowledge" class="text-xs">
                            {{ t('flows.panel.layer_knowledge') }}
                        </TabsTrigger>
                        <TabsTrigger value="tools" class="text-xs">
                            {{ t('flows.panel.layer_tools') }}
                        </TabsTrigger>
                    </TabsList>

                    <!-- Triage: always enabled, required -->
                    <TabsContent value="triage" class="space-y-3 pt-3">
                        <div
                            v-if="triageMissing"
                            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-2.5 dark:border-amber-800 dark:bg-amber-950"
                        >
                            <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <p class="text-xs text-amber-800 dark:text-amber-200">
                                {{ t('flows.panel.triage_required_warning') }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label class="text-xs">
                                {{ t('flows.panel.layer_select_agent') }}
                            </Label>
                            <Select
                                :model-value="agentLayers.triage.agent_id ?? ''"
                                @update:model-value="selectAgent('triage', $event as string)"
                            >
                                <SelectTrigger class="h-8 text-xs">
                                    <SelectValue :placeholder="t('flows.panel.layer_select_agent_placeholder')" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="a in agentsForLayer('triage')"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.name }}
                                        <span class="ml-1 text-muted-foreground">({{ a.model }})</span>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="flex items-center gap-2">
                            <div class="h-px flex-1 bg-border" />
                            <span class="text-[10px] text-muted-foreground uppercase">
                                {{ t('flows.panel.layer_or') }}
                            </span>
                            <div class="h-px flex-1 bg-border" />
                        </div>

                        <Button
                            variant="outline"
                            size="sm"
                            class="w-full gap-1.5 text-xs"
                            @click="openCreateModal('triage')"
                        >
                            <Plus class="h-3.5 w-3.5" />
                            {{ t('flows.panel.layer_create_agent') }}
                        </Button>
                    </TabsContent>

                    <!-- Knowledge & Tools: optional, toggle -->
                    <TabsContent
                        v-for="layer in (['knowledge', 'tools'] as const)"
                        :key="layer"
                        :value="layer"
                        class="space-y-3 pt-3"
                    >
                        <div class="flex items-center justify-between">
                            <Label class="text-xs">
                                {{ t('flows.panel.layer_enabled') }}
                            </Label>
                            <Switch
                                :model-value="agentLayers[layer].enabled"
                                @update:model-value="
                                    updateLayer(layer, { enabled: $event as boolean })
                                "
                            />
                        </div>

                        <template v-if="agentLayers[layer].enabled">
                            <div class="grid gap-2">
                                <Label class="text-xs">
                                    {{ t('flows.panel.layer_select_agent') }}
                                </Label>
                                <Select
                                    :model-value="agentLayers[layer].agent_id ?? ''"
                                    @update:model-value="selectAgent(layer, $event as string)"
                                >
                                    <SelectTrigger class="h-8 text-xs">
                                        <SelectValue :placeholder="t('flows.panel.layer_select_agent_placeholder')" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="a in agentsForLayer(layer)"
                                            :key="a.id"
                                            :value="a.id"
                                        >
                                            {{ a.name }}
                                            <span class="ml-1 text-muted-foreground">({{ a.model }})</span>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex items-center gap-2">
                                <div class="h-px flex-1 bg-border" />
                                <span class="text-[10px] text-muted-foreground uppercase">
                                    {{ t('flows.panel.layer_or') }}
                                </span>
                                <div class="h-px flex-1 bg-border" />
                            </div>

                            <Button
                                variant="outline"
                                size="sm"
                                class="w-full gap-1.5 text-xs"
                                @click="openCreateModal(layer)"
                            >
                                <Plus class="h-3.5 w-3.5" />
                                {{ t('flows.panel.layer_create_agent') }}
                            </Button>
                        </template>

                        <p
                            v-else
                            class="rounded-md bg-muted/50 px-3 py-2 text-xs italic text-muted-foreground"
                        >
                            {{ t('flows.panel.layer_disabled_hint') }}
                        </p>
                    </TabsContent>
                </Tabs>

                <AgentCreateModal
                    :open="createModalOpen"
                    :type="layerAgentType[createModalLayer]"
                    :available-models="availableModels"
                    :knowledge-bases="knowledgeBases"
                    :tools="tools"
                    @update:open="createModalOpen = $event"
                    @created="onAgentCreated"
                />
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

            <!-- Connector Node -->
            <template v-if="nodeType === 'connector'">
                <div class="grid gap-2">
                    <Label>{{ t('flows.panel.connector_target') }}</Label>
                    <Select
                        :model-value="connectorData.target_node_id"
                        @update:model-value="selectConnectorTarget($event as string)"
                    >
                        <SelectTrigger>
                            <SelectValue :placeholder="t('flows.panel.connector_target_placeholder')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="target in connectorTargets"
                                :key="target.id"
                                :value="target.id"
                            >
                                {{ target.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ t('flows.panel.connector_description') }}
                </p>
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

        <div class="border-t border-soft p-3">
            <button
                type="button"
                class="inline-flex w-full items-center justify-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="nodeType === 'start'"
                @click="emit('removeNode', node.id)"
            >
                <Trash2 class="size-3.5" />
                {{ t('flows.panel.delete_node') }}
            </button>
        </div>
    </div>
</template>
