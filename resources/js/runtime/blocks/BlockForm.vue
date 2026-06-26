<script setup lang="ts">
import { computed, inject, ref, type Ref } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';
import FormFieldInput from './FormFieldInput.vue';
import { initialFieldValue } from './formFieldDefault';
import { evaluateFieldCondition, type FieldCondition } from './fieldCondition';

interface FormFieldConfig {
    field_id: string;
    label_override?: string;
    default_expression?: string;
    readonly_expression?: string;
    visible_if?: FieldCondition;
    required_if?: FieldCondition;
}

interface FormBlock {
    id: string;
    type: 'form';
    object_id: string;
    mode: 'create' | 'edit';
    record_id_expression?: string;
    fields?: FormFieldConfig[];
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
    block: FormBlock;
    data?: FormData;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const theme = useRuntimeTheme();
const t = themeTokens(theme);
const { execute } = useActionExecutor();

// App slug travels via inject from the runtime page; falls back to URL parsing.
const appSlug = inject<string>('appSlug', deriveSlugFromUrl());
function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/r\/([a-z][a-z0-9_]*)/);
    return m?.[1] ?? '';
}

// When the form is mounted inside a modal opened with params (e.g.
// open_modal {modal_block_id, params:{record_id:'{{row.id}}'}}), the modal
// provides those params here. We forward them as `params` in the submit so
// the form's record_id_expression can resolve {{params.record_id}} against
// the row that triggered the open.
const modalParams = inject<Ref<Record<string, unknown>> | null>('modalParams', null);

// When rendered inside a modal, drop the form's own card chrome — the modal
// already supplies the border + background + padding. Without this the user
// sees a "card inside a card" with an awkward gap between the two surfaces.
const insideModal = inject<boolean>('insideModal', false);
const wrapperClass = computed(() =>
    insideModal
        ? ['space-y-4']
        : ['rounded-sp-sm border p-5 space-y-4', t.surface],
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

const renderedFields = computed<RenderedField[]>(() => {
    if (!object.value) return [];
    const formFields = props.block.fields ?? object.value.fields.map((f) => ({ field_id: f.id }));
    return formFields
        .map((ff) => {
            const field = object.value!.fields.find((f) => f.id === ff.field_id);
            if (!field) return null;
            return {
                fieldId: ff.field_id,
                slug: field.slug,
                label: ff.label_override ?? field.name,
                type: field.type,
                field,
                required: Boolean((field as unknown as { required?: boolean }).required),
                readonly: Boolean(readonlyMap.value[field.slug]),
                visible_if: ff.visible_if,
                required_if: ff.required_if,
            };
        })
        .filter((f): f is RenderedField => f !== null);
});

const formData = ref<Record<string, unknown>>(initialState());
const fieldErrors = ref<Record<string, string[]>>({});
const submitting = ref(false);

function initialState(): Record<string, unknown> {
    const state: Record<string, unknown> = {};
    const defaults = props.data?.form?.defaults ?? {};
    for (const f of renderedFields.value) {
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

const visibleFields = computed<RenderedField[]>(() => renderedFields.value.filter(isVisible));

function isEmpty(value: unknown): boolean {
    return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
}

async function submit() {
    if (submitting.value) return;
    fieldErrors.value = {};

    // Enforce required_if (and static required) for VISIBLE fields only — a
    // hidden field's condition is moot and its value is not submitted.
    const missing: Record<string, string[]> = {};
    for (const rf of visibleFields.value) {
        if (isRequired(rf) && isEmpty(formData.value[rf.slug])) {
            missing[rf.slug] = ['This field is required.'];
        }
    }
    if (Object.keys(missing).length > 0) {
        fieldErrors.value = missing;
        return;
    }

    submitting.value = true;

    // Only submit values for currently-visible fields; hidden fields are
    // dropped so a conditionally-hidden field never writes a stale value.
    const visibleSlugs = new Set(visibleFields.value.map((f) => f.slug));
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
        <div v-for="rf in visibleFields" :key="rf.fieldId" class="space-y-1.5">
            <label :for="`form_${block.id}_${rf.slug}`" :class="['text-xs', t.textMuted]">
                {{ rf.label }}
                <span v-if="isRequired(rf)" class="text-red-400">*</span>
            </label>

            <div :inert="rf.readonly" :class="rf.readonly ? 'opacity-60' : ''">
                <FormFieldInput
                    :field="rf.field"
                    :input-id="`form_${block.id}_${rf.slug}`"
                    v-model="formData[rf.slug]"
                    :app-slug="appSlug"
                />
            </div>

            <p v-for="msg in fieldErrors[rf.slug] ?? []" :key="msg" class="text-[11px] text-red-400">
                {{ msg }}
            </p>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <button
                v-if="block.on_cancel && block.on_cancel.length > 0"
                type="button"
                @click="cancel"
                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs transition-colors hover:bg-surface-hover"
                :class="t.text"
            >
                {{ block.cancel_label ?? 'Cancel' }}
            </button>
            <button
                type="submit"
                :disabled="submitting"
                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
            >
                {{ block.submit_label ?? 'Save' }}
            </button>
        </div>
    </form>
</template>
