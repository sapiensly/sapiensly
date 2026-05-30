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
    ManifestStep,
    ManifestWorkflow,
    WorkflowTrigger,
} from '@/types/appWorkflows';
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
}>();

const emit = defineEmits<{
    (e: 'update-node', payload: { id: string; patch: Record<string, unknown> }): void;
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
        setTimeout(() => { idJustCopied.value = false; }, 1500);
    } catch {
        // Clipboard API can be disabled (HTTP, permission policy); fall
        // back to a manual prompt so the user can still grab the id.
        // eslint-disable-next-line no-alert
        window.prompt(t('apps.builder.workflows.panel.copy_id'), stepData.value.id);
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
    const variable = (stepData.value.output_variable as string | undefined)?.trim();
    const isQuery = stepData.value.type === 'record.query';
    const suffix = isQuery ? '.rows.0.data.<campo>' : '';

    const parts: string[] = [];
    if (variable) {
        parts.push(`{{vars.${variable}${suffix}}}`);
    }
    parts.push(`{{steps.${stepId}.output${suffix}}}`);
    return parts.join('   ·   ');
});

watch(
    () => props.node.id,
    () => {
        advancedDraft.value = serializeAdvanced(props.node.data);
        advancedError.value = null;
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
const triggerData = computed<WorkflowTrigger>(() => props.node.data as WorkflowTrigger);

function patchTrigger(patch: Record<string, unknown>) {
    emit('update-node', { id: props.node.id, patch });
}

function changeTriggerType(newType: WorkflowTrigger['type']) {
    if (newType === 'manual') {
        emit('update-node', { id: props.node.id, patch: { type: 'manual', label: '' } });
    } else {
        emit('update-node', {
            id: props.node.id,
            patch: { type: newType, object_id: '' },
        });
    }
}
</script>

<template>
    <aside class="flex w-80 shrink-0 flex-col gap-3 overflow-y-auto border-l border-soft bg-navy/50 p-4">
        <!-- ============ TRIGGER NODE ============ -->
        <template v-if="isTrigger">
            <h3 class="text-xs uppercase tracking-wider text-ink-subtle">
                {{ t('apps.builder.workflows.panel.trigger_heading') }}
            </h3>

            <label class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.trigger_type') }}</span>
                <select
                    :value="triggerData.type"
                    @change="changeTriggerType(($event.target as HTMLSelectElement).value as WorkflowTrigger['type'])"
                    class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 text-sm text-ink"
                >
                    <option value="manual">{{ t('apps.builder.workflows.trigger.manual') }}</option>
                    <option value="record.created">{{ t('apps.builder.workflows.trigger.record_created') }}</option>
                    <option value="record.updated">{{ t('apps.builder.workflows.trigger.record_updated') }}</option>
                    <option value="record.deleted">{{ t('apps.builder.workflows.trigger.record_deleted') }}</option>
                </select>
            </label>

            <label v-if="triggerData.type === 'manual'" class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.trigger_label') }}</span>
                <input
                    type="text"
                    :value="triggerData.label ?? ''"
                    @input="patchTrigger({ label: ($event.target as HTMLInputElement).value })"
                    class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 text-sm text-ink"
                    :placeholder="t('apps.builder.workflows.panel.trigger_label_placeholder')"
                />
            </label>

            <label v-else class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.trigger_object_id') }}</span>
                <ObjectPicker
                    :model-value="(triggerData as { object_id?: string }).object_id ?? ''"
                    :objects="objects"
                    @update:model-value="(v: string) => patchTrigger({ object_id: v })"
                />
            </label>
        </template>

        <!-- ============ STEP NODE ============ -->
        <template v-else>
            <header class="flex items-center justify-between">
                <h3 class="text-xs uppercase tracking-wider text-ink-subtle">
                    {{ t('apps.builder.workflows.panel.step_heading') }} · {{ stepData.type }}
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
            <div class="flex items-center gap-1 rounded-md border border-soft bg-white/5 px-2 py-1.5">
                <span class="shrink-0 text-xs text-ink-subtle">ID</span>
                <code class="flex-1 truncate font-mono text-xs text-ink">{{ stepData.id }}</code>
                <button
                    type="button"
                    @click="copyStepId"
                    :title="t('apps.builder.workflows.panel.copy_id')"
                    class="shrink-0 rounded px-1 py-0.5 text-[10px] uppercase tracking-wider text-ink-muted transition-colors hover:bg-white/10 hover:text-ink"
                >
                    {{ idJustCopied ? t('apps.builder.workflows.panel.copied') : t('apps.builder.workflows.panel.copy') }}
                </button>
            </div>

            <label class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.step_name') }}</span>
                <input
                    type="text"
                    :value="stepData.name ?? ''"
                    @input="patchStep({ name: ($event.target as HTMLInputElement).value })"
                    class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 text-sm text-ink"
                    :placeholder="t('apps.builder.workflows.panel.step_name_placeholder')"
                />
            </label>

            <label class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.output_variable') }}</span>
                <input
                    type="text"
                    :value="stepData.output_variable ?? ''"
                    @input="patchStep({ output_variable: ($event.target as HTMLInputElement).value || undefined })"
                    class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 font-mono text-sm text-ink"
                    placeholder="result"
                />
                <p class="text-xs text-ink-subtle">
                    {{ t('apps.builder.workflows.panel.output_variable_hint') }}
                </p>
                <!-- Concrete, copy-pasteable reference paths. Shows the
                     real step id + (if set) the vars.* shortcut. For
                     record.query also previews the `.rows.0.data.<field>`
                     suffix users always trip on. -->
                <code class="block whitespace-pre-wrap break-all rounded bg-black/30 px-2 py-1 font-mono text-xs text-accent-blue">
                    {{ outputVariableHint }}
                </code>
            </label>

            <!-- Per-step-type quick fields. The "advanced" textarea below
                 covers anything we don't render here. -->
            <label v-if="stepData.type === 'log'" class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.log_message') }}</span>
                <textarea
                    :value="(stepData as { message?: string }).message ?? ''"
                    @input="patchStep({ message: ($event.target as HTMLTextAreaElement).value })"
                    rows="3"
                    class="w-full rounded-md border border-medium bg-white/5 px-2 py-1 text-xs text-ink"
                />
            </label>

            <template v-if="stepData.type === 'set_variable'">
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.set_variable_name') }}</span>
                    <input
                        type="text"
                        :value="(stepData as { variable?: string }).variable ?? ''"
                        @input="patchStep({ variable: ($event.target as HTMLInputElement).value })"
                        class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 font-mono text-sm text-ink"
                    />
                </label>
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.set_variable_value') }}</span>
                    <input
                        type="text"
                        :value="String((stepData as { value?: unknown }).value ?? '')"
                        @input="patchStep({ value: ($event.target as HTMLInputElement).value })"
                        class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 font-mono text-sm text-ink"
                    />
                </label>
            </template>

            <label v-if="['record.create', 'record.update', 'record.delete', 'record.query'].includes(stepData.type)" class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.object_id') }}</span>
                <ObjectPicker
                    :model-value="(stepData as { object_id?: string }).object_id ?? ''"
                    :objects="objects"
                    @update:model-value="(v: string) => patchStep({ object_id: v })"
                />
            </label>

            <label v-if="['record.update', 'record.delete'].includes(stepData.type)" class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.record_id') }}</span>
                <input
                    type="text"
                    :value="(stepData as { record_id?: string }).record_id ?? ''"
                    @input="patchStep({ record_id: ($event.target as HTMLInputElement).value })"
                    class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 font-mono text-sm text-ink"
                    placeholder="{{trigger.record.id}}"
                />
            </label>

            <label v-if="stepData.type === 'ai.complete'" class="space-y-1">
                <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.ai_prompt') }}</span>
                <textarea
                    :value="(stepData as { prompt?: string }).prompt ?? ''"
                    @input="patchStep({ prompt: ($event.target as HTMLTextAreaElement).value })"
                    rows="4"
                    class="w-full rounded-md border border-medium bg-white/5 px-2 py-1 text-xs text-ink"
                />
            </label>

            <template v-if="stepData.type === 'http.request'">
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.http_method') }}</span>
                    <select
                        :value="(stepData as { method?: string }).method ?? 'GET'"
                        @change="patchStep({ method: ($event.target as HTMLSelectElement).value })"
                        class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 text-sm text-ink"
                    >
                        <option>GET</option>
                        <option>POST</option>
                        <option>PUT</option>
                        <option>PATCH</option>
                        <option>DELETE</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm text-ink-muted">{{ t('apps.builder.workflows.panel.http_url') }}</span>
                    <input
                        type="text"
                        :value="(stepData as { url?: string }).url ?? ''"
                        @input="patchStep({ url: ($event.target as HTMLInputElement).value })"
                        class="h-9 w-full rounded-md border border-medium bg-white/5 px-2 font-mono text-sm text-ink"
                        placeholder="https://..."
                    />
                </label>
            </template>

            <div class="space-y-1 pt-2 border-t border-soft">
                <span class="text-xs uppercase tracking-wider text-ink-subtle">
                    {{ t('apps.builder.workflows.panel.advanced') }}
                </span>
                <textarea
                    v-model="advancedDraft"
                    @blur="commitAdvanced"
                    rows="6"
                    class="w-full rounded-md border border-medium bg-black/30 px-2 py-1 font-mono text-xs text-ink"
                />
                <p v-if="advancedError" class="text-xs text-red-400">{{ advancedError }}</p>
                <p class="text-xs text-ink-subtle">
                    {{ t('apps.builder.workflows.panel.advanced_hint') }}
                </p>
            </div>
        </template>
    </aside>
</template>
