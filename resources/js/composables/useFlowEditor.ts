import type { FlowDefinition, FlowNodeType } from '@/types/flows';
import type { Edge, Node } from '@vue-flow/core';
import { computed, ref } from 'vue';

export function useFlowEditor(initialDefinition?: FlowDefinition) {
    const nodes = ref<Node[]>([]);
    const edges = ref<Edge[]>([]);
    const selectedNodeId = ref<string | null>(null);

    function loadDefinition(def: FlowDefinition): void {
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

    function toDefinition(): FlowDefinition {
        return {
            nodes: nodes.value.map((n) => ({
                id: n.id,
                type: n.type as FlowNodeType,
                position: n.position,
                data: n.data,
            })),
            edges: edges.value.map((e) => ({
                id: e.id,
                source: e.source,
                target: e.target,
                sourceHandle: e.sourceHandle,
                label: e.label,
            })),
        };
    }

    let nodeCounter = 0;

    function getDefaultData(type: FlowNodeType): Record<string, unknown> {
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
            case 'agent_handoff':
                return { target_agent: 'knowledge' };
            case 'message':
                return { message: '' };
            case 'end':
                return { action: 'resume_conversation' };
            default:
                return {};
        }
    }

    function addNode(
        type: FlowNodeType,
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
