<script setup lang="ts">
/**
 * Form panel that edits the selected node's payload. We keep the form
 * minimal and per-step-type: only the most important fields get an input,
 * the rest of the JSON is exposed via a raw "advanced" textarea so power
 * users (and Claude later via apply) can still tweak edge cases.
 *
 * For TRIGGER nodes the panel switches between manual / record.* configs.
 */

import ObjectPicker from '@/components/apps/workflows/ObjectPicker.vue';
import type {
    AppWorkflowNode,
    ConnectorActionContract,
    ConnectorIntegration,
    ManifestStep,
    ManifestWorkflow,
    WorkflowTrigger,
} from '@/types/appWorkflows';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface ManifestObject {
    id: string;
    slug: string;
    name: string;
}

const props = defineProps<{
    node: AppWorkflowNode;
    /** Metadata (so the panel can edit the workflow name when no node selected — but for now no-op). */
    meta: Omit<ManifestWorkflow, 'steps'>;
    /** The App's objects, used by the searchable object picker. */
    objects: ManifestObject[];
    /** Tenant integrations + their typed actions, for the connector.call pickers. */
    connectorIntegrations?: ConnectorIntegration[];
    /** App id, for fetching the webhook ingress URL + secret. */
    appId?: string;
    /** Current workflow id, for the webhook ingress URL + secret. */
    workflowId?: string;
}>();

const emit = defineEmits<{
    (
        e: 'update-node',
        payload: { id: string; patch: Record<string, unknown> },
    ): void;
    (e: 'remove-node', id: string): void;
}>();

const { t } = useI18n();

const isTrigger = computed(() => props.node.kind === 'trigger');

// ---------- Step nodes ----------
const stepData = computed<ManifestStep>(() => props.node.data as ManifestStep);

function patchStep(patch: Record<string, unknown>) {
    emit('update-node', { id: props.node.id, patch });
}

// "Advanced" raw JSON for the type-specific fields the panel doesn't have
// a dedicated input for. Keeps power-user edits working; the textarea is
// dehydrated/rehydrated only on commit to avoid wrecking the structure
// while the user is typing.
const advancedDraft = ref('');
const advancedError = ref<string | null>(null);

// "Copied!" feedback for the step-id copy button — pure UX touch.
const idJustCopied = ref(false);
async function copyStepId() {
    try {
        await navigator.clipboard.writeText(stepData.value.id);
        idJustCopied.value = true;
        setTimeout(() => {
            idJustCopied.value = false;
        }, 1500);
    } catch {
        // Clipboard API can be disabled (HTTP, permission policy); fall
        // back to a manual prompt so the user can still grab the id.
        // eslint-disable-next-line no-alert
        window.prompt(
            t('apps.builder.workflows.panel.copy_id'),
            stepData.value.id,
        );
    }
}

/**
 * Concrete usage hint for the output_variable input. Two equivalent paths
 * exist for referencing this step's result from later steps:
 *   - {{steps.<this_step_id>.output...}} — always works
 *   - {{vars.<output_variable>}} — only when output_variable is set
 * We surface both so the user picks whichever is more readable.
 *
 * Special-cases record.query: its output shape is { count, rows: [...] }
 * — the rows[0].data.field pattern trips users up otherwise.
 */
const outputVariableHint = computed(() => {
    const stepId = stepData.value.id;
    const variable = (
        stepData.value.output_variable as string | undefined
    )?.trim();
    const isQuery = stepData.value.type === 'record.query';
    const suffix = isQuery ? '.rows.0.data.<campo>' : '';

    const parts: string[] = [];
    if (variable) {
        parts.push(`{{vars.${variable}${suffix}}}`);
    }
    parts.push(`{{steps.${stepId}.output${suffix}}}`);
    return parts.join('   ·   ');
});

// ---------- connector.call ----------
const integrations = computed<ConnectorIntegration[]>(
    () => props.connectorIntegrations ?? [],
);

/** Flat lookup: tool_id → its action contract + owning integration. */
const allActions = computed(() => {
    const map = new Map<
        string,
        { action: ConnectorActionContract; integration: ConnectorIntegration }
    >();
    for (const integration of integrations.value) {
        for (const action of integration.actions) {
            map.set(action.id, { action, integration });
        }
    }
    return map;
});

const selectedAction = computed<ConnectorActionContract | undefined>(() => {
    const toolId =
        typeof stepData.value.tool_id === 'string'
            ? stepData.value.tool_id
            : '';
    return toolId ? allActions.value.get(toolId)?.action : undefined;
});

const selectedIntegration = computed<ConnectorIntegration | undefined>(() =>
    integrations.value.find((i) => i.id === connectorIntegrationId.value),
);

const actionsForIntegration = computed<ConnectorActionContract[]>(
    () => selectedIntegration.value?.actions ?? [],
);

// Local pointer to the chosen integration so the action dropdown can populate
// before an action is picked. Seeded from the current action's integration on
// node change.
const connectorIntegrationId = ref('');

function changeConnectorIntegration(id: string) {
    connectorIntegrationId.value = id;
    // Switching integration clears the action (its inputs differ).
    patchStep({ tool_id: '', inputs: {} });
}

function selectConnectorAction(actionId: string) {
    const entry = allActions.value.get(actionId);
    // Scaffold inputs to the action's declared params, preserving any
    // expressions already written for an input of the same name.
    const current = (stepData.value.inputs ?? {}) as Record<string, unknown>;
    const inputs: Record<string, unknown> = {};
    for (const input of entry?.action.inputs ?? []) {
        inputs[input.name] = current[input.name] ?? '';
    }
    patchStep({ tool_id: actionId, inputs });
}

function patchConnectorInput(name: string, value: string) {
    const current = {
        ...((stepData.value.inputs ?? {}) as Record<string, unknown>),
    };
    current[name] = value;
    patchStep({ inputs: current });
}

function connectorInputValue(name: string): string {
    const inputs = (stepData.value.inputs ?? {}) as Record<string, unknown>;
    const v = inputs[name];
    return typeof v === 'string' ? v : '';
}

watch(
    () => props.node.id,
    () => {
        advancedDraft.value = serializeAdvanced(props.node.data);
        advancedError.value = null;
        const toolId =
            typeof stepData.value.tool_id === 'string'
                ? stepData.value.tool_id
                : '';
        connectorIntegrationId.value = toolId
            ? (allActions.value.get(toolId)?.integration.id ?? '')
            : '';
    },
    { immediate: true },
);

function serializeAdvanced(data: unknown): string {
    if (!data || typeof data !== 'object') return '{}';
    const clone = { ...(data as Record<string, unknown>) };
    // Hide the keys the panel exposes natively — keeps the textarea focused
    // on the bits without first-class inputs.
    delete clone.id;
    delete clone.type;
    delete clone.name;
    delete clone.output_variable;
    delete clone.message;
    delete clone.variable;
    delete clone.value;
    delete clone.object_id;
    delete clone.record_id;
    delete clone.prompt;
    delete clone.method;
    delete clone.url;
    delete clone.tool_id;
    delete clone.inputs;
    return JSON.stringify(clone, null, 2);
}

function commitAdvanced() {
    let parsed: Record<string, unknown>;
    try {
        parsed = JSON.parse(advancedDraft.value || '{}');
    } catch (e) {
        advancedError.value = (e as Error).message;
        return;
    }
    advancedError.value = null;
    patchStep(parsed);
}

// ---------- Trigger nodes ----------
const triggerData = computed<WorkflowTrigger>(
    () => props.node.data as WorkflowTrigger,
);

function patchTrigger(patch: Record<string, unknown>) {
    emit('update-node', { id: props.node.id, patch });
}

function changeTriggerType(newType: WorkflowTrigger['type']) {
    if (newType === 'manual') {
        emit('update-node', {
            id: props.node.id,
            patch: { type: 'manual', label: '' },
        });
    } else if (newType === 'schedule') {
        emit('update-node', {
            id: props.node.id,
            patch: { type: 'schedule', cron: '0 9 * * 1-5', timezone: 'UTC' },
        });
    } else if (newType === 'webhook.inbound') {
        emit('update-node', {
            id: props.node.id,
            patch: { type: 'webhook.inbound' },
        });
    } else {
        emit('update-node', {
            id: props.node.id,
            patch: { type: newType, object_id: '' },
        });
    }
}

// ---------- Schedule trigger: cron presets + natural-language preview ----------
const CRON_PRESETS: { labelKey: string; cron: string }[] = [
    { labelKey: 'every_hour', cron: '0 * * * *' },
    { labelKey: 'daily_9am', cron: '0 9 * * *' },
    { labelKey: 'weekdays_9am', cron: '0 9 * * 1-5' },
    { labelKey: 'weekly_monday', cron: '0 9 * * 1' },
    { labelKey: 'monthly_first', cron: '0 9 1 * *' },
];

function describeCron(cron: string): string {
    const preset = CRON_PRESETS.find((p) => p.cron === cron);
    if (preset) {
        return t(`apps.builder.workflows.cron_preset.${preset.labelKey}`);
    }
    const parts = cron.trim().split(/\s+/);
    if (parts.length === 5) {
        return t('apps.builder.workflows.cron_custom', { cron });
    }
    return t('apps.builder.workflows.cron_invalid');
}

const cronPreview = computed(() =>
    describeCron((triggerData.value as { cron?: string }).cron ?? ''),
);

// ---------- Webhook trigger: lazily fetch the signed URL + secret ----------
const webhookInfo = ref<{
    url: string;
    secret: string;
    signature_header: string;
} | null>(null);
const webhookRevealed = ref(false);
const webhookCopied = ref<'url' | 'secret' | null>(null);

async function loadWebhookInfo() {
    if (!props.appId || !props.workflowId) return;
    try {
        const { data } = await axios.get(
            `/apps/${props.appId}/builder/workflows/${props.workflowId}/webhook-info`,
        );
        webhookInfo.value = data;
    } catch {
        webhookInfo.value = null;
    }
}

async function copyWebhook(which: 'url' | 'secret') {
    const value =
        which === 'url' ? webhookInfo.value?.url : webhookInfo.value?.secret;
    if (!value) return;
    await navigator.clipboard.writeText(value);
    webhookCopied.value = which;
    window.setTimeout(() => {
        webhookCopied.value = null;
    }, 1500);
}

const webhookSample = computed(() =>
    JSON.stringify({ id: 'evt_123', event: 'example', data: {} }, null, 2),
);

watch(
    () => [props.node.id, (props.node.data as WorkflowTrigger).type],
    () => {
        webhookRevealed.value = false;
        if (
            isTrigger.value &&
            (props.node.data as WorkflowTrigger).type === 'webhook.inbound'
        ) {
            loadWebhookInfo();
        }
    },
    { immediate: true },
);
</script>

<template>
    <aside
        class="flex w-80 shrink-0 flex-col gap-3 overflow-y-auto border-l border-soft bg-navy/50 p-4"
    >
        <!-- ============ TRIGGER NODE ============ -->
        <template v-if="isTrigger">
            <h3 class="text-xs tracking-wider text-ink-subtle uppercase">
                {{ t('apps.builder.workflows.panel.trigger_heading') }}
            </h3>

            <label class="space-y-1">
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.trigger_type')
                }}</span>
                <select
                    :value="triggerData.type"
                    @change="
                        changeTriggerType(
                            ($event.target as HTMLSelectElement)
                                .value as WorkflowTrigger['type'],
                        )
                    "
                    class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                >
                    <option value="manual">
                        {{ t('apps.builder.workflows.trigger.manual') }}
                    </option>
                    <option value="record.created">
                        {{ t('apps.builder.workflows.trigger.record_created') }}
                    </option>
                    <option value="record.updated">
                        {{ t('apps.builder.workflows.trigger.record_updated') }}
                    </option>
                    <option value="record.deleted">
                        {{ t('apps.builder.workflows.trigger.record_deleted') }}
                    </option>
                    <option value="schedule">
                        {{ t('apps.builder.workflows.trigger.schedule') }}
                    </option>
                    <option value="webhook.inbound">
                        {{ t('apps.builder.workflows.trigger.webhook') }}
                    </option>
                </select>
            </label>

            <label v-if="triggerData.type === 'manual'" class="space-y-1">
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.trigger_label')
                }}</span>
                <input
                    type="text"
                    :value="triggerData.label ?? ''"
                    @input="
                        patchTrigger({
                            label: ($event.target as HTMLInputElement).value,
                        })
                    "
                    class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                    :placeholder="
                        t(
                            'apps.builder.workflows.panel.trigger_label_placeholder',
                        )
                    "
                />
            </label>

            <label
                v-else-if="triggerData.type.startsWith('record.')"
                class="space-y-1"
            >
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.trigger_object_id')
                }}</span>
                <ObjectPicker
                    :model-value="
                        (triggerData as { object_id?: string }).object_id ?? ''
                    "
                    :objects="objects"
                    @update:model-value="
                        (v: string) => patchTrigger({ object_id: v })
                    "
                />
            </label>

            <!-- Schedule trigger: cron preset/custom + natural-language preview. -->
            <template v-else-if="triggerData.type === 'schedule'">
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.cron_preset')
                    }}</span>
                    <select
                        :value="
                            CRON_PRESETS.some(
                                (p) =>
                                    p.cron ===
                                    (triggerData as { cron?: string }).cron,
                            )
                                ? (triggerData as { cron?: string }).cron
                                : '__custom__'
                        "
                        @change="
                            (e) => {
                                const v = (e.target as HTMLSelectElement).value;
                                if (v !== '__custom__') patchTrigger({ cron: v });
                            }
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                    >
                        <option
                            v-for="p in CRON_PRESETS"
                            :key="p.cron"
                            :value="p.cron"
                        >
                            {{ t(`apps.builder.workflows.cron_preset.${p.labelKey}`) }}
                        </option>
                        <option value="__custom__">
                            {{ t('apps.builder.workflows.cron_preset.custom') }}
                        </option>
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.cron_expression')
                    }}</span>
                    <input
                        type="text"
                        :value="(triggerData as { cron?: string }).cron ?? ''"
                        @input="
                            patchTrigger({
                                cron: ($event.target as HTMLInputElement).value,
                            })
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 font-mono text-sm text-ink"
                        placeholder="0 9 * * 1-5"
                    />
                    <span class="text-xs text-accent-blue">{{ cronPreview }}</span>
                </label>

                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.timezone')
                    }}</span>
                    <input
                        type="text"
                        :value="
                            (triggerData as { timezone?: string }).timezone ??
                            'UTC'
                        "
                        @input="
                            patchTrigger({
                                timezone: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                        placeholder="UTC"
                    />
                </label>
            </template>

            <!-- Webhook trigger: signed URL + secret to paste into the provider. -->
            <template v-else-if="triggerData.type === 'webhook.inbound'">
                <div class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.webhook_url')
                    }}</span>
                    <div class="flex gap-1">
                        <input
                            type="text"
                            readonly
                            :value="webhookInfo?.url ?? '…'"
                            class="h-9 w-full rounded-md border border-medium bg-black/20 px-2 font-mono text-xs text-ink-muted"
                        />
                        <button
                            type="button"
                            @click="copyWebhook('url')"
                            class="shrink-0 rounded-md border border-medium px-2 text-xs text-ink-muted hover:bg-white/5"
                        >
                            {{
                                webhookCopied === 'url'
                                    ? t('apps.builder.workflows.panel.copied')
                                    : t('apps.builder.workflows.panel.copy')
                            }}
                        </button>
                    </div>
                </div>

                <div class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.webhook_secret')
                    }}</span>
                    <div class="flex gap-1">
                        <input
                            :type="webhookRevealed ? 'text' : 'password'"
                            readonly
                            :value="webhookInfo?.secret ?? ''"
                            class="h-9 w-full rounded-md border border-medium bg-black/20 px-2 font-mono text-xs text-ink-muted"
                        />
                        <button
                            type="button"
                            @click="webhookRevealed = !webhookRevealed"
                            class="shrink-0 rounded-md border border-medium px-2 text-xs text-ink-muted hover:bg-white/5"
                        >
                            {{
                                webhookRevealed
                                    ? t('apps.builder.workflows.panel.hide')
                                    : t('apps.builder.workflows.panel.reveal')
                            }}
                        </button>
                        <button
                            type="button"
                            @click="copyWebhook('secret')"
                            class="shrink-0 rounded-md border border-medium px-2 text-xs text-ink-muted hover:bg-white/5"
                        >
                            {{
                                webhookCopied === 'secret'
                                    ? t('apps.builder.workflows.panel.copied')
                                    : t('apps.builder.workflows.panel.copy')
                            }}
                        </button>
                    </div>
                    <span class="text-xs text-ink-subtle">{{
                        t('apps.builder.workflows.panel.webhook_secret_hint')
                    }}</span>
                </div>

                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.webhook_dedupe_path')
                    }}</span>
                    <input
                        type="text"
                        :value="
                            (triggerData as { dedupe_path?: string })
                                .dedupe_path ?? ''
                        "
                        @input="
                            patchTrigger({
                                dedupe_path: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 font-mono text-sm text-ink"
                        placeholder="id"
                    />
                </label>

                <div class="space-y-1">
                    <span class="text-xs tracking-wider text-ink-subtle uppercase">{{
                        t('apps.builder.workflows.panel.webhook_sample')
                    }}</span>
                    <pre
                        class="overflow-x-auto rounded-md border border-soft bg-black/20 p-2 font-mono text-[10px] text-ink-muted"
                        >{{ webhookSample }}</pre
                    >
                </div>
            </template>
        </template>

        <!-- ============ STEP NODE ============ -->
        <template v-else>
            <header class="flex items-center justify-between">
                <h3 class="text-xs tracking-wider text-ink-subtle uppercase">
                    {{ t('apps.builder.workflows.panel.step_heading') }} ·
                    {{ stepData.type }}
                </h3>
                <button
                    type="button"
                    @click="emit('remove-node', node.id)"
                    :title="t('apps.builder.workflows.panel.remove_step')"
                    class="rounded-pill px-2 py-0.5 text-xs text-red-300 transition-colors hover:bg-red-400/10"
                >
                    {{ t('apps.builder.workflows.panel.remove') }}
                </button>
            </header>

            <!-- Step id, copyable. Critical for expression-debugging — other
                 steps reference this one via {{steps.<id>.output}} and the
                 user otherwise has no way to see the real id. -->
            <div
                class="flex items-center gap-1 rounded-md border border-soft bg-surface px-2 py-1.5"
            >
                <span class="shrink-0 text-xs text-ink-subtle">ID</span>
                <code class="flex-1 truncate font-mono text-xs text-ink">{{
                    stepData.id
                }}</code>
                <button
                    type="button"
                    @click="copyStepId"
                    :title="t('apps.builder.workflows.panel.copy_id')"
                    class="shrink-0 rounded px-1 py-0.5 text-[10px] tracking-wider text-ink-muted uppercase transition-colors hover:bg-surface-hover hover:text-ink"
                >
                    {{
                        idJustCopied
                            ? t('apps.builder.workflows.panel.copied')
                            : t('apps.builder.workflows.panel.copy')
                    }}
                </button>
            </div>

            <label class="space-y-1">
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.step_name')
                }}</span>
                <input
                    type="text"
                    :value="stepData.name ?? ''"
                    @input="
                        patchStep({
                            name: ($event.target as HTMLInputElement).value,
                        })
                    "
                    class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                    :placeholder="
                        t('apps.builder.workflows.panel.step_name_placeholder')
                    "
                />
            </label>

            <label class="space-y-1">
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.output_variable')
                }}</span>
                <input
                    type="text"
                    :value="stepData.output_variable ?? ''"
                    @input="
                        patchStep({
                            output_variable:
                                ($event.target as HTMLInputElement).value ||
                                undefined,
                        })
                    "
                    class="h-9 w-full rounded-md border border-medium bg-surface px-2 font-mono text-sm text-ink"
                    placeholder="result"
                />
                <p class="text-xs text-ink-subtle">
                    {{ t('apps.builder.workflows.panel.output_variable_hint') }}
                </p>
                <!-- Concrete, copy-pasteable reference paths. Shows the
                     real step id + (if set) the vars.* shortcut. For
                     record.query also previews the `.rows.0.data.<field>`
                     suffix users always trip on. -->
                <code
                    class="block rounded bg-black/30 px-2 py-1 font-mono text-xs break-all whitespace-pre-wrap text-accent-blue"
                >
                    {{ outputVariableHint }}
                </code>
            </label>

            <!-- Per-step-type quick fields. The "advanced" textarea below
                 covers anything we don't render here. -->
            <label v-if="stepData.type === 'log'" class="space-y-1">
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.log_message')
                }}</span>
                <textarea
                    :value="(stepData as { message?: string }).message ?? ''"
                    @input="
                        patchStep({
                            message: ($event.target as HTMLTextAreaElement)
                                .value,
                        })
                    "
                    rows="3"
                    class="w-full rounded-md border border-medium bg-surface px-2 py-1 text-xs text-ink"
                />
            </label>

            <template v-if="stepData.type === 'set_variable'">
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.set_variable_name')
                    }}</span>
                    <input
                        type="text"
                        :value="
                            (stepData as { variable?: string }).variable ?? ''
                        "
                        @input="
                            patchStep({
                                variable: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 font-mono text-sm text-ink"
                    />
                </label>
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.set_variable_value')
                    }}</span>
                    <input
                        type="text"
                        :value="
                            String(
                                (stepData as { value?: unknown }).value ?? '',
                            )
                        "
                        @input="
                            patchStep({
                                value: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 font-mono text-sm text-ink"
                    />
                </label>
            </template>

            <label
                v-if="
                    [
                        'record.create',
                        'record.update',
                        'record.delete',
                        'record.query',
                    ].includes(stepData.type)
                "
                class="space-y-1"
            >
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.object_id')
                }}</span>
                <ObjectPicker
                    :model-value="
                        (stepData as { object_id?: string }).object_id ?? ''
                    "
                    :objects="objects"
                    @update:model-value="
                        (v: string) => patchStep({ object_id: v })
                    "
                />
            </label>

            <label
                v-if="
                    ['record.update', 'record.delete'].includes(stepData.type)
                "
                class="space-y-1"
            >
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.record_id')
                }}</span>
                <input
                    type="text"
                    :value="
                        (stepData as { record_id?: string }).record_id ?? ''
                    "
                    @input="
                        patchStep({
                            record_id: ($event.target as HTMLInputElement)
                                .value,
                        })
                    "
                    class="h-9 w-full rounded-md border border-medium bg-surface px-2 font-mono text-sm text-ink"
                    placeholder="{{trigger.record.id}}"
                />
            </label>

            <label v-if="stepData.type === 'ai.complete'" class="space-y-1">
                <span class="text-sm text-ink-muted">{{
                    t('apps.builder.workflows.panel.ai_prompt')
                }}</span>
                <textarea
                    :value="(stepData as { prompt?: string }).prompt ?? ''"
                    @input="
                        patchStep({
                            prompt: ($event.target as HTMLTextAreaElement)
                                .value,
                        })
                    "
                    rows="4"
                    class="w-full rounded-md border border-medium bg-surface px-2 py-1 text-xs text-ink"
                />
            </label>

            <template v-if="stepData.type === 'http.request'">
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.http_method')
                    }}</span>
                    <select
                        :value="
                            (stepData as { method?: string }).method ?? 'GET'
                        "
                        @change="
                            patchStep({
                                method: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                    >
                        <option>GET</option>
                        <option>POST</option>
                        <option>PUT</option>
                        <option>PATCH</option>
                        <option>DELETE</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.http_url')
                    }}</span>
                    <input
                        type="text"
                        :value="(stepData as { url?: string }).url ?? ''"
                        @input="
                            patchStep({
                                url: ($event.target as HTMLInputElement).value,
                            })
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 font-mono text-sm text-ink"
                        placeholder="https://..."
                    />
                </label>
            </template>

            <template v-if="stepData.type === 'connector.call'">
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.connector_integration')
                    }}</span>
                    <select
                        :value="connectorIntegrationId"
                        @change="
                            changeConnectorIntegration(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                    >
                        <option value="">
                            {{
                                t(
                                    'apps.builder.workflows.panel.connector_pick_integration',
                                )
                            }}
                        </option>
                        <option
                            v-for="i in integrations"
                            :key="i.id"
                            :value="i.id"
                        >
                            {{ i.name }}
                        </option>
                    </select>
                </label>

                <p
                    v-if="integrations.length === 0"
                    class="text-xs text-ink-subtle"
                >
                    {{ t('apps.builder.workflows.panel.connector_none') }}
                </p>

                <p
                    v-if="
                        selectedIntegration && !selectedIntegration.authorized
                    "
                    class="rounded-md border border-amber-400/30 bg-amber-400/10 px-2 py-1.5 text-xs text-amber-300"
                >
                    {{
                        t('apps.builder.workflows.panel.connector_unauthorized')
                    }}
                </p>

                <label v-if="connectorIntegrationId" class="space-y-1">
                    <span class="text-sm text-ink-muted">{{
                        t('apps.builder.workflows.panel.connector_action')
                    }}</span>
                    <select
                        :value="
                            (stepData as { tool_id?: string }).tool_id ?? ''
                        "
                        @change="
                            selectConnectorAction(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                        class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                    >
                        <option value="">
                            {{
                                t(
                                    'apps.builder.workflows.panel.connector_pick_action',
                                )
                            }}
                        </option>
                        <option
                            v-for="a in actionsForIntegration"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.name }}
                        </option>
                    </select>
                </label>

                <!-- Effect ribbon + blast radius for the chosen action — same
                     read/write grammar as the node card and (later) the
                     approval gate. -->
                <div
                    v-if="selectedAction"
                    class="space-y-1.5 rounded-md border border-soft bg-surface px-2 py-2"
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1 rounded-pill px-1.5 py-0.5 text-[10px] font-medium tracking-wider uppercase"
                            :class="
                                selectedAction.effect === 'write'
                                    ? 'bg-amber-400/10 text-amber-300'
                                    : 'bg-accent-blue/10 text-accent-blue'
                            "
                        >
                            {{
                                selectedAction.effect === 'write'
                                    ? t(
                                          'apps.builder.workflows.connector.effect_write',
                                      )
                                    : t(
                                          'apps.builder.workflows.connector.effect_read',
                                      )
                            }}
                        </span>
                        <span
                            v-if="
                                selectedAction.effect === 'write' &&
                                !selectedAction.safe
                            "
                            class="text-[10px] tracking-wider text-ink-subtle uppercase"
                        >
                            {{ t('apps.builder.workflows.connector.gated') }}
                        </span>
                    </div>
                    <p class="text-xs text-ink-muted">
                        {{ selectedAction.blast_radius }}
                    </p>
                </div>

                <!-- Input mapping: one row per declared action input. -->
                <div
                    v-if="selectedAction && selectedAction.inputs.length"
                    class="space-y-2"
                >
                    <span
                        class="text-xs tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('apps.builder.workflows.panel.connector_inputs') }}
                    </span>
                    <label
                        v-for="input in selectedAction.inputs"
                        :key="input.name"
                        class="block space-y-1"
                    >
                        <span class="text-sm text-ink-muted">
                            {{ input.name }}
                            <span v-if="input.required" class="text-red-300"
                                >*</span
                            >
                            <span class="text-ink-subtle"
                                >· {{ input.type }}</span
                            >
                        </span>
                        <input
                            type="text"
                            :value="connectorInputValue(input.name)"
                            @input="
                                patchConnectorInput(
                                    input.name,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                            class="h-9 w-full rounded-md border border-medium bg-surface px-2 font-mono text-sm text-ink"
                            placeholder="{{trigger.record.id}}"
                        />
                    </label>
                </div>
                <p
                    v-else-if="selectedAction && !selectedAction.typed"
                    class="text-xs text-ink-subtle"
                >
                    {{ t('apps.builder.workflows.panel.connector_untyped') }}
                </p>
            </template>

            <div class="space-y-1 border-t border-soft pt-2">
                <span class="text-xs tracking-wider text-ink-subtle uppercase">
                    {{ t('apps.builder.workflows.panel.advanced') }}
                </span>
                <textarea
                    v-model="advancedDraft"
                    @blur="commitAdvanced"
                    rows="6"
                    class="w-full rounded-md border border-medium bg-black/30 px-2 py-1 font-mono text-xs text-ink"
                />
                <p v-if="advancedError" class="text-xs text-red-400">
                    {{ advancedError }}
                </p>
                <p class="text-xs text-ink-subtle">
                    {{ t('apps.builder.workflows.panel.advanced_hint') }}
                </p>
            </div>
        </template>
    </aside>
</template>
