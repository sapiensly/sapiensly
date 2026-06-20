<script setup lang="ts">
import AgentCreateModal from '@/components/bot-flows/AgentCreateModal.vue';
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
    AgentNodeConfig,
    AgentRole,
    BotFlowNodeType,
    ConditionNodeConfig,
    ConditionRule,
    ConnectorNodeConfig,
    EndNodeConfig,
    HumanHandoffNodeConfig,
    InputNodeConfig,
    InputType,
    MenuNodeConfig,
    MenuOption,
    MessageNodeConfig,
    StartNodeConfig,
} from '@/types/botFlows';
import { AlertTriangle, Plus, Trash2, X } from '@lucide/vue';
import type { Node } from '@vue-flow/core';
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
    general: AgentRef[];
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
        availableAgents: () => ({
            general: [],
            triage: [],
            knowledge: [],
            action: [],
        }),
        knowledgeBases: () => [],
        tools: () => [],
    },
);

const emit = defineEmits<{
    close: [];
    updateData: [id: string, data: Record<string, unknown>];
    removeNode: [id: string];
}>();

const nodeType = computed(() => props.node.type as BotFlowNodeType);

const startData = computed(() => props.node.data as StartNodeConfig);
const menuData = computed(() => props.node.data as MenuNodeConfig);
const conditionData = computed(() => props.node.data as ConditionNodeConfig);
const agentNodeData = computed(() => props.node.data as AgentNodeConfig);

const agentsForRole = computed(
    () => props.availableAgents[agentNodeData.value.role] ?? [],
);

const changeAgentRole = (role: AgentRole) => {
    // Switching role resets the picked agent — the roster lists differ per role.
    update({ role, agent_id: null, agent_name: null });
};

const selectAgentForNode = (agentId: string) => {
    const agent = agentsForRole.value.find((a) => a.id === agentId);
    update({ agent_id: agentId, agent_name: agent?.name ?? null });
};
const messageData = computed(() => props.node.data as MessageNodeConfig);
const connectorData = computed(() => props.node.data as ConnectorNodeConfig);
const inputData = computed(() => props.node.data as InputNodeConfig);

// Accepted-files control maps the runtime `accept` array to a single choice.
const acceptValue = computed<string>(() => {
    const accept = inputData.value.accept ?? [];
    if (accept.includes('image')) {
        return 'image';
    }
    if (accept.includes('document')) {
        return 'document';
    }
    return 'any';
});

function setAcceptValue(value: string): void {
    update({ accept: value === 'any' ? [] : [value] });
}

const humanHandoffData = computed(
    () => props.node.data as HumanHandoffNodeConfig,
);
const endData = computed(() => props.node.data as EndNodeConfig);

// Connector: list of targets (start + all menu nodes)
const connectorTargets = computed(() => {
    const targets: { id: string; label: string }[] = [
        { id: '__start__', label: t('botFlows.panel.connector_to_start') },
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
    () =>
        agentLayers.value.triage.enabled && !agentLayers.value.triage.agent_id,
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

// --- Agent vs MultiAgent mode ---

/** Every available agent across roles, de-duplicated — for single-agent mode. */
const allAgents = computed<AgentRef[]>(() => {
    const seen = new Set<string>();
    const out: AgentRef[] = [];
    for (const list of [
        props.availableAgents.general,
        props.availableAgents.triage,
        props.availableAgents.knowledge,
        props.availableAgents.action,
    ]) {
        for (const a of list) {
            if (!seen.has(a.id)) {
                seen.add(a.id);
                out.push(a);
            }
        }
    }
    return out;
});

/** 'agent' (single) by default; legacy nodes with extra layers infer 'multi_agent'. */
const agentMode = computed<'agent' | 'multi_agent'>(() => {
    const mode = (props.node.data as AgentHandoffNodeConfig).mode;
    if (mode === 'agent' || mode === 'multi_agent') {
        return mode;
    }
    return agentLayers.value.knowledge.enabled ||
        agentLayers.value.tools.enabled
        ? 'multi_agent'
        : 'agent';
});

function setAgentMode(mode: 'agent' | 'multi_agent') {
    if (mode === 'agent') {
        // Single-agent: keep the (triage) agent as the bot's brain, turn the
        // optional layers off so the underlying roster stays single.
        update({
            mode,
            layers: {
                triage: { ...agentLayers.value.triage, enabled: true },
                knowledge: { ...agentLayers.value.knowledge, enabled: false },
                tools: { ...agentLayers.value.tools, enabled: false },
            },
        });
    } else {
        update({ mode });
    }
}

function selectSingleAgent(agentId: string) {
    const agent = allAgents.value.find((a) => a.id === agentId);
    updateLayer('triage', {
        enabled: true,
        agent_id: agentId || null,
        agent_name: agent?.name ?? null,
    });
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
        <div
            class="flex items-center justify-between border-b border-soft px-3 py-3"
        >
            <h3
                class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
            >
                {{ t('botFlows.panel.title') }}
            </h3>
            <button
                type="button"
                class="flex size-7 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                @click="emit('close')"
            >
                <X class="size-3.5" />
            </button>
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto p-3">
            <!-- Start Node -->
            <template v-if="nodeType === 'start'">
                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.trigger') }}</Label>
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
                                    t(
                                        'botFlows.panel.trigger_conversation_start',
                                    )
                                }}
                            </SelectItem>
                            <SelectItem value="keyword">
                                {{ t('botFlows.panel.trigger_keyword') }}
                            </SelectItem>
                            <SelectItem value="always">
                                {{ t('botFlows.panel.trigger_always') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="startData.trigger === 'keyword'" class="grid gap-2">
                    <Label>{{ t('botFlows.panel.keywords') }}</Label>
                    <Input
                        :model-value="(startData.keywords || []).join(', ')"
                        :placeholder="t('botFlows.panel.keywords_placeholder')"
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

            <!-- Agent Node -->
            <template v-if="nodeType === 'agent'">
                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.agent_role') }}</Label>
                    <Select
                        :model-value="agentNodeData.role"
                        @update:model-value="
                            changeAgentRole($event as AgentRole)
                        "
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="triage">
                                {{ t('botFlows.panel.layer_triage') }}
                            </SelectItem>
                            <SelectItem value="knowledge">
                                {{ t('botFlows.panel.layer_knowledge') }}
                            </SelectItem>
                            <SelectItem value="action">
                                {{ t('botFlows.panel.layer_action') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-[11px] text-ink-subtle">
                        {{ t('botFlows.panel.agent_role_hint') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.agent_pick') }}</Label>
                    <Select
                        v-if="agentsForRole.length > 0"
                        :model-value="agentNodeData.agent_id ?? ''"
                        @update:model-value="
                            selectAgentForNode($event as string)
                        "
                    >
                        <SelectTrigger>
                            <SelectValue
                                :placeholder="
                                    t('botFlows.panel.agent_pick_placeholder')
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="a in agentsForRole"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-else
                        class="flex items-center gap-1.5 text-[11px] text-sp-warning"
                    >
                        <AlertTriangle class="size-3.5" />
                        {{ t('botFlows.panel.agent_none_for_role') }}
                    </p>
                </div>
            </template>

            <!-- Menu Node -->
            <template v-if="nodeType === 'menu'">
                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.menu_message') }}</Label>
                    <Textarea
                        :model-value="menuData.message"
                        :placeholder="
                            t('botFlows.panel.menu_message_placeholder')
                        "
                        rows="3"
                        @update:model-value="update({ message: $event })"
                    />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>{{ t('botFlows.panel.options') }}</Label>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-6 px-2 text-xs"
                            @click="addMenuOption"
                        >
                            <Plus class="mr-1 h-3 w-3" />
                            {{ t('botFlows.panel.add_option') }}
                        </Button>
                    </div>

                    <div
                        v-for="(option, index) in menuData.options"
                        :key="option.id"
                        class="flex items-center gap-2"
                    >
                        <Input
                            :model-value="option.label"
                            :placeholder="`${t('botFlows.panel.option')} ${index + 1}`"
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
                    <Label>{{ t('botFlows.panel.match_type') }}</Label>
                    <Select
                        :model-value="conditionData.match_type"
                        @update:model-value="update({ match_type: $event })"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="exact">{{
                                t('botFlows.panel.match_exact')
                            }}</SelectItem>
                            <SelectItem value="contains">{{
                                t('botFlows.panel.match_contains')
                            }}</SelectItem>
                            <SelectItem value="regex">{{
                                t('botFlows.panel.match_regex')
                            }}</SelectItem>
                            <SelectItem value="llm_classification">{{
                                t('botFlows.panel.match_llm')
                            }}</SelectItem>
                            <SelectItem value="has_file">{{
                                t('botFlows.panel.match_has_file')
                            }}</SelectItem>
                            <SelectItem value="file_type_is">{{
                                t('botFlows.panel.match_file_type')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="
                            conditionData.match_type === 'has_file' ||
                            conditionData.match_type === 'file_type_is'
                        "
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('botFlows.panel.match_file_hint') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>{{ t('botFlows.panel.rules') }}</Label>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-6 px-2 text-xs"
                            @click="addConditionRule"
                        >
                            <Plus class="mr-1 h-3 w-3" />
                            {{ t('botFlows.panel.add_rule') }}
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
                                :placeholder="t('botFlows.panel.rule_label')"
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
                            :placeholder="t('botFlows.panel.rule_pattern')"
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
                <!-- Mode toggle: a single Agent (default) vs a Multi-agent team. -->
                <div
                    class="grid grid-cols-2 gap-1 rounded-md border border-soft p-0.5"
                >
                    <button
                        v-for="m in ['agent', 'multi_agent'] as const"
                        :key="m"
                        type="button"
                        class="rounded-[5px] px-2 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            agentMode === m
                                ? 'bg-accent-blue/15 text-ink'
                                : 'text-ink-muted hover:text-ink'
                        "
                        @click="setAgentMode(m)"
                    >
                        {{
                            m === 'agent'
                                ? t('botFlows.panel.mode_agent')
                                : t('botFlows.panel.mode_multi_agent')
                        }}
                    </button>
                </div>

                <!-- Single-agent mode -->
                <template v-if="agentMode === 'agent'">
                    <p class="text-xs text-muted-foreground">
                        {{ t('botFlows.panel.mode_agent_description') }}
                    </p>

                    <div class="grid gap-2">
                        <Label class="text-xs">
                            {{ t('botFlows.panel.layer_select_agent') }}
                        </Label>
                        <Select
                            :model-value="agentLayers.triage.agent_id ?? ''"
                            @update:model-value="
                                selectSingleAgent($event as string)
                            "
                        >
                            <SelectTrigger class="h-8 text-xs">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'botFlows.panel.layer_select_agent_placeholder',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="a in allAgents"
                                    :key="a.id"
                                    :value="a.id"
                                >
                                    {{ a.name }}
                                    <span class="ml-1 text-muted-foreground"
                                        >({{ a.model }})</span
                                    >
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="h-px flex-1 bg-border" />
                        <span
                            class="text-[10px] text-muted-foreground uppercase"
                        >
                            {{ t('botFlows.panel.layer_or') }}
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
                        {{ t('botFlows.panel.layer_create_agent') }}
                    </Button>
                </template>

                <Tabs v-else default-value="triage" class="w-full">
                    <TabsList class="grid w-full grid-cols-3">
                        <TabsTrigger value="triage" class="gap-1 text-xs">
                            <AlertTriangle
                                v-if="triageMissing"
                                class="h-3 w-3 text-amber-500"
                            />
                            {{ t('botFlows.panel.layer_triage') }}
                        </TabsTrigger>
                        <TabsTrigger value="knowledge" class="text-xs">
                            {{ t('botFlows.panel.layer_knowledge') }}
                        </TabsTrigger>
                        <TabsTrigger value="tools" class="text-xs">
                            {{ t('botFlows.panel.layer_tools') }}
                        </TabsTrigger>
                    </TabsList>

                    <!-- Triage: always enabled, required -->
                    <TabsContent value="triage" class="space-y-3 pt-3">
                        <div
                            v-if="triageMissing"
                            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-2.5 dark:border-amber-800 dark:bg-amber-950"
                        >
                            <AlertTriangle
                                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-400"
                            />
                            <p
                                class="text-xs text-amber-800 dark:text-amber-200"
                            >
                                {{
                                    t('botFlows.panel.triage_required_warning')
                                }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label class="text-xs">
                                {{ t('botFlows.panel.layer_select_agent') }}
                            </Label>
                            <Select
                                :model-value="agentLayers.triage.agent_id ?? ''"
                                @update:model-value="
                                    selectAgent('triage', $event as string)
                                "
                            >
                                <SelectTrigger class="h-8 text-xs">
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'botFlows.panel.layer_select_agent_placeholder',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="a in agentsForLayer('triage')"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.name }}
                                        <span class="ml-1 text-muted-foreground"
                                            >({{ a.model }})</span
                                        >
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="flex items-center gap-2">
                            <div class="h-px flex-1 bg-border" />
                            <span
                                class="text-[10px] text-muted-foreground uppercase"
                            >
                                {{ t('botFlows.panel.layer_or') }}
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
                            {{ t('botFlows.panel.layer_create_agent') }}
                        </Button>
                    </TabsContent>

                    <!-- Knowledge & Tools: optional, toggle -->
                    <TabsContent
                        v-for="layer in ['knowledge', 'tools'] as const"
                        :key="layer"
                        :value="layer"
                        class="space-y-3 pt-3"
                    >
                        <div class="flex items-center justify-between">
                            <Label class="text-xs">
                                {{ t('botFlows.panel.layer_enabled') }}
                            </Label>
                            <Switch
                                :model-value="agentLayers[layer].enabled"
                                @update:model-value="
                                    updateLayer(layer, {
                                        enabled: $event as boolean,
                                    })
                                "
                            />
                        </div>

                        <template v-if="agentLayers[layer].enabled">
                            <div class="grid gap-2">
                                <Label class="text-xs">
                                    {{ t('botFlows.panel.layer_select_agent') }}
                                </Label>
                                <Select
                                    :model-value="
                                        agentLayers[layer].agent_id ?? ''
                                    "
                                    @update:model-value="
                                        selectAgent(layer, $event as string)
                                    "
                                >
                                    <SelectTrigger class="h-8 text-xs">
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'botFlows.panel.layer_select_agent_placeholder',
                                                )
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="a in agentsForLayer(layer)"
                                            :key="a.id"
                                            :value="a.id"
                                        >
                                            {{ a.name }}
                                            <span
                                                class="ml-1 text-muted-foreground"
                                                >({{ a.model }})</span
                                            >
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex items-center gap-2">
                                <div class="h-px flex-1 bg-border" />
                                <span
                                    class="text-[10px] text-muted-foreground uppercase"
                                >
                                    {{ t('botFlows.panel.layer_or') }}
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
                                {{ t('botFlows.panel.layer_create_agent') }}
                            </Button>
                        </template>

                        <p
                            v-else
                            class="rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground italic"
                        >
                            {{ t('botFlows.panel.layer_disabled_hint') }}
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
                    <Label>{{ t('botFlows.panel.message_text') }}</Label>
                    <Textarea
                        :model-value="messageData.message"
                        :placeholder="t('botFlows.panel.message_placeholder')"
                        rows="4"
                        @update:model-value="update({ message: $event })"
                    />
                </div>
            </template>

            <!-- Input Node -->
            <template v-if="nodeType === 'input'">
                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.input_prompt') }}</Label>
                    <Textarea
                        :model-value="inputData.prompt"
                        :placeholder="
                            t('botFlows.panel.input_prompt_placeholder')
                        "
                        rows="3"
                        @update:model-value="update({ prompt: $event })"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.input_variable') }}</Label>
                    <Input
                        :model-value="inputData.variable"
                        :placeholder="
                            t('botFlows.panel.input_variable_placeholder')
                        "
                        @update:model-value="update({ variable: $event })"
                    />
                    <p class="text-[11px] text-ink-subtle">
                        {{ t('botFlows.panel.input_variable_hint') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.input_type') }}</Label>
                    <Select
                        :model-value="inputData.input_type ?? 'text'"
                        @update:model-value="
                            update({ input_type: $event as InputType })
                        "
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="text">
                                {{ t('botFlows.panel.input_type_text') }}
                            </SelectItem>
                            <SelectItem value="email">
                                {{ t('botFlows.panel.input_type_email') }}
                            </SelectItem>
                            <SelectItem value="number">
                                {{ t('botFlows.panel.input_type_number') }}
                            </SelectItem>
                            <SelectItem value="phone">
                                {{ t('botFlows.panel.input_type_phone') }}
                            </SelectItem>
                            <SelectItem value="file">
                                {{ t('botFlows.panel.input_type_file') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="inputData.input_type === 'file'" class="grid gap-2">
                    <Label>{{ t('botFlows.panel.input_accept') }}</Label>
                    <Select
                        :model-value="acceptValue"
                        @update:model-value="setAcceptValue($event as string)"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="any">
                                {{ t('botFlows.panel.input_accept_any') }}
                            </SelectItem>
                            <SelectItem value="image">
                                {{ t('botFlows.panel.input_accept_image') }}
                            </SelectItem>
                            <SelectItem value="document">
                                {{ t('botFlows.panel.input_accept_document') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.input_error_message') }}</Label>
                    <Input
                        :model-value="inputData.error_message || ''"
                        :placeholder="
                            t('botFlows.panel.input_error_message_placeholder')
                        "
                        @update:model-value="update({ error_message: $event })"
                    />
                </div>
            </template>

            <!-- Human Handoff Node -->
            <template v-if="nodeType === 'human_handoff'">
                <p class="text-xs text-muted-foreground">
                    {{ t('botFlows.panel.human_handoff_description') }}
                </p>

                <div class="grid gap-2">
                    <Label>{{
                        t('botFlows.panel.human_handoff_message')
                    }}</Label>
                    <Textarea
                        :model-value="humanHandoffData.message || ''"
                        :placeholder="
                            t(
                                'botFlows.panel.human_handoff_message_placeholder',
                            )
                        "
                        rows="2"
                        @update:model-value="update({ message: $event })"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>{{
                        t('botFlows.panel.human_handoff_reason')
                    }}</Label>
                    <Input
                        :model-value="humanHandoffData.reason || ''"
                        :placeholder="
                            t('botFlows.panel.human_handoff_reason_placeholder')
                        "
                        @update:model-value="update({ reason: $event })"
                    />
                </div>

                <div class="flex items-center justify-between">
                    <Label>{{
                        t('botFlows.panel.human_handoff_notify')
                    }}</Label>
                    <Switch
                        :model-value="humanHandoffData.notify ?? true"
                        @update:model-value="
                            update({ notify: $event as boolean })
                        "
                    />
                </div>
            </template>

            <!-- Connector Node -->
            <template v-if="nodeType === 'connector'">
                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.connector_target') }}</Label>
                    <Select
                        :model-value="connectorData.target_node_id"
                        @update:model-value="
                            selectConnectorTarget($event as string)
                        "
                    >
                        <SelectTrigger>
                            <SelectValue
                                :placeholder="
                                    t(
                                        'botFlows.panel.connector_target_placeholder',
                                    )
                                "
                            />
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
                    {{ t('botFlows.panel.connector_description') }}
                </p>
            </template>

            <!-- End Node -->
            <template v-if="nodeType === 'end'">
                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.end_action') }}</Label>
                    <Select
                        :model-value="endData.action"
                        @update:model-value="update({ action: $event })"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="resume_conversation">
                                {{ t('botFlows.panel.end_resume') }}
                            </SelectItem>
                            <SelectItem value="close_conversation">
                                {{ t('botFlows.panel.end_close') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>{{ t('botFlows.panel.end_message') }}</Label>
                    <Textarea
                        :model-value="endData.message || ''"
                        :placeholder="
                            t('botFlows.panel.end_message_placeholder')
                        "
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
                {{ t('botFlows.panel.delete_node') }}
            </button>
        </div>
    </div>
</template>
