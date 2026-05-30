/**
 * State + mutations for the App workflow visual editor.
 *
 * Wraps the graph model produced by `appWorkflowSerialize` and exposes the
 * primitive ops the editor UI needs:
 *   - load: hydrate from a manifest workflow
 *   - addStep: push a new step (at the end for MVP)
 *   - removeNode: delete a node by id + re-stitch its edges
 *   - updateNodeData: patch a node's payload (used by the panel form)
 *   - serialize: graph → manifest workflow ready to POST
 *   - select / deselect: track the side panel's focus
 *
 * Each editor instance is independent; the consumer creates one per
 * workflow it's editing (no global store).
 */

import {
    autoLayout,
    graphToManifest,
    manifestToGraph,
    newStepId,
    rebuildLinearEdges,
    TRIGGER_NODE_ID,
} from '@/lib/appWorkflowSerialize';
import { STEP_CATALOG } from '@/lib/appWorkflowStepCatalog';
import type {
    AppWorkflowGraph,
    AppWorkflowNode,
    ManifestStep,
    ManifestWorkflow,
    StepType,
} from '@/types/appWorkflows';
import { computed, ref } from 'vue';

export function useAppWorkflowEditor() {
    const graph = ref<AppWorkflowGraph | null>(null);
    const selectedNodeId = ref<string | null>(null);
    const dirty = ref(false);

    function load(workflow: ManifestWorkflow): void {
        graph.value = manifestToGraph(workflow);
        selectedNodeId.value = null;
        dirty.value = false;
    }

    const selectedNode = computed<AppWorkflowNode | null>(() => {
        if (!graph.value || !selectedNodeId.value) return null;
        return graph.value.nodes.find((n) => n.id === selectedNodeId.value) ?? null;
    });

    function select(nodeId: string | null): void {
        selectedNodeId.value = nodeId;
    }

    /** Push a step of the given type at the end of the chain. */
    function addStep(stepType: StepType): AppWorkflowNode | null {
        if (!graph.value) return null;
        const entry = STEP_CATALOG[stepType];
        const step: ManifestStep = {
            id: newStepId(),
            type: stepType,
            ...entry.defaults(),
        };

        // Order = trigger first, then existing steps, then the new one.
        const existingSteps = graphToManifest(graph.value).steps;
        const orderedIds = [TRIGGER_NODE_ID, ...existingSteps.map((s) => s.id), step.id];

        const newNode: AppWorkflowNode = {
            id: step.id,
            kind: stepType,
            position: { x: 0, y: 0 }, // autoLayout sets the real value next
            data: step,
        };

        graph.value = autoLayout({
            ...graph.value,
            nodes: [...graph.value.nodes, newNode],
            edges: rebuildLinearEdges(orderedIds),
        });
        dirty.value = true;
        selectedNodeId.value = newNode.id;
        return newNode;
    }

    /** Drop a node by id and re-stitch the chain so order is preserved. */
    function removeNode(nodeId: string): void {
        if (!graph.value) return;
        if (nodeId === TRIGGER_NODE_ID) return; // trigger is mandatory
        const survivors = graph.value.nodes.filter((n) => n.id !== nodeId);
        const surviving = graphToManifest({
            ...graph.value,
            nodes: survivors,
            edges: graph.value.edges.filter(
                (e) => e.source !== nodeId && e.target !== nodeId,
            ),
        }).steps;
        const orderedIds = [TRIGGER_NODE_ID, ...surviving.map((s) => s.id)];

        graph.value = autoLayout({
            ...graph.value,
            nodes: survivors,
            edges: rebuildLinearEdges(orderedIds),
        });
        dirty.value = true;
        if (selectedNodeId.value === nodeId) {
            selectedNodeId.value = null;
        }
    }

    /**
     * Patch a node's data payload — used by the side panel form on input.
     * The merge is shallow at the top level; nested objects (e.g. `values`
     * on a record.update) should be replaced wholesale by the panel.
     */
    function updateNodeData(nodeId: string, patch: Record<string, unknown>): void {
        if (!graph.value) return;
        graph.value = {
            ...graph.value,
            nodes: graph.value.nodes.map((n) =>
                n.id === nodeId ? { ...n, data: { ...n.data, ...patch } as never } : n,
            ),
        };
        dirty.value = true;
    }

    /** Patch the workflow's metadata (name, description, enabled, etc). */
    function updateMeta(patch: Partial<ManifestWorkflow>): void {
        if (!graph.value) return;
        graph.value = {
            ...graph.value,
            meta: { ...graph.value.meta, ...patch },
        };
        dirty.value = true;
    }

    /**
     * Persist VueFlow's node position updates back into our model so a
     * round-trip through the editor preserves manual layout. We still
     * rely on `autoLayout` for structural changes (add/remove) — manual
     * drags only override positions.
     */
    function updateNodePosition(nodeId: string, position: { x: number; y: number }): void {
        if (!graph.value) return;
        graph.value = {
            ...graph.value,
            nodes: graph.value.nodes.map((n) =>
                n.id === nodeId ? { ...n, position } : n,
            ),
        };
        // Dragging alone isn't "dirty" because the manifest schema doesn't
        // persist positions yet — don't mark dirty so the user doesn't get
        // a "unsaved changes" warning from cosmetic moves.
    }

    function serialize(): ManifestWorkflow | null {
        return graph.value ? graphToManifest(graph.value) : null;
    }

    function markClean(): void {
        dirty.value = false;
    }

    return {
        graph,
        selectedNode,
        selectedNodeId,
        dirty,
        load,
        select,
        addStep,
        removeNode,
        updateNodeData,
        updateMeta,
        updateNodePosition,
        serialize,
        markClean,
    };
}
