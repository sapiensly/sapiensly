<script setup lang="ts">
import * as FlowController from '@/actions/App/Http/Controllers/FlowController';
import FlowNodePalette from '@/components/flows/FlowNodePalette.vue';
import FlowNodePanel from '@/components/flows/FlowNodePanel.vue';
import FlowTestWidget from '@/components/flows/FlowTestWidget.vue';
import FlowToolbar from '@/components/flows/FlowToolbar.vue';
import AgentHandoffNode from '@/components/flows/nodes/AgentHandoffNode.vue';
import ConditionNode from '@/components/flows/nodes/ConditionNode.vue';
import ConnectorNode from '@/components/flows/nodes/ConnectorNode.vue';
import EndNode from '@/components/flows/nodes/EndNode.vue';
import MenuNode from '@/components/flows/nodes/MenuNode.vue';
import MessageNode from '@/components/flows/nodes/MessageNode.vue';
import StartNode from '@/components/flows/nodes/StartNode.vue';
import { useFlowEditor } from '@/composables/useFlowEditor';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Agent } from '@/types/agents';
import type { Flow, FlowDefinition, FlowNodeType } from '@/types/flows';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { VueFlow } from '@vue-flow/core';
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import '@vue-flow/controls/dist/style.css';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';

defineOptions({
    inheritAttrs: true,
});

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

interface Props {
    agent: Agent | null;
    flow: Flow | null;
    availableModels?: AvailableModel[];
    availableAgents?: AvailableAgents;
    knowledgeBases?: KBRef[];
    tools?: ToolRef[];
}

const props = withDefaults(defineProps<Props>(), {
    availableModels: () => [],
    availableAgents: () => ({ triage: [], knowledge: [], action: [] }),
    knowledgeBases: () => [],
    tools: () => [],
});

const isCreating = !props.flow;

const defaultDefinition: FlowDefinition = {
    nodes: [
        {
            id: 'node_start_0',
            type: 'start',
            position: { x: 250, y: 50 },
            data: { trigger: 'conversation_start' },
        },
    ],
    edges: [],
};

const initialDefinition = props.flow?.definition ?? defaultDefinition;

const {
    nodes,
    edges,
    selectedNode,
    addNode,
    removeNode,
    updateNodeData,
    selectNode,
    toDefinition,
    onConnect,
} = useFlowEditor(initialDefinition);

const flowName = ref(props.flow?.name ?? t('flows.editor.new_flow'));
const flowStatus = ref<'draft' | 'active' | 'inactive'>(
    props.flow?.status ?? 'draft',
);

const form = useForm({
    name: '',
    description: '',
    definition: {} as FlowDefinition,
    status: '' as string,
});

const onNodeClick = (event: { node: { id: string } }) => {
    selectNode(event.node.id);
};

const onPaneClick = () => {
    selectNode(null);
};

const onEdgeUpdate = ({ edge, connection }: { edge: { id: string }; connection: { source: string; target: string; sourceHandle?: string | null; targetHandle?: string | null } }) => {
    // Remove old edge and add the updated one
    edges.value = edges.value.filter((e) => e.id !== edge.id);
    onConnect(connection);
};

const onDragOver = (event: DragEvent) => {
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
};

const vueFlowRef = ref<HTMLElement | null>(null);

const onDrop = (event: DragEvent) => {
    if (!event.dataTransfer) {
        return;
    }

    const type = event.dataTransfer.getData(
        'application/vueflow',
    ) as FlowNodeType;
    if (!type) {
        return;
    }

    const bounds = (vueFlowRef.value as HTMLElement)?.getBoundingClientRect();
    if (!bounds) {
        return;
    }

    const position = {
        x: event.clientX - bounds.left,
        y: event.clientY - bounds.top,
    };

    addNode(type, position);
};

// --- Auto-save ---

const autoSaveStatus = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;
let lastSavedJson = '';

function getPayload() {
    return {
        name: flowName.value,
        description: '',
        definition: toDefinition(),
        status: flowStatus.value,
    };
}

async function autoSave() {
    if (isCreating || !props.flow) return;

    const payload = getPayload();
    const json = JSON.stringify(payload);
    if (json === lastSavedJson) return;

    autoSaveStatus.value = 'saving';

    try {
        const url = props.agent
            ? FlowController.update({ agent: props.agent.id, flow: props.flow.id }).url
            : FlowController.globalUpdate({ flow: props.flow.id }).url;

        await axios.put(url, payload);
        lastSavedJson = json;
        autoSaveStatus.value = 'saved';
    } catch {
        autoSaveStatus.value = 'error';
    }
}

function scheduleAutoSave() {
    if (autoSaveTimer) clearTimeout(autoSaveTimer);
    autoSaveStatus.value = 'idle';
    autoSaveTimer = setTimeout(autoSave, 1500);
}

// Watch nodes, edges, and flowName for changes
if (!isCreating) {
    watch([nodes, edges, flowName], scheduleAutoSave, { deep: true });

    // Capture initial state to avoid saving unchanged data
    lastSavedJson = JSON.stringify(getPayload());
}

onUnmounted(() => {
    if (autoSaveTimer) clearTimeout(autoSaveTimer);
});

// Manual save (for create mode, or force save)
const save = () => {
    const definition = toDefinition();

    form.name = flowName.value;
    form.description = '';
    form.definition = definition;
    form.status = flowStatus.value;

    if (isCreating) {
        if (props.agent) {
            form.post(FlowController.store({ agent: props.agent.id }).url);
        } else {
            form.post(FlowController.globalStore().url);
        }
    } else {
        // Force save immediately
        autoSave();
    }
};

const toggleStatus = () => {
    if (!props.flow) {
        return;
    }

    if (flowStatus.value === 'active') {
        flowStatus.value = 'inactive';
        save();
    } else if (props.agent) {
        router.post(
            FlowController.activate({
                agent: props.agent.id,
                flow: props.flow.id,
            }).url,
            {},
            {
                onSuccess: () => {
                    flowStatus.value = 'active';
                },
            },
        );
    } else {
        flowStatus.value = 'active';
        save();
    }
};

const handleUpdateData = (id: string, data: Record<string, unknown>) => {
    updateNodeData(id, data);
};

const handleRemoveNode = (id: string) => {
    removeNode(id);
};

const backUrl = props.agent
    ? FlowController.index({ agent: props.agent.id }).url
    : FlowController.globalIndex().url;

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.flows'), href: backUrl },
    { title: flowName.value, href: '#' },
]);

</script>

<template>
    <Head :title="flowName" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex min-h-0 flex-1 flex-col">
        <FlowToolbar
            :back-url="backUrl"
            :name="flowName"
            :status="flowStatus"
            :processing="form.processing"
            :auto-save-status="autoSaveStatus"
            :is-creating="isCreating"
            @update:name="flowName = $event"
            @save="save"
            @toggle-status="toggleStatus"
        />

        <div class="flex min-h-0 flex-1 overflow-hidden">
            <FlowNodePalette />

            <div
                ref="vueFlowRef"
                class="relative flex-1"
                style="min-height: 0"
                @dragover="onDragOver"
                @drop="onDrop"
            >
                <VueFlow
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    :default-viewport="{ x: 100, y: 50, zoom: 1 }"
                    :min-zoom="0.2"
                    :max-zoom="4"
                    :edges-updatable="true"
                    style="width: 100%; height: 100%"
                    @node-click="onNodeClick"
                    @pane-click="onPaneClick"
                    @connect="onConnect"
                    @edge-update="onEdgeUpdate"
                >
                    <template #node-start="nodeProps">
                        <StartNode v-bind="nodeProps" />
                    </template>
                    <template #node-menu="nodeProps">
                        <MenuNode v-bind="nodeProps" />
                    </template>
                    <template #node-condition="nodeProps">
                        <ConditionNode v-bind="nodeProps" />
                    </template>
                    <template #node-agent_handoff="nodeProps">
                        <AgentHandoffNode v-bind="nodeProps" />
                    </template>
                    <template #node-message="nodeProps">
                        <MessageNode v-bind="nodeProps" />
                    </template>
                    <template #node-connector="nodeProps">
                        <ConnectorNode v-bind="nodeProps" />
                    </template>
                    <template #node-end="nodeProps">
                        <EndNode v-bind="nodeProps" />
                    </template>

                    <Background />
                    <Controls />
                </VueFlow>

                <FlowTestWidget v-if="flow" :flow-id="flow.id" />
            </div>

            <FlowNodePanel
                v-if="selectedNode"
                :node="selectedNode"
                :all-nodes="nodes"
                :available-models="availableModels"
                :available-agents="availableAgents"
                :knowledge-bases="knowledgeBases"
                :tools="tools"
                @close="selectNode(null)"
                @update-data="handleUpdateData"
                @remove-node="handleRemoveNode"
            />
        </div>

        </div>
    </AppLayout>
</template>
