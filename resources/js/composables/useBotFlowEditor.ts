import type { BotFlowDefinition, BotFlowNodeType } from '@/types/botFlows';
import type { Edge, Node } from '@vue-flow/core';
import { computed, ref, type Ref } from 'vue';

export function useBotFlowEditor(initialDefinition?: BotFlowDefinition) {
    // Cast the Refs explicitly: `ref<Node[]>()` would force TS to evaluate
    // `UnwrapRef<Node[]>`, and vue-flow's deeply recursive `Node`/`Edge` types
    // blow the instantiation depth limit (TS2589).
    const nodes = ref([]) as Ref<Node[]>;
    const edges = ref([]) as Ref<Edge[]>;
    const selectedNodeId = ref<string | null>(null);

    function loadDefinition(def: BotFlowDefinition): void {
        nodes.value = def.nodes.map((n) => ({
            id: n.id,
            type: n.type,
            position: n.position,
            data: n.data,
        }));

        edges.value = def.edges.map((e) => ({
            id: e.id,
            source: e.source,
            target: e.target,
            sourceHandle: e.sourceHandle,
            label: e.label,
            animated: true,
        }));
    }

    function toDefinition(): BotFlowDefinition {
        return {
            nodes: nodes.value.map((n) => ({
                id: n.id,
                type: n.type as BotFlowNodeType,
                position: n.position,
                data: n.data,
            })),
            edges: edges.value.map((e) => ({
                id: e.id,
                source: e.source,
                target: e.target,
                sourceHandle: e.sourceHandle ?? undefined,
                label: typeof e.label === 'string' ? e.label : undefined,
            })),
        };
    }

    let nodeCounter = 0;

    function getDefaultData(type: BotFlowNodeType): Record<string, unknown> {
        switch (type) {
            case 'start':
                return { trigger: 'conversation_start' };
            case 'menu':
                return {
                    message: '',
                    options: [{ id: 'option_0', label: 'Option 1' }],
                };
            case 'condition':
                return {
                    match_type: 'contains',
                    rules: [{ id: 'match_0', pattern: '', label: '' }],
                };
            case 'agent':
                return {
                    role: 'triage',
                    agent_id: null,
                    agent_name: null,
                };
            case 'agent_handoff':
                return {
                    target_agent: 'triage_llm',
                    layers: {
                        triage: { enabled: true, agent_id: null },
                        knowledge: { enabled: false, agent_id: null },
                        tools: { enabled: false, agent_id: null },
                    },
                };
            case 'connector':
                return { target_node_id: '__start__', target_label: '' };
            case 'message':
                return { message: '' };
            case 'input':
                return { prompt: '', variable: '', input_type: 'text' };
            case 'human_handoff':
                return { message: '', reason: '', notify: true };
            case 'end':
                return { action: 'resume_conversation' };
            default:
                return {};
        }
    }

    function addNode(
        type: BotFlowNodeType,
        position: { x: number; y: number },
    ): string {
        nodeCounter++;
        const id = `node_${type}_${nodeCounter}_${Date.now()}`;
        const defaultData = getDefaultData(type);

        nodes.value = [
            ...nodes.value,
            { id, type, position, data: defaultData },
        ];

        return id;
    }

    function removeNode(id: string): void {
        nodes.value = nodes.value.filter((n) => n.id !== id);
        edges.value = edges.value.filter(
            (e) => e.source !== id && e.target !== id,
        );
        if (selectedNodeId.value === id) {
            selectedNodeId.value = null;
        }
    }

    function updateNodeData(id: string, data: Record<string, unknown>): void {
        const node = nodes.value.find((n) => n.id === id);
        if (node) {
            node.data = { ...node.data, ...data };
        }
    }

    function selectNode(id: string | null): void {
        selectedNodeId.value = id;
    }

    const selectedNode = computed(() => {
        if (!selectedNodeId.value) {
            return null;
        }
        return nodes.value.find((n) => n.id === selectedNodeId.value) ?? null;
    });

    function onConnect(params: {
        source: string;
        target: string;
        sourceHandle?: string | null;
        targetHandle?: string | null;
    }): void {
        edges.value = [
            ...edges.value,
            {
                id: `e_${params.source}_${params.target}_${Date.now()}`,
                source: params.source,
                target: params.target,
                sourceHandle: params.sourceHandle ?? undefined,
                targetHandle: params.targetHandle ?? undefined,
                animated: true,
            },
        ];
    }

    if (initialDefinition && initialDefinition.nodes?.length) {
        loadDefinition(initialDefinition);
    }

    return {
        nodes,
        edges,
        selectedNodeId,
        selectedNode,
        addNode,
        removeNode,
        updateNodeData,
        selectNode,
        loadDefinition,
        toDefinition,
        onConnect,
    };
}
