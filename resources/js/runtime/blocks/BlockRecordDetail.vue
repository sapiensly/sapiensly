<script setup lang="ts">
import { Eye } from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import type { FieldDef, ObjectDef, RowRef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { useRecordPresence, type Watcher } from '../useLiveRecords';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { runtimeWord } from '../words';
import FieldValue from './FieldValue.vue';
import { type DisplayContext } from './fieldDisplay';

interface DetailField {
    field_id: string;
    label_override?: string;
}

interface RecordDetailBlock {
    id: string;
    type: 'record_detail';
    label?: string;
    object_id: string;
    record_id_expression: string;
    fields: DetailField[];
    /** Show who else has this record open. */
    presence?: boolean;
}

const props = defineProps<{
    block: RecordDetailBlock;
    data:
        | {
              record: RowRef | null;
          }
        | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.object_id),
);
const record = computed(() => props.data?.record ?? null);

/**
 * Who else is looking at this.
 *
 * Two people about to edit the same order is the thing worth knowing, and
 * knowing it is what stops the second one wasting the work. It says somebody is
 * HERE and never what they are doing — a presence channel carries names to
 * every other member, so anything more would be telling people about each
 * other's work rather than about their own collision.
 */
const appId = inject<string>('appId', '');
const currentUserId = inject<number | null>('currentUserId', null);

const { watchers } =
    props.block.presence === true && record.value !== null
        ? useRecordPresence(appId, record.value.id, currentUserId)
        : { watchers: ref<Watcher[]>([]) };

const context = computed<DisplayContext>(() => ({
    locale: props.locale,
    defaultCurrency: props.defaultCurrency,
    labels: record.value?.labels,
    labelsTrashed: record.value?.labels_trashed,
    objects: props.objects,
}));

/**
 * Prose spans the full row; everything else pairs up across the grid.
 *
 * A description sharing a 3-up row with a status wraps to five cramped lines
 * beside two words, so the long ones get the whole width and the short ones
 * stay side by side.
 */
const WIDE_TYPES = new Set(['long_text', 'rich_text', 'file', 'date_range']);

interface DetailRow {
    key: string;
    label: string;
    field?: FieldDef;
    value: unknown;
    wide: boolean;
}

const rows = computed<DetailRow[]>(() =>
    props.block.fields.map((f) => {
        const field = resolveField(object.value, f.field_id);
        return {
            key: f.field_id,
            label: f.label_override ?? field?.name ?? f.field_id,
            field,
            value: field ? record.value?.data?.[field.slug] : undefined,
            wide: WIDE_TYPES.has(field?.type ?? ''),
        };
    }),
);
</script>

<template>
    <!--
        Stacked pairs on a responsive grid, not a label/value row split across
        the card. The old layout pushed every value hard right, so on a wide
        screen a label sat a thousand pixels from the thing it named and reading
        one record meant sweeping left to right per line. Label above value
        keeps the two together at any width, and the grid uses the space the
        card actually has instead of padding it with air.
    -->
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <p
            v-if="block.label"
            :class="['mb-4 text-[11px] tracking-wider uppercase', t.textSubtle]"
        >
            {{ block.label }}
        </p>

        <!-- Somebody else has this open. Said plainly and without alarm: it is
             information, not a lock. -->
        <p
            v-if="watchers.length > 0"
            data-sp-presence
            :class="['mb-3 flex items-center gap-1.5 text-[11px]', t.textMuted]"
        >
            <Eye class="size-3.5 shrink-0" />
            {{
                runtimeWord(locale, 'presence_here', {
                    who: watchers.map((w) => w.name).join(', '),
                })
            }}
        </p>

        <p v-if="!record" :class="['py-6 text-center text-xs', t.textMuted]">
            No record selected.
        </p>

        <dl
            v-else
            class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <div
                v-for="row in rows"
                :key="row.key"
                :class="row.wide ? 'sm:col-span-2 xl:col-span-3' : ''"
            >
                <dt
                    :class="[
                        'mb-1 text-[11px] tracking-wide uppercase',
                        t.textSubtle,
                    ]"
                >
                    {{ row.label }}
                </dt>
                <dd :class="['text-sm', t.text]">
                    <FieldValue
                        v-if="row.field"
                        :field="row.field"
                        :value="row.value"
                        :context="context"
                    />
                    <template v-else>—</template>
                </dd>
            </div>
        </dl>
    </div>
</template>
