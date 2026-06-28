<script setup lang="ts">
/**
 * The visual workflow editor surface. Owns:
 *  - The composable instance for the open workflow
 *  - The @vue-flow canvas
 *  - The palette, side panel, and toolbar
 *  - Save / Run network calls + their result rendering
 *
 * Parent (AppWorkflowsTab) tells us which workflow to load via the
 * `workflow` prop. We hydrate the composable on mount + whenever the prop
 * changes, so switching workflows in the list is a one-prop swap.
 */

import AppWorkflowNodePanel from '@/components/apps/workflows/AppWorkflowNodePanel.vue';
import AppWorkflowPalette from '@/components/apps/workflows/AppWorkflowPalette.vue';
import AppWorkflowToolbar from '@/components/apps/workflows/AppWorkflowToolbar.vue';
import StepNode from '@/components/apps/workflows/nodes/StepNode.vue';
import TriggerNode from '@/components/apps/workflows/nodes/TriggerNode.vue';
import { useAppWorkflowEditor } from '@/composables/useAppWorkflowEditor';
import { TRIGGER_NODE_ID } from '@/lib/appWorkflowSerialize';
import {
    STEP_CATALOG,
    type ConnectorActionSummary,
} from '@/lib/appWorkflowStepCatalog';
import type {
    ConnectorIntegration,
    ManifestWorkflow,
    StepType,
    WorkflowRunResult,
} from '@/types/appWorkflows';
import { Check, X } from '@lucide/vue';
import { Background } from '@vue-flow/background';
import {
    VueFlow,
    type NodeMouseEvent,
    type Node as VueFlowNode,
} from '@vue-flow/core';
import '@vue-flow/core/dist/style.css';
import axios from 'axios';
import { computed, onMounted, provide, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface ManifestObject {
    id: string;
    slug: string;
    name: string;
}

const props = defineProps<{
    appId: string;
    workflow: ManifestWorkflow;
    /** The App's objects — passed to the side panel for the object picker. */
    objects: ManifestObject[];
}>();

const emit = defineEmits<{
    (e: 'saved', payload: { manifest: Record<string, unknown> }): void;
    (e: 'deleted', workflowId: string): void;
    (e: 'back'): void;
}>();

const { t } = useI18n();

const editor = useAppWorkflowEditor();

const saving = ref(false);
const running = ref(false);
const lastRun = shallowRef<WorkflowRunResult | null>(null);
const errorText = ref<string | null>(null);

interface VerifyReport {
    passed: boolean;
    run: { status: string; error: string | null };
    assertions: Array<{
        type: string;
        label: string;
        passed: boolean;
        detail: string;
    }>;
    simulated_writes: Array<{
        step_id: string;
        step_type: string;
        effect: string;
        preview: string | null;
    }>;
}
const verifying = ref(false);
const verifyReport = shallowRef<VerifyReport | null>(null);

interface PendingProposal {
    id: string;
    workflow_id: string;
    run_id: string;
    step_id: string;
    effect: string;
    preview: string | null;
}
const pendingProposals = ref<PendingProposal[]>([]);
const resolvingProposal = ref<string | null>(null);

// VueFlow needs a flat array of node/edge objects with `type` set so it
// can dispatch to the registered components.
const vueFlowNodes = computed<VueFlowNode[]>(() => {
    if (!editor.graph.value) return [];
    return editor.graph.value.nodes.map((n) => ({
        id: n.id,
        type: n.kind === 'trigger' ? 'trigger' : 'step',
        position: n.position,
        data: n.data,
    }));
});

const vueFlowEdges = computed(() => {
    if (!editor.graph.value) return [];
    return editor.graph.value.edges.map((e) => ({
        id: e.id,
        source: e.source,
        target: e.target,
        animated: false,
    }));
});

const nodeTypes = { step: StepNode, trigger: TriggerNode };

// Manual-trigger workflows are the only ones the Run button can fire.
const canRun = computed(() => {
    const trigger = editor.graph.value?.meta.trigger;
    return trigger?.type === 'manual';
});

// Provide a lookup table from object_id → object for any descendant node
// component (e.g. StepNode) that needs to resolve a friendly name in its
// card summary. @vue-flow/core swallows extra props passed to node types,
// so inject is the path that survives the render boundary.
const objectsById = computed(
    () => new Map(props.objects.map((o) => [o.id, o])),
);
provide('appWorkflowObjectsById', objectsById);

// Connector actions, fetched lazily (integrations change out-of-band).
// Drives the connector.call node's label + effect ribbon and the panel's
// integration/action pickers.
const connectorIntegrations = ref<ConnectorIntegration[]>([]);

const connectorActionsById = computed(() => {
    const map = new Map<string, ConnectorActionSummary>();
    for (const integration of connectorIntegrations.value) {
        for (const action of integration.actions) {
            map.set(action.id, {
                name: action.name,
                integrationName: integration.name,
                effect: action.effect,
                safe: action.safe,
            });
        }
    }
    return map;
});
provide('appWorkflowConnectorActionsById', connectorActionsById);

async function loadConnectorActions() {
    try {
        const { data } = await axios.get(
            `/apps/${props.appId}/builder/connector-actions`,
        );
        connectorIntegrations.value = data.integrations ?? [];
    } catch {
        // Non-fatal: the canvas still works, connector nodes just fall back
        // to showing the raw tool id with no effect ribbon.
        connectorIntegrations.value = [];
    }
}

const workflowName = computed(() => editor.graph.value?.meta.name ?? '');

onMounted(() => {
    editor.load(props.workflow);
    loadConnectorActions();
});

watch(
    () => props.workflow,
    (next) => {
        editor.load(next);
        lastRun.value = null;
        errorText.value = null;
    },
);

function onNodeClick(event: NodeMouseEvent) {
    editor.select(event.node.id);
}

function onPaneClick() {
    editor.select(null);
}

function onNodeDragStop(event: { node: VueFlowNode }) {
    editor.updateNodePosition(event.node.id, event.node.position);
}

function addStep(stepType: StepType) {
    editor.addStep(stepType);
}

function discard() {
    editor.load(props.workflow);
    lastRun.value = null;
}

async function save() {
    const serialized = editor.serialize();
    if (!serialized) return;

    saving.value = true;
    errorText.value = null;
    try {
        const { data } = await axios.put(
            `/apps/${props.appId}/builder/workflows/${serialized.id}`,
            { workflow: serialized },
            { timeout: 15_000 },
        );
        editor.markClean();
        emit('saved', { manifest: data.manifest });
    } catch (e) {
        const err = e as {
            message?: string;
            response?: {
                status?: number;
                data?: { error?: string; message?: string; errors?: unknown[] };
            };
        };
        const body = err.response?.data;
        if (
            body?.error === 'invalid_manifest' &&
            Array.isArray(body.errors) &&
            body.errors.length > 0
        ) {
            // Show every error, deduped — Opis returns multiple lines when a
            // `oneOf` discriminator can't pick a single branch, and surfacing
            // only the first is misleading (the user sees "object_id missing"
            // on a log step, which is from a non-matching record.* branch).
            const seen = new Set<string>();
            const lines: string[] = [];
            for (const raw of body.errors) {
                const e = raw as { path?: string; message?: string };
                const key = (e.path ?? '') + '|' + (e.message ?? '');
                if (seen.has(key)) continue;
                seen.add(key);
                lines.push(`${e.path ?? ''} — ${e.message ?? ''}`);
                if (lines.length >= 6) break; // cap to keep the rail readable
            }
            errorText.value = lines.join('\n');
        } else {
            errorText.value = body?.message ?? err.message ?? 'Save failed';
        }
         
        console.error(
            'Workflow save failed:',
            e,
            'serialized payload:',
            serialized,
        );
    } finally {
        saving.value = false;
    }
}

async function run() {
    const wfId = editor.graph.value?.meta.id;
    if (!wfId) return;
    running.value = true;
    errorText.value = null;
    lastRun.value = null;
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/workflows/${wfId}/run`,
            {},
            { timeout: 30_000 },
        );
        lastRun.value = data.run as WorkflowRunResult;
        // A real run can halt on a gated write (propose-don't-mutate). Surface
        // the pending proposals so the user can approve or dismiss them.
        pendingProposals.value = [];
        if (data.run?.status === 'awaiting_approval') {
            await loadPendingProposals();
        }
    } catch (e) {
        const err = e as {
            message?: string;
            response?: { data?: { message?: string } };
        };
        errorText.value =
            err.response?.data?.message ?? err.message ?? 'Run failed';
         
        console.error('Workflow run failed:', e);
    } finally {
        running.value = false;
    }
}

async function loadPendingProposals() {
    try {
        const { data } = await axios.get(
            `/apps/${props.appId}/builder/workflow-proposals`,
        );
        pendingProposals.value = data.proposals ?? [];
    } catch {
        pendingProposals.value = [];
    }
}

async function resolveProposal(id: string, decision: 'approve' | 'dismiss') {
    resolvingProposal.value = id;
    try {
        await axios.post(
            `/apps/${props.appId}/builder/workflow-proposals/${id}/${decision}`,
        );
        pendingProposals.value = pendingProposals.value.filter(
            (p) => p.id !== id,
        );
    } catch (e) {
        const err = e as {
            message?: string;
            response?: { data?: { error?: string; message?: string } };
        };
        errorText.value =
            err.response?.data?.error ??
            err.response?.data?.message ??
            err.message ??
            'Action failed';
    } finally {
        resolvingProposal.value = null;
    }
}

async function verify() {
    const wfId = editor.graph.value?.meta.id;
    if (!wfId) return;
    verifying.value = true;
    errorText.value = null;
    verifyReport.value = null;
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/workflows/${wfId}/verify`,
            {},
            { timeout: 30_000 },
        );
        verifyReport.value = data as VerifyReport;
    } catch (e) {
        const err = e as {
            message?: string;
            response?: { data?: { message?: string } };
        };
        errorText.value =
            err.response?.data?.message ?? err.message ?? 'Verify failed';
    } finally {
        verifying.value = false;
    }
}

async function deleteWorkflow() {
    const wfId = editor.graph.value?.meta.id;
    if (!wfId) return;
    if (!window.confirm(t('apps.builder.workflows.delete_confirm'))) return;

    // No dedicated delete endpoint — emit up so the parent does a
    // remove via the propose_change / manifest edit path. Parent owns
    // the manifest list anyway.
    emit('deleted', wfId);
}

// Quick lookup so the run-result panel can show a step name/icon when it
// summarizes execution.
function describeStepRunRow(stepId: string): string {
    const node = editor.graph.value?.nodes.find((n) => n.id === stepId);
    if (!node || node.kind === 'trigger') return stepId;
    const entry = STEP_CATALOG[node.kind as StepType];
    const data = node.data as { name?: string };
    return data.name?.trim() || (entry ? t(entry.labelKey) : stepId);
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <AppWorkflowToolbar
            :workflow-name="workflowName"
            :is-dirty="editor.dirty.value"
            :saving="saving"
            :running="running"
            :verifying="verifying"
            :can-run="canRun"
            :is-editing="true"
            @save="save"
            @run="run"
            @verify="verify"
            @discard="discard"
            @back="emit('back')"
            @delete="deleteWorkflow"
        />

        <pre
            v-if="errorText"
            class="max-h-40 overflow-auto border-b border-red-400/30 bg-red-400/10 px-4 py-2 font-mono text-xs leading-relaxed whitespace-pre-wrap text-red-300"
            >{{ errorText }}</pre
        >

        <div class="flex min-h-0 flex-1">
            <AppWorkflowPalette @add="addStep" />

            <!-- The canvas — fills available space. -->
            <section class="flex min-h-0 flex-1 flex-col">
                <div class="relative min-h-0 flex-1">
                    <VueFlow
                        v-if="editor.graph.value"
                        :nodes="vueFlowNodes"
                        :edges="vueFlowEdges"
                        :node-types="nodeTypes"
                        :nodes-draggable="true"
                        :nodes-connectable="false"
                        :default-viewport="{ x: 60, y: 40, zoom: 1 }"
                        class="bg-navy/30"
                        @node-click="onNodeClick"
                        @pane-click="onPaneClick"
                        @node-drag-stop="onNodeDragStop"
                    >
                        <Background pattern-color="#1e293b" :gap="20" />
                    </VueFlow>
                </div>

                <!-- Verification report — dry-run + declarative checks. -->
                <div
                    v-if="verifyReport"
                    class="border-t border-soft px-4 py-3 text-sm"
                >
                    <div class="mb-2 flex items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1 rounded-pill px-2 py-0.5 text-xs font-medium tracking-wider uppercase"
                            :class="
                                verifyReport.passed
                                    ? 'bg-sp-success/10 text-sp-success'
                                    : 'bg-red-400/10 text-red-300'
                            "
                        >
                            {{
                                verifyReport.passed
                                    ? t(
                                          'apps.builder.workflows.verify_passed',
                                          {
                                              n: verifyReport.assertions.filter(
                                                  (a) => a.passed,
                                              ).length,
                                              total: verifyReport.assertions
                                                  .length,
                                          },
                                      )
                                    : t('apps.builder.workflows.verify_failed')
                            }}
                        </span>
                    </div>

                    <ul class="space-y-1">
                        <li
                            v-for="(a, i) in verifyReport.assertions"
                            :key="i"
                            class="flex items-center gap-2 text-xs"
                        >
                            <Check
                                v-if="a.passed"
                                class="size-3 shrink-0 text-sp-success"
                            />
                            <X v-else class="size-3 shrink-0 text-red-300" />
                            <span class="text-ink-muted">{{ a.label }}</span>
                            <span v-if="!a.passed" class="text-ink-subtle"
                                >— {{ a.detail }}</span
                            >
                        </li>
                    </ul>

                    <div
                        v-if="verifyReport.simulated_writes.length"
                        class="mt-2 space-y-1 border-t border-soft pt-2"
                    >
                        <span
                            class="text-[10px] tracking-wider text-ink-subtle uppercase"
                            >{{
                                t('apps.builder.workflows.verify_simulated')
                            }}</span
                        >
                        <div
                            v-for="(w, i) in verifyReport.simulated_writes"
                            :key="i"
                            class="flex items-center gap-2 text-xs"
                        >
                            <span
                                class="inline-flex shrink-0 items-center rounded-pill px-1.5 py-0.5 text-[10px] font-medium tracking-wider uppercase"
                                :class="
                                    w.effect === 'write'
                                        ? 'bg-amber-400/10 text-amber-300'
                                        : 'bg-accent-blue/10 text-accent-blue'
                                "
                                >{{ w.effect }}·{{
                                    t(
                                        'apps.builder.workflows.verify_simulated_tag',
                                    )
                                }}</span
                            >
                            <span class="truncate text-ink-muted">{{
                                w.preview || describeStepRunRow(w.step_id)
                            }}</span>
                        </div>
                    </div>

                    <p
                        v-if="verifyReport.run.error"
                        class="mt-2 text-xs text-red-300"
                    >
                        {{ verifyReport.run.error }}
                    </p>
                </div>

                <!-- Approval gate — a real run halted on a non-safe write. -->
                <div
                    v-if="pendingProposals.length"
                    class="border-t border-soft px-4 py-3 text-sm"
                >
                    <div class="mb-2 flex items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1 rounded-pill bg-amber-400/10 px-2 py-0.5 text-xs font-medium tracking-wider text-amber-300 uppercase"
                        >
                            {{ t('apps.builder.workflows.approval.heading') }}
                        </span>
                    </div>

                    <div
                        v-for="p in pendingProposals"
                        :key="p.id"
                        class="mb-2 rounded-md border border-soft bg-black/20 p-2"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex shrink-0 items-center rounded-pill bg-amber-400/10 px-1.5 py-0.5 text-[10px] font-medium tracking-wider text-amber-300 uppercase"
                                >{{ p.effect }} ·🔒</span
                            >
                            <span class="truncate text-xs text-ink-muted">{{
                                p.preview ||
                                t('apps.builder.workflows.approval.untitled')
                            }}</span>
                        </div>
                        <div class="mt-2 flex gap-2">
                            <button
                                type="button"
                                :disabled="resolvingProposal === p.id"
                                @click="resolveProposal(p.id, 'approve')"
                                class="rounded-md bg-sp-success/15 px-2 py-1 text-xs font-medium text-sp-success hover:bg-sp-success/25 disabled:opacity-50"
                            >
                                {{ t('apps.builder.workflows.approval.approve') }}
                            </button>
                            <button
                                type="button"
                                :disabled="resolvingProposal === p.id"
                                @click="resolveProposal(p.id, 'dismiss')"
                                class="rounded-md border border-medium px-2 py-1 text-xs text-ink-muted hover:bg-white/5 disabled:opacity-50"
                            >
                                {{ t('apps.builder.workflows.approval.dismiss') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Run result rail. Empties when no run has been kicked yet. -->
                <div
                    v-if="lastRun"
                    class="max-h-48 shrink-0 overflow-auto border-t border-soft bg-black/20 px-3 py-2"
                >
                    <header
                        class="mb-1 flex items-center gap-2 text-xs tracking-wider text-ink-muted uppercase"
                    >
                        <span>{{
                            t('apps.builder.workflows.run_result')
                        }}</span>
                        <span
                            :class="[
                                'rounded-pill px-1.5 py-0.5 text-xs',
                                lastRun.status === 'completed'
                                    ? 'bg-emerald-400/10 text-emerald-300'
                                    : 'bg-red-400/10 text-red-300',
                            ]"
                            >{{ lastRun.status }}</span
                        >
                    </header>
                    <ol class="space-y-1">
                        <li
                            v-for="step in lastRun.steps"
                            :key="step.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <span class="w-3 text-ink-subtle"
                                >{{ step.sequence_index + 1 }}.</span
                            >
                            <span class="font-mono text-xs text-ink-muted">{{
                                step.step_type
                            }}</span>
                            <span class="truncate text-ink">{{
                                describeStepRunRow(step.step_id)
                            }}</span>
                            <span
                                :class="[
                                    'ml-auto rounded-pill px-1.5 py-0.5 text-xs',
                                    step.status === 'completed'
                                        ? 'bg-emerald-400/10 text-emerald-300'
                                        : step.status === 'failed'
                                          ? 'bg-red-400/10 text-red-300'
                                          : 'bg-surface text-ink-muted',
                                ]"
                                >{{ step.status }}</span
                            >
                        </li>
                    </ol>
                    <p v-if="lastRun.error" class="mt-2 text-xs text-red-300">
                        {{ lastRun.error }}
                    </p>
                </div>
            </section>

            <AppWorkflowNodePanel
                v-if="editor.selectedNode.value && editor.graph.value"
                :node="editor.selectedNode.value"
                :meta="editor.graph.value.meta"
                :objects="objects"
                :connector-integrations="connectorIntegrations"
                :app-id="appId"
                :workflow-id="editor.graph.value.meta.id"
                @update-node="(p) => editor.updateNodeData(p.id, p.patch)"
                @remove-node="
                    (id) => {
                        if (id === TRIGGER_NODE_ID) return;
                        editor.removeNode(id);
                    }
                "
            />
        </div>
    </div>
</template>
