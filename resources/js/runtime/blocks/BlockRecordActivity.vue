<script setup lang="ts">
/**
 * What has happened to this record, and the box for adding to it.
 *
 * One list for two things, because they are read as one: somebody opening an
 * order asks "why is this still waiting?" and the answer is half machine
 * ("Estado: Recibida → Esperando refacción, Ana") and half human ("llamé al
 * cliente, no contesta"). Split into two panels they would be read
 * interleaved anyway.
 *
 * Values are stored raw, so a select's option is turned into its label here —
 * against whatever the manifest says today. A field that has since been
 * renamed or dropped still reads, because the label was captured when the
 * change was written.
 */
import axios from 'axios';
import { computed, inject, onMounted, onUnmounted, ref, watch } from 'vue';
import type { ObjectDef } from '../types/manifest';
import { blockDataBus } from '../useActionExecutor';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { runtimeWord } from '../words';

interface Change {
    field: string;
    label: string;
    from: unknown;
    to: unknown;
}

interface TrailEvent {
    id: string;
    kind: 'created' | 'updated' | 'deleted' | 'restored' | 'purged' | 'comment';
    actor: string | null;
    body: string | null;
    changes: Change[] | null;
    at: string | null;
}

const props = defineProps<{
    block: {
        id: string;
        type: 'record_activity';
        label?: string;
        record_id_expression: string;
        allow_comments?: boolean;
    };
    objects: ObjectDef[];
    locale: string;
}>();

const appSlug = inject<string>('appSlug', '');

/**
 * Which record this is about.
 *
 * Resolved here rather than by the renderer: `{{params.id}}` means a URL
 * param, and the block's whole job happens against a live endpoint anyway, so
 * there is nothing for the server to have pre-resolved.
 */
const recordId = computed<string | null>(() => {
    const raw = props.block.record_id_expression ?? '';
    const match = raw.match(/^\{\{\s*params\.([\w-]+)\s*\}\}$/);
    if (!match) return raw.startsWith('rec_') ? raw : null;

    if (typeof window === 'undefined') return null;

    return new URLSearchParams(window.location.search).get(match[1]);
});

const t = themeTokens(useRuntimeTheme());

const events = ref<TrailEvent[]>([]);
const loading = ref(true);
const draft = ref('');
const sending = ref(false);
/** Set when the endpoint refuses — a read-only role, or a portal. */
const cannotComment = ref(false);
/**
 * Whether this app records a trail at all. Off by default — keeping a record of
 * who did what is a business's decision, not something a platform starts doing
 * because nobody said no. When it is off the panel does not render: a box that
 * accepts a note and drops it is worse than no box.
 */
const enabled = ref(true);

const canComment = computed(
    () => props.block.allow_comments !== false && !cannotComment.value,
);

function mount(): string {
    if (typeof window !== 'undefined') {
        const m = window.location.pathname.match(
            /^\/(r|a)\/([a-z0-9][a-z0-9_-]*)/,
        );
        if (m) return `/${m[1]}/${m[2]}`;
    }
    return `/r/${appSlug}`;
}

async function load(): Promise<void> {
    if (!recordId.value) {
        events.value = [];
        loading.value = false;
        return;
    }

    loading.value = true;
    try {
        const { data } = await axios.get(
            `${mount()}/records/${recordId.value}/trail`,
        );
        enabled.value = data.enabled !== false;
        events.value = data.events ?? [];
    } catch {
        events.value = [];
    } finally {
        loading.value = false;
    }
}

async function send(): Promise<void> {
    const body = draft.value.trim();
    if (body === '' || sending.value || !recordId.value) return;

    sending.value = true;
    try {
        const { data } = await axios.post(
            `${mount()}/records/${recordId.value}/trail`,
            { body },
        );
        events.value = [data.event, ...events.value];
        draft.value = '';
    } catch {
        // A role that may read but not update, or a surface with no endpoint.
        // Saying so beats a box that swallows what somebody typed.
        cannotComment.value = true;
    } finally {
        sending.value = false;
    }
}

onMounted(load);
watch(recordId, load);

// A save on this page is exactly the thing this block exists to report, and it
// does not arrive as a prop: the form's `refresh` patches the other blocks'
// data in place, so nothing this component watches ever changes. Editing a
// record and watching its own history not mention it is worse than having no
// history — it reads as the block being broken.
onUnmounted(blockDataBus.on(() => void load()));

/** The field definitions of every object, so a stored value can be named. */
const fieldsBySlug = computed(() => {
    const out: Record<
        string,
        { name: string; options?: { value: string; label: string }[] }
    > = {};
    for (const object of props.objects) {
        for (const field of object.fields ?? []) {
            out[field.slug] = field as never;
        }
    }
    return out;
});

/**
 * A stored value as a person would read it. An option's label when the field
 * still has one, the value itself otherwise, and a dash for nothing — which is
 * the honest rendering of a field that was empty before.
 */
function readable(change: Change, value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (Array.isArray(value))
        return value.length === 0 ? '—' : value.join(', ');
    if (typeof value === 'boolean') {
        return runtimeWord(props.locale, value ? 'bool_yes' : 'bool_no');
    }

    const options = fieldsBySlug.value[change.field]?.options;
    const match = options?.find((o) => o.value === value);

    return match ? match.label : String(value);
}

function when(iso: string | null): string {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString(props.locale, {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

function word(key: string): string {
    return runtimeWord(props.locale, key);
}
</script>

<template>
    <div
        v-if="enabled"
        :class="['overflow-hidden rounded-sp-sm border', t.surface]"
    >
        <p
            :class="[
                'border-b border-soft px-3 py-2 text-[11px] tracking-wider uppercase',
                t.textSubtle,
            ]"
        >
            {{ block.label ?? word('activity') }}
        </p>

        <!-- The box is at the top, beside the newest entry: a trail is read
             newest-first, and a comment box below fifty rows is a box nobody
             finds. -->
        <div v-if="canComment && recordId" class="border-b border-soft p-3">
            <textarea
                v-model="draft"
                rows="2"
                maxlength="2000"
                :placeholder="word('leave_a_note')"
                :class="[
                    'w-full resize-y rounded-md border px-2.5 py-1.5 text-sm',
                    t.surfaceMuted,
                    t.text,
                ]"
                @keydown.meta.enter="send"
                @keydown.ctrl.enter="send"
            />
            <div class="mt-1.5 flex justify-end">
                <button
                    type="button"
                    data-sp-trail-send
                    :disabled="sending || draft.trim() === ''"
                    class="rounded-pill bg-accent-blue px-3 py-1 text-xs font-medium text-white transition-colors hover:bg-accent-blue-hover disabled:opacity-40"
                    @click="send"
                >
                    {{ word('comment_send') }}
                </button>
            </div>
        </div>

        <p
            v-if="loading"
            :class="['px-3 py-6 text-center text-xs', t.textMuted]"
        >
            …
        </p>

        <p
            v-else-if="events.length === 0"
            :class="['px-3 py-6 text-center text-xs', t.textMuted]"
        >
            {{ word('no_activity') }}
        </p>

        <ul v-else class="divide-y divide-soft/60">
            <li
                v-for="event in events"
                :key="event.id"
                :data-sp-trail-kind="event.kind"
                class="px-3 py-2.5"
            >
                <div
                    :class="[
                        'flex flex-wrap items-baseline gap-x-2 text-[11px]',
                        t.textSubtle,
                    ]"
                >
                    <span :class="['font-medium', t.text]">
                        {{ event.actor ?? word('by_the_app') }}
                    </span>
                    <span>{{ word(`event_${event.kind}`) }}</span>
                    <span class="ml-auto">{{ when(event.at) }}</span>
                </div>

                <p
                    v-if="event.body"
                    :class="[
                        'mt-1 text-sm leading-relaxed whitespace-pre-line',
                        t.text,
                    ]"
                >
                    {{ event.body }}
                </p>

                <ul v-if="event.changes?.length" class="mt-1 space-y-0.5">
                    <li
                        v-for="change in event.changes"
                        :key="change.field"
                        :class="['text-xs', t.textMuted]"
                    >
                        <span :class="t.text">{{ change.label }}</span
                        >:
                        {{ readable(change, change.from) }}
                        →
                        <span :class="t.text">{{
                            readable(change, change.to)
                        }}</span>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</template>
