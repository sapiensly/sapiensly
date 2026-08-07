<script setup lang="ts">
import { Mic, Sparkles } from '@lucide/vue';
import { computed, inject, ref, type Ref } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';
import { useFileUpload } from '../useFileUpload';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { useRuntimeWrite } from '../useRuntimeWrite';
import { runtimeWord } from '../words';
import FormFieldInput from './FormFieldInput.vue';
import VoiceRecorder from './VoiceRecorder.vue';
import { evaluateFieldCondition, type FieldCondition } from './fieldCondition';
import { initialFieldValue } from './formFieldDefault';

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
    fill_from_document?: boolean;
    fill_from_voice?: boolean;
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
    const m = window.location.pathname.match(/^\/[ra]\/([a-z0-9][a-z0-9_-]*)/);
    return m?.[1] ?? '';
}

// When the form is mounted inside a modal opened with params (e.g.
// open_modal {modal_block_id, params:{record_id:'{{row.id}}'}}), the modal
// provides those params here. We forward them as `params` in the submit so
// the form's record_id_expression can resolve {{params.record_id}} against
// the row that triggered the open.
const modalParams = inject<Ref<Record<string, unknown>> | null>(
    'modalParams',
    null,
);

// The PAGE's params — its URL query — are the other half of {{params.*}}. A
// detail page reads {{params.id}} in its record_detail, and its "add a child"
// form writes the same token into on_submit (create_record values.parent =
// "{{params.id}}"). Forwarding only the modal's params left that resolving to
// nothing, so every child created from a detail page was saved with a NULL
// parent — silently, since the create itself succeeds.
const pageParams = inject<Record<string, unknown>>('pageParams', {});

// The modal's params win on a key collision: they name the exact row that
// opened this form, which is more specific than the page it sits on.
const submitParams = computed(() => ({
    ...pageParams,
    ...(modalParams?.value ?? {}),
}));

// When rendered inside a modal, drop the form's own card chrome — the modal
// already supplies the border + background + padding. Without this the user
// sees a "card inside a card" with an awkward gap between the two surfaces.
const insideModal = inject<boolean>('insideModal', false);
/**
 * Types that need the width of the whole form: a paragraph, a document, a file
 * drop, a picker that lists records by name. Squeezed into half a row they
 * either wrap badly or truncate the very thing being chosen.
 */
const WIDE_FIELD_TYPES = [
    'long_text',
    'rich_text',
    'file',
    'relation',
    'multi_select',
];

function spansBothColumns(rf: { field: { type: string } }): boolean {
    return WIDE_FIELD_TYPES.includes(rf.field.type);
}

/**
 * One column, or two.
 *
 * A thirteen-field create form in a modal was one column thirteen rows tall —
 * taller than the screen, so the submit button lived below the fold and the
 * shape of what was being asked was invisible. Past a handful of fields a
 * second column halves that, and collapses back to one where there is no room
 * for two.
 */
const gridClass = computed(() =>
    visibleFields.value.length > 5
        ? 'grid grid-cols-1 gap-x-4 gap-y-4 md:grid-cols-2'
        : 'space-y-4',
);

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

const readonlyMap = computed<Record<string, boolean>>(
    () => props.data?.form?.readonly ?? {},
);

const renderedFields = computed<RenderedField[]>(() => {
    if (!object.value) return [];
    const formFields: FormFieldConfig[] =
        props.block.fields ??
        object.value.fields.map((f): FormFieldConfig => ({ field_id: f.id }));
    return formFields
        .map((ff): RenderedField | null => {
            const field = object.value!.fields.find(
                (f) => f.id === ff.field_id,
            );
            if (!field) return null;
            return {
                fieldId: ff.field_id,
                slug: field.slug,
                label: ff.label_override ?? field.name,
                type: field.type,
                field,
                required: Boolean(
                    (field as unknown as { required?: boolean }).required,
                ),
                readonly: Boolean(readonlyMap.value[field.slug]),
                visible_if: ff.visible_if,
                required_if: ff.required_if,
            };
        })
        .filter((f): f is RenderedField => f !== null);
});

/**
 * The record an edit form opened on, when the modal that opened it carried one.
 *
 * The server seeds `data.form.defaults` whenever the record id is knowable at
 * render time, but a modal opened from a table row only learns its id on the
 * click — so the row hands its own values over in the open_modal params. Those
 * values already reached this browser to be drawn in the table, and the role's
 * read-hidden fields were stripped before they did.
 */
const modalRecord = computed<Record<string, unknown>>(() => {
    const record = modalParams?.value?.record;
    return record !== null &&
        typeof record === 'object' &&
        !Array.isArray(record)
        ? (record as Record<string, unknown>)
        : {};
});

const formData = ref<Record<string, unknown>>(initialState());

/**
 * Filling the form from a photograph of the thing it is about.
 *
 * It fills, and never saves. The model read a crumpled receipt in bad light,
 * and the person holding it is the one who knows whether it says 1,250 or
 * 7,250 — so what arrives is a filled form they check, not a record they never
 * saw. Only fields the document actually stated come back; the rest stay empty
 * on purpose, because somebody proof-reads what is filled in and not what is
 * missing.
 */
const extracting = ref(false);
const extractError = ref<string | null>(null);
const extractInput = ref<HTMLInputElement | null>(null);
const { upload } = useFileUpload(appSlug);
const { write } = useRuntimeWrite();

async function onDocumentChosen(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) await fillFrom(file);
}

/**
 * Dictating it instead. Same journey from here: upload, read, merge — the
 * server decides that audio needs transcribing first, because only it knows
 * which models are configured.
 */
const recording = ref(false);
const heard = ref<string | null>(null);

async function onRecorded(blob: Blob): Promise<void> {
    recording.value = false;
    await fillFrom(
        new File([blob], 'nota.webm', { type: blob.type || 'audio/webm' }),
    );
}

async function fillFrom(file: File): Promise<void> {
    if (!object.value) return;

    extracting.value = true;
    extractError.value = null;
    heard.value = null;

    const uploaded = await upload(file);
    if (uploaded === null) {
        extracting.value = false;
        extractError.value = runtimeWord(props.locale, 'fill_failed');

        return;
    }

    const result = await write<{
        values?: Record<string, unknown>;
        transcript?: string | null;
        error?: string | null;
    }>(`/r/${appSlug}/objects/${object.value.slug}/extract`, {
        file_id: uploaded.file_id,
    });

    extracting.value = false;

    if (!result.ok || result.data?.error) {
        extractError.value = runtimeWord(props.locale, 'fill_failed');

        return;
    }

    // What was heard, shown beside the result. A wrong field with no
    // transcript next to it is a mystery; with one it is obvious.
    const transcript = result.data?.transcript;
    if (typeof transcript === 'string' && transcript !== '') {
        heard.value = runtimeWord(props.locale, 'voice_heard', {
            text: transcript.slice(0, 240),
        });
    }

    const values = result.data?.values ?? {};
    if (Object.keys(values).length === 0) {
        extractError.value = runtimeWord(props.locale, 'fill_nothing');

        return;
    }

    // Merged, not replaced: whatever somebody already typed is theirs, and a
    // model is not entitled to overwrite it.
    for (const [slug, value] of Object.entries(values)) {
        if (
            formData.value[slug] === undefined ||
            formData.value[slug] === null ||
            formData.value[slug] === ''
        ) {
            formData.value[slug] = value;
        }
    }
}
// What the form opened with. An edit submits the DIFFERENCE against this, so
// keep it as the pristine copy — never the same object as formData.
const pristine = ref<Record<string, unknown>>({ ...formData.value });
const fieldErrors = ref<Record<string, string[]>>({});

/**
 * The one line under a field: its error, or failing that its help text.
 *
 * The error WINS rather than joining the help, so a field never says two things
 * at once — and so the line does not change height when validation fails on a
 * field that already had something to say.
 */
function fieldNote(rf: { slug: string; field: { help_text?: string } }): string {
    return (fieldErrors.value[rf.slug] ?? [])[0] ?? rf.field.help_text ?? '';
}
const submitting = ref(false);

function initialState(): Record<string, unknown> {
    const state: Record<string, unknown> = {};
    const seed =
        props.block.mode === 'edit'
            ? { ...modalRecord.value, ...(props.data?.form?.defaults ?? {}) }
            : (props.data?.form?.defaults ?? {});
    for (const f of renderedFields.value) {
        state[f.slug] =
            f.slug in seed ? seed[f.slug] : initialFieldValue(f.field);
    }
    return state;
}

/** Whether a field passes its visible_if condition (always visible when none set). */
function isVisible(rf: RenderedField): boolean {
    return rf.visible_if
        ? evaluateFieldCondition(rf.visible_if, formData.value, slugForFieldId)
        : true;
}

/** Effective required: the field's own flag OR a satisfied required_if condition. */
function isRequired(rf: RenderedField): boolean {
    if (rf.required) return true;
    return rf.required_if
        ? evaluateFieldCondition(rf.required_if, formData.value, slugForFieldId)
        : false;
}

const visibleFields = computed<RenderedField[]>(() =>
    renderedFields.value.filter(isVisible),
);

function isEmpty(value: unknown): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

/**
 * Whether a field still holds what it opened with — the test that decides
 * whether an edit sends it at all.
 *
 * Two empties count as equal: a stored null reaches an input that binds it as
 * '', and calling that a change would post an empty write for every untouched
 * optional field, which is exactly the noise this is here to avoid. Emptying a
 * field that HELD something is a real change and still travels. Structured
 * values (multi-selects, date ranges) compare by shape.
 */
function isUnchanged(value: unknown, before: unknown): boolean {
    if (isEmpty(value) && isEmpty(before)) return true;
    return JSON.stringify(value) === JSON.stringify(before);
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
    //
    // An EDIT submits only what the user actually changed. update_record merges
    // (it touches the keys it is sent and leaves the rest), so sending the whole
    // form would rewrite every field with whatever this browser happened to hold
    // — blanking anything it never loaded, and clobbering a colleague's
    // concurrent edit to a field this user never looked at. Clearing a field on
    // purpose still travels: it changed.
    const visibleSlugs = new Set(visibleFields.value.map((f) => f.slug));
    const editing = props.block.mode === 'edit';
    const payload: Record<string, unknown> = {};
    for (const [slug, value] of Object.entries(formData.value)) {
        if (!visibleSlugs.has(slug)) continue;
        if (editing && isUnchanged(value, pristine.value[slug])) continue;
        payload[slug] = value;
    }

    const result = await execute(
        (props.block.on_submit ?? []) as RuntimeAction[],
        { appSlug, form: payload, params: submitParams.value },
    );

    if (!result.ok && result.fieldErrors) {
        fieldErrors.value = result.fieldErrors;
    }

    submitting.value = false;
}

async function cancel() {
    await execute((props.block.on_cancel ?? []) as RuntimeAction[], {
        appSlug,
        form: { ...formData.value },
        params: submitParams.value,
    });
}
</script>

<template>
    <form :class="wrapperClass" @submit.prevent="submit">
        <!-- Above the fields, because it is a way of STARTING the form rather
             than a step in it. It fills; the person checks and saves. -->
        <div
            v-if="block.fill_from_document || block.fill_from_voice"
            class="mb-3 flex flex-wrap items-center gap-2"
        >
            <button
                v-if="block.fill_from_document"
                type="button"
                data-sp-fill-doc
                :disabled="extracting"
                :class="[
                    'inline-flex h-9 items-center gap-1.5 rounded-md border px-2.5 text-xs transition-colors hover:bg-surface-hover disabled:opacity-50',
                    t.surfaceMuted,
                    t.textMuted,
                ]"
                @click="extractInput?.click()"
            >
                <Sparkles class="size-3.5" />
                {{
                    runtimeWord(
                        locale,
                        extracting ? 'fill_reading' : 'fill_from_document',
                    )
                }}
            </button>
            <button
                v-if="block.fill_from_voice"
                type="button"
                data-sp-fill-voice
                :disabled="extracting"
                :class="[
                    'inline-flex h-9 items-center gap-1.5 rounded-md border px-2.5 text-xs transition-colors hover:bg-surface-hover disabled:opacity-50',
                    t.surfaceMuted,
                    t.textMuted,
                ]"
                @click="recording = true"
            >
                <Mic class="size-3.5" />
                {{ runtimeWord(locale, 'fill_from_voice') }}
            </button>
            <span :class="['text-[10px]', t.textSubtle]">
                {{ runtimeWord(locale, 'fill_check') }}
            </span>
            <input
                ref="extractInput"
                type="file"
                class="hidden"
                accept="image/*,application/pdf"
                capture="environment"
                @change="onDocumentChosen"
            />
        </div>

        <VoiceRecorder
            v-if="recording"
            :locale="locale"
            @recorded="onRecorded"
            @close="recording = false"
        />

        <p
            v-if="heard"
            data-sp-fill-heard
            :class="['mb-2 text-[11px]', t.textMuted]"
        >
            {{ heard }}
        </p>

        <p
            v-if="extractError"
            data-sp-fill-error
            class="mb-2 text-[11px] text-amber-500"
        >
            {{ extractError }}
        </p>

        <div :class="gridClass">
            <div
                v-for="rf in visibleFields"
                :key="rf.fieldId"
                class="space-y-1.5"
                :class="spansBothColumns(rf) ? 'md:col-span-2' : ''"
            >
                <label
                    :for="`form_${block.id}_${rf.slug}`"
                    :class="['text-xs', t.textMuted]"
                >
                    {{ rf.label }}
                    <span v-if="isRequired(rf)" class="text-red-400">*</span>
                </label>

                <div
                    :inert="rf.readonly"
                    :class="rf.readonly ? 'opacity-60' : ''"
                >
                    <FormFieldInput
                        :field="rf.field"
                        :input-id="`form_${block.id}_${rf.slug}`"
                        v-model="formData[rf.slug]"
                        :app-slug="appSlug"
                        :locale="locale"
                        :object-id="object?.id"
                    />
                </div>

                <!--
                One line under the field, and the error REPLACES the help
                rather than joining it, so validating a form does not shove
                everything below it down the page.

                The line is only RESERVED for a field that has help text. It
                used to be reserved for every field, which on a desktop is
                invisible and on a phone is the whole problem: seventeen pixels
                of nothing under each of ten fields is two fewer fields on
                screen, and a form where you scroll past emptiness to reach the
                next question. A field with no help text gets the line only
                when it has an error — which shifts what is below it by one
                line, at the one moment the reader is already looking.
            -->
                <p
                    v-if="fieldNote(rf) !== ''"
                    class="text-[11px] leading-tight"
                    :class="
                        (fieldErrors[rf.slug] ?? []).length > 0
                            ? 'text-red-400'
                            : t.textSubtle
                    "
                >
                    {{ fieldNote(rf) }}
                </p>
            </div>
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
                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
            >
                {{ block.submit_label ?? 'Save' }}
            </button>
        </div>
    </form>
</template>
