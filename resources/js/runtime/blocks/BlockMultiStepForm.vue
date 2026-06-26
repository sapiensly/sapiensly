<script setup lang="ts">
import { Check, ChevronLeft } from '@lucide/vue';
import { computed, inject, ref, type Ref } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';
import FormFieldInput from './FormFieldInput.vue';
import { initialFieldValue } from './formFieldDefault';
import { evaluateFieldCondition, type FieldCondition } from './fieldCondition';

interface StepFieldConfig {
    field_id: string;
    label_override?: string;
    default_expression?: string;
    readonly_expression?: string;
    visible_if?: FieldCondition;
    required_if?: FieldCondition;
}

interface Step {
    id: string;
    title: string;
    description?: string;
    fields: StepFieldConfig[];
}

interface MultiStepFormBlock {
    id: string;
    type: 'multi_step_form';
    object_id: string;
    mode: 'create' | 'edit';
    record_id_expression?: string;
    steps: Step[];
    show_progress?: boolean;
    submit_label?: string;
    cancel_label?: string;
    on_submit?: RuntimeAction[];
    on_cancel?: RuntimeAction[];
}

/** Server-pre-resolved form payload (default_expression / readonly_expression). */
interface FormData {
    form?: {
        defaults?: Record<string, unknown>;
        readonly?: Record<string, boolean>;
    };
}

const props = defineProps<{
    block: MultiStepFormBlock;
    data?: FormData;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const theme = useRuntimeTheme();
const t = themeTokens(theme);
const { execute } = useActionExecutor();

const appSlug = inject<string>('appSlug', deriveSlugFromUrl());
function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/r\/([a-z][a-z0-9_]*)/);
    return m?.[1] ?? '';
}

// Forwarded as `params` at submit time so a modal-hosted multi_step_form
// can resolve {{params.record_id}} on its edit-mode record_id_expression.
const modalParams = inject<Ref<Record<string, unknown>> | null>('modalParams', null);

// Drop the card chrome when the form lives inside a modal (same reasoning
// as BlockForm).
const insideModal = inject<boolean>('insideModal', false);
const wrapperClass = computed(() =>
    insideModal
        ? ['space-y-5']
        : ['rounded-sp-sm border p-5 space-y-5', t.surface],
);

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.object_id),
);

interface RenderedField {
    fieldId: string;
    slug: string;
    label: string;
    type: FieldDef['type'];
    field: FieldDef;
    required: boolean;
    readonly: boolean;
    visible_if?: FieldCondition;
    required_if?: FieldCondition;
}

/** Resolve a field_id to its slug, for condition evaluation. */
function slugForFieldId(fieldId: string): string | undefined {
    return object.value?.fields.find((f) => f.id === fieldId)?.slug;
}

const readonlyMap = computed<Record<string, boolean>>(() => props.data?.form?.readonly ?? {});

/** Flatten a single step into the field descriptors the renderer uses. */
function fieldsForStep(step: Step): RenderedField[] {
    if (!object.value) return [];
    return step.fields
        .map((sf) => {
            const field = object.value!.fields.find((f) => f.id === sf.field_id);
            if (!field) return null;
            return {
                fieldId: sf.field_id,
                slug: field.slug,
                label: sf.label_override ?? field.name,
                type: field.type,
                field,
                required: Boolean((field as unknown as { required?: boolean }).required),
                readonly: Boolean(readonlyMap.value[field.slug]),
                visible_if: sf.visible_if,
                required_if: sf.required_if,
            };
        })
        .filter((f): f is RenderedField => f !== null);
}

const allFields = computed<RenderedField[]>(() =>
    props.block.steps.flatMap((s) => fieldsForStep(s)),
);

const currentStepIndex = ref(0);
const currentStep = computed(() => props.block.steps[currentStepIndex.value]);
const currentStepFields = computed(() => fieldsForStep(currentStep.value).filter(isVisible));
const isLastStep = computed(() => currentStepIndex.value === props.block.steps.length - 1);
const isFirstStep = computed(() => currentStepIndex.value === 0);

const formData = ref<Record<string, unknown>>(initialState());
const fieldErrors = ref<Record<string, string[]>>({});
const stepError = ref<string | null>(null);
const submitting = ref(false);

function initialState(): Record<string, unknown> {
    const state: Record<string, unknown> = {};
    const defaults = props.data?.form?.defaults ?? {};
    for (const f of allFields.value) {
        state[f.slug] = f.slug in defaults ? defaults[f.slug] : initialFieldValue(f.field);
    }
    return state;
}

/** Whether a field passes its visible_if condition (always visible when none set). */
function isVisible(rf: RenderedField): boolean {
    return rf.visible_if ? evaluateFieldCondition(rf.visible_if, formData.value, slugForFieldId) : true;
}

/** Effective required: the field's own flag OR a satisfied required_if condition. */
function isRequired(rf: RenderedField): boolean {
    if (rf.required) return true;
    return rf.required_if ? evaluateFieldCondition(rf.required_if, formData.value, slugForFieldId) : false;
}

const showProgress = computed(() => props.block.show_progress !== false);

/**
 * Local pre-flight before moving to the next step: required fields in the
 * current step must be filled. The backend re-validates on final submit,
 * but catching this up front means the user doesn't reach the last step
 * only to be bounced back.
 */
function canAdvance(): boolean {
    const missing: string[] = [];
    for (const f of currentStepFields.value) {
        if (!isRequired(f)) continue;
        const v = formData.value[f.slug];
        const empty = v === null
            || v === undefined
            || v === ''
            || (Array.isArray(v) && v.length === 0)
            || (typeof v === 'object' && v !== null && 'from' in v && (v as { from?: string; to?: string }).from === '' && (v as { from?: string; to?: string }).to === '');
        if (empty) missing.push(f.label);
    }
    if (missing.length > 0) {
        stepError.value = `Complete required fields: ${missing.join(', ')}.`;
        return false;
    }
    stepError.value = null;
    return true;
}

function next() {
    if (!canAdvance()) return;
    if (currentStepIndex.value < props.block.steps.length - 1) {
        currentStepIndex.value++;
    }
}

function back() {
    if (currentStepIndex.value > 0) {
        currentStepIndex.value--;
        stepError.value = null;
    }
}

async function submit() {
    if (submitting.value) return;
    if (!canAdvance()) return;
    submitting.value = true;
    fieldErrors.value = {};

    // Submit only currently-visible fields, so a conditionally-hidden field
    // never writes a stale value.
    const visibleSlugs = new Set(allFields.value.filter(isVisible).map((f) => f.slug));
    const payload: Record<string, unknown> = {};
    for (const [slug, value] of Object.entries(formData.value)) {
        if (visibleSlugs.has(slug)) payload[slug] = value;
    }

    const result = await execute(
        (props.block.on_submit ?? []) as RuntimeAction[],
        { appSlug, form: payload, params: modalParams?.value ?? {} },
    );

    if (!result.ok && result.fieldErrors) {
        fieldErrors.value = result.fieldErrors;
        // Jump back to the first step that has an error so the user sees it.
        for (let i = 0; i < props.block.steps.length; i++) {
            const fields = fieldsForStep(props.block.steps[i]);
            if (fields.some((f) => fieldErrors.value[f.slug]?.length)) {
                currentStepIndex.value = i;
                break;
            }
        }
    }

    submitting.value = false;
}

async function cancel() {
    await execute(
        (props.block.on_cancel ?? []) as RuntimeAction[],
        { appSlug, form: { ...formData.value }, params: modalParams?.value ?? {} },
    );
}
</script>

<template>
    <form
        :class="wrapperClass"
        @submit.prevent="submit"
    >
        <!-- Progress indicator: clickable bubbles for steps the user has already passed. -->
        <ol v-if="showProgress" class="flex flex-wrap items-center gap-2">
            <li
                v-for="(step, idx) in block.steps"
                :key="step.id"
                class="flex items-center gap-2"
            >
                <button
                    type="button"
                    @click="idx < currentStepIndex ? currentStepIndex = idx : null"
                    :disabled="idx > currentStepIndex"
                    :class="[
                        'flex items-center gap-2 rounded-pill px-2.5 py-1 text-[11px] transition-colors',
                        idx === currentStepIndex
                            ? 'bg-accent-blue/15 text-accent-blue'
                            : idx < currentStepIndex
                                ? 'text-ink-muted hover:text-ink'
                                : 'text-ink-subtle opacity-50',
                    ]"
                >
                    <span
                        :class="[
                            'flex size-5 items-center justify-center rounded-full text-[10px] font-semibold',
                            idx === currentStepIndex
                                ? 'bg-accent-blue text-white'
                                : idx < currentStepIndex
                                    ? 'bg-accent-blue/20 text-accent-blue'
                                    : 'bg-surface-hover text-ink-subtle',
                        ]"
                    >
                        <Check v-if="idx < currentStepIndex" class="size-3" />
                        <template v-else>{{ idx + 1 }}</template>
                    </span>
                    {{ step.title }}
                </button>
                <span
                    v-if="idx < block.steps.length - 1"
                    :class="['text-ink-subtle', idx < currentStepIndex ? 'opacity-100' : 'opacity-30']"
                >·</span>
            </li>
        </ol>

        <!-- Step header. -->
        <header v-if="currentStep" class="space-y-1">
            <h3 :class="['text-sm font-semibold', t.text]">{{ currentStep.title }}</h3>
            <p v-if="currentStep.description" :class="['text-xs', t.textMuted]">{{ currentStep.description }}</p>
        </header>

        <!-- Fields of the current step. -->
        <div class="space-y-4">
            <div v-for="rf in currentStepFields" :key="rf.fieldId" class="space-y-1.5">
                <label :for="`msf_${block.id}_${rf.slug}`" :class="['text-xs', t.textMuted]">
                    {{ rf.label }}
                    <span v-if="isRequired(rf)" class="text-red-400">*</span>
                </label>

                <div :inert="rf.readonly" :class="rf.readonly ? 'opacity-60' : ''">
                    <FormFieldInput
                        :field="rf.field"
                        :input-id="`msf_${block.id}_${rf.slug}`"
                        v-model="formData[rf.slug]"
                        :app-slug="appSlug"
                    />
                </div>

                <p v-for="msg in fieldErrors[rf.slug] ?? []" :key="msg" class="text-[11px] text-red-400">
                    {{ msg }}
                </p>
            </div>
        </div>

        <p v-if="stepError" class="text-[11px] text-amber-400">{{ stepError }}</p>

        <!-- Navigation. -->
        <div class="flex items-center justify-between gap-2 pt-2">
            <button
                v-if="!isFirstStep"
                type="button"
                @click="back"
                :class="['inline-flex items-center gap-1 rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs transition-colors hover:bg-surface-hover', t.text]"
            >
                <ChevronLeft class="size-3" />
                Back
            </button>
            <span v-else></span>

            <div class="flex items-center gap-2">
                <button
                    v-if="block.on_cancel && block.on_cancel.length > 0"
                    type="button"
                    @click="cancel"
                    :class="['inline-flex items-center rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs transition-colors hover:bg-surface-hover', t.text]"
                >
                    {{ block.cancel_label ?? 'Cancel' }}
                </button>
                <button
                    v-if="!isLastStep"
                    type="button"
                    @click="next"
                    class="inline-flex items-center rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white transition-colors hover:bg-accent-blue-hover"
                >
                    Next
                </button>
                <button
                    v-else
                    type="submit"
                    :disabled="submitting"
                    class="inline-flex items-center rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                >
                    {{ block.submit_label ?? 'Save' }}
                </button>
            </div>
        </div>
    </form>
</template>
