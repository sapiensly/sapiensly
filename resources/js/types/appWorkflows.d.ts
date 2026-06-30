/**
 * Type mirror for the manifest's workflow object. The schema lives in
 * storage/app/schemas/app-manifest/v1.json; this file is the TS shape the
 * visual editor reads/writes. We keep step payloads permissive (`unknown`
 * for the type-specific fields) and trust the backend ManifestValidator
 * to catch shape violations when the user hits Save — duplicating the JSON
 * Schema in TS would just bit-rot.
 */

export type ManualTrigger = { type: 'manual'; label?: string };
export type RecordTrigger = {
    type: 'record.created' | 'record.updated' | 'record.deleted';
    object_id: string;
    filter?: unknown;
};
export type ScheduleTrigger = {
    type: 'schedule';
    cron: string;
    timezone?: string;
};
export type WebhookTrigger = {
    type: 'webhook.inbound';
    dedupe_path?: string;
    signature_header?: string;
};
export type DateReachedTrigger = {
    type: 'record.date_reached';
    object_id: string;
    field_id: string;
    offset?: {
        value?: number;
        unit?: 'minutes' | 'hours' | 'days' | 'weeks';
        direction?: 'before' | 'after';
    };
    at?: string;
    timezone?: string;
    filter?: unknown;
};
export type WorkflowTrigger =
    | ManualTrigger
    | RecordTrigger
    | ScheduleTrigger
    | WebhookTrigger
    | DateReachedTrigger;

export type StepType =
    | 'log'
    | 'set_variable'
    | 'record.create'
    | 'record.update'
    | 'record.delete'
    | 'record.query'
    | 'branch'
    | 'ai.complete'
    | 'http.request'
    | 'connector.call';

// ---------- Connector action contracts (from GET .../builder/connector-actions) ----------

export type ConnectorEffect = 'read' | 'write';

export interface ConnectorActionInput {
    name: string;
    type: string;
    required: boolean;
}

/** Mirror of App\DTOs\ConnectorActionContract::jsonSerialize(). */
export interface ConnectorActionContract {
    id: string;
    name: string;
    integration_id: string | null;
    tool_type: string;
    inputs: ConnectorActionInput[];
    outputs: string[];
    effect: ConnectorEffect;
    effect_inferred: boolean;
    blast_radius: string;
    safe: boolean;
    typed: boolean;
}

export interface ConnectorIntegration {
    id: string;
    name: string;
    authorized: boolean;
    actions: ConnectorActionContract[];
}

/** Catalog metadata used by the palette + panel — title/icon/description. */
export interface StepTypeMeta {
    type: StepType;
    label: string;
    description: string;
    /** Lucide icon name. */
    icon: string;
}

/**
 * A step row as it lives in the manifest (loosely typed). Each step has
 * common base fields plus type-specific keys we don't enforce in TS.
 */
export interface ManifestStep {
    id: string;
    type: StepType;
    name?: string;
    output_variable?: string;
    skip_if?: string;
    retry?: { max_attempts?: number; backoff_seconds?: number };
    /** Type-specific payload — varies by step type. */
    [key: string]: unknown;
}

export interface ManifestWorkflow {
    id: string;
    slug: string;
    name: string;
    description?: string;
    enabled?: boolean;
    trigger: WorkflowTrigger;
    steps: ManifestStep[];
    error_handler?: ManifestStep[];
    timeout_seconds?: number;
    max_retries?: number;
}

// ---------- Graph shape used by the canvas ----------
//
// We deliberately keep this MVP-narrow:
//   - One node per top-level step.
//   - One special "trigger" node.
//   - Linear edges trigger → step1 → step2 → ... → stepN.
//   - `branch.cases[]` and `branch.default_steps[]` are NOT expanded into
//     the canvas yet — the BranchNode renders a compact summary and the
//     nested edits happen via Claude or via a follow-up iteration of this
//     editor.

export type AppWorkflowNodeKind = 'trigger' | StepType;

export interface AppWorkflowNode {
    id: string;
    kind: AppWorkflowNodeKind;
    position: { x: number; y: number };
    /**
     * The actual step payload for step nodes; the trigger payload for the
     * trigger node. Edited live by the panel.
     */
    data: ManifestStep | WorkflowTrigger;
}

export interface AppWorkflowEdge {
    id: string;
    source: string;
    target: string;
}

export interface AppWorkflowGraph {
    /** Mirror of the manifest's workflow metadata, minus the steps array. */
    meta: Omit<ManifestWorkflow, 'steps'>;
    nodes: AppWorkflowNode[];
    edges: AppWorkflowEdge[];
}

/**
 * Step run result returned by POST /workflows/{wfId}/run. The editor
 * renders these inline next to each node so the user can see what
 * happened in a manual test.
 */
export interface WorkflowStepRunResult {
    id: string;
    step_id: string;
    step_type: string;
    status: 'pending' | 'running' | 'completed' | 'failed' | 'skipped';
    sequence_index: number;
    output: unknown;
    error: string | null;
}

export interface WorkflowRunResult {
    id: string;
    workflow_id: string;
    trigger_type: string;
    status: 'pending' | 'running' | 'completed' | 'failed';
    variables: Record<string, unknown> | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    steps: WorkflowStepRunResult[];
}
