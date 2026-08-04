<script setup lang="ts">
import { computed, inject, ref } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { useRuntimeWrite } from '../useRuntimeWrite';
import { runtimeWord } from '../words';
import FormFieldInput from './FormFieldInput.vue';

/**
 * A form whose fields come from RECORDS.
 *
 * The thing that lets an app's own users define a questionnaire: HR writes the
 * questions as data and an employee answers a real form, rather than a
 * spreadsheet with one column per possible answer type — which is what a
 * writable grid gives you, and it reads exactly as badly as it sounds.
 *
 * Each question is drawn with the SAME FormFieldInput every ordinary form uses,
 * so a scale is a real rating control, a yes/no is a real switch, and a photo
 * question gets the camera. Nothing here re-implements an input; it maps a
 * question's own vocabulary onto one and gets the rest for free.
 */
interface RecordFormBlock {
    id: string;
    type: 'record_form';
    label?: string;
    label_field_id: string;
    type_field_id: string;
    type_map: Record<string, string>;
    options_field_id?: string;
    required_field_id?: string;
    submit_label?: string;
    answers: { value_field_ids: Record<string, string> };
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: RecordFormBlock;
    data:
        | { rows: RowData[]; can?: { answer?: boolean }; answered?: boolean }
        | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const appSlug = inject<string>('appSlug', '');
const { write } = useRuntimeWrite();

const answers = ref<Record<string, unknown>>({});
const sending = ref(false);
const done = ref(false);
const answeredElsewhere = ref(false);
const error = ref<string | null>(null);
const missing = ref<string[]>([]);

/** Anything the author did not map renders as text rather than as nothing. */
function kindOf(row: RowData): string {
    const declared = row.data[slugOf(props.block.type_field_id)];

    return props.block.type_map[String(declared ?? '')] ?? 'string';
}

function slugOf(fieldId: string): string {
    for (const object of props.objects) {
        const field = object.fields.find((f) => f.id === fieldId);
        if (field) return field.slug;
    }

    return fieldId;
}

/**
 * A synthetic field descriptor per question.
 *
 * The input components take a FieldDef, so each question is described as one —
 * its id is the question's record id, which is also how the answer is keyed on
 * the way back.
 */
const questions = computed(() =>
    (props.data?.rows ?? []).map((row) => {
        const kind = kindOf(row);
        const raw = row.data[slugOf(props.block.options_field_id ?? '')];

        const options = Array.isArray(raw)
            ? raw.map((o) => String(o))
            : String(raw ?? '')
                  .split(/[\n,]/)
                  .map((o) => o.trim())
                  .filter((o) => o !== '');

        const field: FieldDef = {
            id: row.id,
            slug: row.id,
            name: String(row.data[slugOf(props.block.label_field_id)] ?? ''),
            type: kind as FieldDef['type'],
            ...(kind === 'rating' ? { max: 5 } : {}),
            ...(options.length > 0
                ? {
                      options: options.map((value) => ({
                          id: value,
                          value,
                          label: value,
                      })),
                  }
                : {}),
        };

        return {
            row,
            field,
            kind,
            required:
                props.block.required_field_id !== undefined &&
                row.data[slugOf(props.block.required_field_id)] === true,
        };
    }),
);

const canAnswer = computed(() => props.data?.can?.answer !== false);

/**
 * Filed already — on this visit or a previous one.
 *
 * The server says so on load, which is the half a client-side flag cannot
 * cover: a reload used to bring the whole form back, and on an anonymous
 * questionnaire a second filing can never be told from the first afterwards.
 */
const filed = computed(
    () =>
        done.value || answeredElsewhere.value || props.data?.answered === true,
);

async function submit(): Promise<void> {
    if (sending.value) return;

    // Checked here AND on the server, but named here: an employee who is told
    // "something was missing" without being told which question has to read the
    // whole form again.
    missing.value = questions.value
        .filter((q) => {
            const value = answers.value[q.row.id];

            return (
                q.required &&
                (value === undefined || value === null || value === '')
            );
        })
        .map((q) => q.row.id);

    if (missing.value.length > 0) {
        error.value = runtimeWord(props.locale, 'form_missing_required');

        return;
    }

    sending.value = true;
    error.value = null;

    const result = await write<{ answers: number }>(
        `/r/${appSlug}/forms/${props.block.id}/submit`,
        {
            answers: questions.value.map((q) => ({
                question_id: q.row.id,
                kind: q.kind,
                value: answers.value[q.row.id] ?? null,
            })),
        },
    );

    sending.value = false;

    if (!result.ok) {
        // The server says it was already filed — by a second tab, or a first
        // click this one raced. Not an error to retry: showing the form again
        // would invite exactly the duplicate that was just refused.
        if (result.status === 409) {
            answeredElsewhere.value = true;

            return;
        }

        error.value = runtimeWord(props.locale, 'form_send_failed');

        return;
    }

    done.value = true;
}
</script>

<template>
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <p
            v-if="block.label"
            :class="['mb-4 text-[11px] tracking-wider uppercase', t.textSubtle]"
        >
            {{ block.label }}
        </p>

        <!-- Filed. Deliberately terminal: re-submitting is how somebody
             accidentally answers twice, and on an anonymous survey there is no
             way afterwards to tell which of the two was theirs. -->
        <p
            v-if="filed"
            data-sp-form-done
            :class="['py-8 text-center text-sm', t.text]"
        >
            {{
                done
                    ? runtimeWord(locale, 'form_thanks')
                    : runtimeWord(locale, 'form_already_answered')
            }}
        </p>

        <p
            v-else-if="questions.length === 0"
            :class="['py-6 text-center text-xs', t.textMuted]"
        >
            {{ runtimeWord(locale, 'form_no_questions') }}
        </p>

        <form v-else class="space-y-5" @submit.prevent="submit">
            <div
                v-for="q in questions"
                :key="q.row.id"
                :data-sp-question="q.row.id"
                class="space-y-1.5"
            >
                <label
                    :for="`q-${q.row.id}`"
                    :class="['block text-sm', t.text]"
                >
                    {{ q.field.name }}
                    <span v-if="q.required" class="text-red-400">*</span>
                </label>

                <FormFieldInput
                    :field="q.field"
                    :input-id="`q-${q.row.id}`"
                    :model-value="answers[q.row.id] ?? null"
                    :app-slug="appSlug"
                    :locale="locale"
                    @update:model-value="answers[q.row.id] = $event"
                />

                <p
                    v-if="missing.includes(q.row.id)"
                    class="text-[11px] text-red-400"
                >
                    {{ runtimeWord(locale, 'form_required') }}
                </p>
            </div>

            <p v-if="error" data-sp-form-error class="text-xs text-amber-500">
                {{ error }}
            </p>

            <button
                type="submit"
                data-sp-form-submit
                :disabled="sending || !canAnswer"
                class="rounded-pill bg-accent-blue px-4 py-2 text-sm text-white transition-opacity disabled:opacity-50"
            >
                {{
                    sending
                        ? runtimeWord(locale, 'form_sending')
                        : (block.submit_label ??
                          runtimeWord(locale, 'form_send'))
                }}
            </button>
        </form>
    </div>
</template>
