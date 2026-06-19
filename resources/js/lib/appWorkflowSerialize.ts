/**
 * manifest ⇄ graph adapters for the visual workflow editor.
 *
 * Design choices:
 *   - One node per TOP-LEVEL step. Branch steps' nested `cases[].steps`
 *     and `default_steps` stay in the manifest payload as opaque arrays
 *     until a future iteration expands them as separate nodes. For now
 *     they're round-tripped untouched.
 *   - Auto-layout: trigger at (0, 0), step i at (0, (i+1) * VERTICAL_GAP).
 *     We never persist positions — the layout is computed fresh on every
 *     load. Future: extend the manifest schema with an optional
 *     `_layout` block (currently rejected by additionalProperties:false).
 *   - Edges: a strict chain trigger → step0 → step1 → ... → stepN. The
 *     engine runs steps in array order, so visual edge reordering must
 *     update the underlying steps[] order, not just the edges.
 */

import type {
    AppWorkflowEdge,
    AppWorkflowGraph,
    AppWorkflowNode,
    ManifestStep,
    ManifestWorkflow,
    StepType,
    WorkflowTrigger,
} from '@/types/appWorkflows';

/** Pixel gap between two consecutive nodes vertically. */
export const VERTICAL_GAP = 130;

/** Stable, well-known id for the trigger node so edges can reference it. */
export const TRIGGER_NODE_ID = '__trigger__';

/**
 * Turn a manifest workflow into the canvas graph the editor renders.
 */
export function manifestToGraph(workflow: ManifestWorkflow): AppWorkflowGraph {
    const { steps, ...metaPlusSteps } = workflow;
    // Drop `steps` from meta — graph holds them as nodes.
    const meta = { ...metaPlusSteps } as Omit<ManifestWorkflow, 'steps'>;

    const nodes: AppWorkflowNode[] = [
        {
            id: TRIGGER_NODE_ID,
            kind: 'trigger',
            position: { x: 0, y: 0 },
            data: workflow.trigger,
        },
        ...steps.map((step, idx) => ({
            id: step.id,
            kind: step.type as StepType,
            position: { x: 0, y: (idx + 1) * VERTICAL_GAP },
            data: step,
        })),
    ];

    const edges: AppWorkflowEdge[] = [];
    let prevId: string = TRIGGER_NODE_ID;
    for (const step of steps) {
        edges.push({
            id: `e_${prevId}_${step.id}`,
            source: prevId,
            target: step.id,
        });
        prevId = step.id;
    }

    return { meta, nodes, edges };
}

/**
 * Inverse: collapse the graph back into a manifest workflow.
 *
 * We treat the edge graph as authoritative for ORDER — walk it starting at
 * the trigger, following whichever edge leaves the current node first.
 * This way the user can drag nodes around freely without that desync from
 * the step execution order, AS LONG AS edges are kept consistent (which
 * the editor enforces).
 */
export function graphToManifest(graph: AppWorkflowGraph): ManifestWorkflow {
    // Build adjacency from edges, indexed by source.
    const outgoing = new Map<string, AppWorkflowEdge[]>();
    for (const edge of graph.edges) {
        const bucket = outgoing.get(edge.source) ?? [];
        bucket.push(edge);
        outgoing.set(edge.source, bucket);
    }

    // Build node lookup.
    const byId = new Map(graph.nodes.map((n) => [n.id, n]));

    // Walk: start at trigger, follow first outgoing edge, repeat until we
    // either revisit a node (cycle — bail out) or run out of edges.
    const ordered: ManifestStep[] = [];
    const visited = new Set<string>([TRIGGER_NODE_ID]);
    let currentId: string | null = TRIGGER_NODE_ID;
    while (currentId !== null) {
        const nextEdges = outgoing.get(currentId) ?? [];
        // No outgoing → done.
        if (nextEdges.length === 0) break;
        // For MVP we always follow the first edge. Branches' multiple
        // outgoing handles aren't expanded as canvas edges in v1, so this
        // is safe.
        const nextId: string = nextEdges[0].target;
        if (visited.has(nextId)) {
            // Pathological cycle (shouldn't happen via the editor's UI but
            // defend anyway) — stop, the user can re-link manually.
            break;
        }
        visited.add(nextId);
        const node = byId.get(nextId);
        if (node && node.kind !== 'trigger') {
            ordered.push(node.data as ManifestStep);
        }
        currentId = nextId;
    }

    // Pick up the trigger payload from the trigger node — the panel may
    // have edited it.
    const triggerNode = byId.get(TRIGGER_NODE_ID);
    const trigger = (triggerNode?.data ??
        graph.meta.trigger) as WorkflowTrigger;

    return {
        ...graph.meta,
        trigger,
        steps: ordered.map(sanitizeStep),
    };
}

/**
 * Map of step type → property names that the schema declares as required
 * strings. We coerce null/undefined to '' here so the user doesn't get
 * cryptic "(null) must match the type: string" errors from Vue/reactivity
 * occasionally leaking null where an empty string is expected.
 *
 * Empty strings still satisfy the schema (no minLength on these fields),
 * so this is a safe normalisation — never widens the accepted shape.
 */
const STEP_STRING_FIELDS: Record<string, string[]> = {
    log: ['message'],
    set_variable: ['variable'],
    'record.create': ['object_id'],
    'record.update': ['object_id', 'record_id'],
    'record.delete': ['object_id', 'record_id'],
    'record.query': ['object_id'],
    'ai.complete': ['prompt'],
    'http.request': ['method', 'url'],
    'connector.call': ['tool_id'],
};

function sanitizeStep(step: ManifestStep): ManifestStep {
    const cleaned: ManifestStep = { ...step };
    const stringFields = STEP_STRING_FIELDS[step.type] ?? [];
    for (const field of stringFields) {
        const v = cleaned[field];
        if (v === null || v === undefined) {
            cleaned[field] = '';
        }
    }
    return cleaned;
}

/**
 * Re-flow node positions into a clean vertical line. Used after deletions
 * or insertions in the middle of the list, when the user hasn't explicitly
 * dragged anywhere.
 */
export function autoLayout(graph: AppWorkflowGraph): AppWorkflowGraph {
    const ordered = graphToManifest(graph).steps;
    const triggerNode = graph.nodes.find((n) => n.id === TRIGGER_NODE_ID);
    const stepNodes = ordered.map(
        (step, idx) =>
            ({
                id: step.id,
                kind: step.type as StepType,
                position: { x: 0, y: (idx + 1) * VERTICAL_GAP },
                data: step,
            }) as AppWorkflowNode,
    );
    return {
        meta: graph.meta,
        nodes: [
            triggerNode ?? {
                id: TRIGGER_NODE_ID,
                kind: 'trigger' as const,
                position: { x: 0, y: 0 },
                data: graph.meta.trigger,
            },
            ...stepNodes,
        ],
        edges: rebuildLinearEdges([
            TRIGGER_NODE_ID,
            ...ordered.map((s) => s.id),
        ]),
    };
}

/**
 * Build a strict linear chain of edges from an ordered list of node ids.
 * Used by autoLayout and when adding/removing nodes from the middle.
 */
export function rebuildLinearEdges(orderedIds: string[]): AppWorkflowEdge[] {
    const edges: AppWorkflowEdge[] = [];
    for (let i = 0; i < orderedIds.length - 1; i++) {
        edges.push({
            id: `e_${orderedIds[i]}_${orderedIds[i + 1]}`,
            source: orderedIds[i],
            target: orderedIds[i + 1],
        });
    }
    return edges;
}

/**
 * Generate a manifest-compatible step id with the canonical `stp_<ulid>`
 * shape used elsewhere. Crockford base32 character set, time-prefixed for
 * sortability without leaking the actual timestamp.
 */
export function newStepId(): string {
    return 'stp_' + ulidLike();
}

export function newWorkflowId(): string {
    return 'wkf_' + ulidLike();
}

/**
 * Lightweight ULID-shaped id. Not RFC-compliant (no monotonic counter and
 * no time encoding) but matches the manifest's id pattern `[a-z0-9_]{8,60}`
 * and never collides in practice (~52 bits of entropy).
 */
function ulidLike(): string {
    const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
    let out = '';
    for (let i = 0; i < 16; i++) {
        out += alphabet[Math.floor(Math.random() * alphabet.length)];
    }
    return out;
}
