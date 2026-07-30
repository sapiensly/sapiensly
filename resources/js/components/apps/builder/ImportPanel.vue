<script setup lang="ts">
import echo from '@/echo';
import { FileSpreadsheet, Loader2, Upload, X } from '@lucide/vue';
import axios from 'axios';
import { computed, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Upload a spreadsheet, review what it would become, then import it.
 *
 * The review step is the point. An import succeeds silently and is painful to
 * undo, so the panel shows the inferred type of every column, what will be
 * skipped and why, and a few real rows — before anything is written.
 *
 * Analysing is synchronous (it only reads). Importing is NOT: thousands of rows
 * go through the full validated write path, so confirming hands the file to a
 * queued job and this panel follows its progress — live over the socket, and by
 * polling as well, because a dropped socket must cost the animation and never
 * the answer.
 */
const props = defineProps<{
    appId: string;
    objects: Array<{ id: string; slug: string; name: string }>;
}>();

const emit = defineEmits<{ (e: 'close'): void; (e: 'imported'): void }>();

const { t } = useI18n();

interface PlanColumn {
    header: string;
    field_slug: string | null;
    type: string | null;
    skip_reason: string | null;
    profile: { samples: string[]; notes: string[] };
}
interface Plan {
    mode: string;
    object: { slug: string; name: string };
    mappings: PlanColumn[];
    total_rows: number;
    truncated: boolean;
    upsert_key: string | null;
    warnings: string[];
    sample: Array<Record<string, string | null>>;
}

interface Progress {
    id: string;
    status: string;
    total_rows: number;
    processed: number;
    created: number;
    updated: number;
    failed: number;
    errors: Array<{ row: number; errors: Record<string, string[]> }>;
    error: string | null;
    truncated: boolean;
    finished: boolean;
}

const file = ref<File | null>(null);
const plan = ref<Plan | null>(null);
const targetSlug = ref('');
const objectName = ref('');
const upsertKey = ref('');
const busy = ref(false);
const error = ref('');
// header → field slug, when the user corrects a match the planner got wrong.
const overrides = ref<Record<string, string>>({});
const progress = ref<Progress | null>(null);

const mappedCount = computed(
    () => plan.value?.mappings.filter((m) => m.field_slug !== null).length ?? 0,
);
const percent = computed(() => {
    const p = progress.value;
    if (!p || p.total_rows === 0) return 0;
    return Math.min(100, Math.round((p.processed / p.total_rows) * 100));
});
const skipped = computed(
    () => plan.value?.mappings.filter((m) => m.field_slug === null) ?? [],
);

function body(): FormData {
    const form = new FormData();
    form.append('file', file.value as File);
    if (targetSlug.value) form.append('object_slug', targetSlug.value);
    else if (objectName.value) form.append('object_name', objectName.value);
    if (upsertKey.value) form.append('upsert_key', upsertKey.value);
    for (const [header, slug] of Object.entries(overrides.value)) {
        if (slug) form.append(`overrides[${header}]`, slug);
    }
    return form;
}

/** The fields a column may be pointed at, once a target object is chosen. */
const targetFields = computed(
    () =>
        (
            plan.value?.object as {
                fields?: Array<{ slug: string; name: string }>;
            }
        )?.fields ?? [],
);

function remap(header: string, slug: string): void {
    overrides.value = { ...overrides.value, [header]: slug };
    analyze();
}

async function pick(event: Event) {
    const picked = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (!picked) return;
    file.value = picked;
    progress.value = null;
    // A sensible default name for a new object: the file's own, without the
    // extension. The user renames it if they disagree.
    objectName.value = picked.name.replace(/\.[^.]+$/, '');
    await analyze();
}

async function analyze() {
    if (!file.value || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/import/analyze`,
            body(),
        );
        plan.value = data.plan;
    } catch (e) {
        plan.value = null;
        error.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? t('apps.builder.import.failed');
    } finally {
        busy.value = false;
    }
}

async function run() {
    if (!file.value || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        // 202: the file is parked and a job takes it from here.
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/import/run`,
            body(),
        );
        progress.value = data.import;
        watchImport(data.import.id);
    } catch (e) {
        error.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? t('apps.builder.import.failed');
    } finally {
        busy.value = false;
    }
}

// Live over the socket; polled as well. The two agree because both read the
// same row, and whichever arrives first simply wins.
type ChannelHandle = ReturnType<typeof echo.private>;
let channel: ChannelHandle | null = null;
let poll: ReturnType<typeof setInterval> | null = null;

function watchImport(id: string): void {
    stopWatching();

    try {
        channel = echo.private(`app.import.${id}`);
        channel.listen('.AppImportProgress', (data: { progress: Progress }) => {
            progress.value = data.progress;
            if (data.progress.finished) finish();
        });
    } catch {
        // No socket: the poll below is the whole mechanism.
    }

    poll = setInterval(async () => {
        try {
            const { data } = await axios.get(
                `/apps/${props.appId}/builder/import/${id}`,
            );
            progress.value = data.import;
            if (data.import.finished) finish();
        } catch {
            /* keep polling; a blip is not an outcome */
        }
    }, 2000);
}

function finish(): void {
    stopWatching();
    emit('imported');
}

function stopWatching(): void {
    if (poll) {
        clearInterval(poll);
        poll = null;
    }
    if (channel) {
        channel.stopListening('.AppImportProgress');
        echo.leave(`app.import.${progress.value?.id ?? ''}`);
        channel = null;
    }
}

onUnmounted(stopWatching);
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        @click.self="emit('close')"
    >
        <div
            class="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-medium bg-surface shadow-xl"
        >
            <header
                class="flex items-center justify-between border-b border-medium px-5 py-3"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-medium text-ink"
                >
                    <FileSpreadsheet class="size-4 text-accent-blue" />
                    {{ t('apps.builder.import.title') }}
                </h2>
                <button
                    type="button"
                    class="text-ink-muted transition-colors hover:text-ink"
                    @click="emit('close')"
                >
                    <X class="size-4" />
                </button>
            </header>

            <div class="flex-1 overflow-y-auto px-5 py-4">
                <!-- Step 1: pick a file. -->
                <label
                    v-if="!plan"
                    class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed border-medium px-6 py-10 text-center transition-colors hover:border-accent-blue/50"
                >
                    <Upload class="size-6 text-ink-muted" />
                    <span class="text-sm text-ink">{{
                        t('apps.builder.import.pick')
                    }}</span>
                    <span class="text-xs text-ink-muted">{{
                        t('apps.builder.import.formats')
                    }}</span>
                    <input
                        type="file"
                        class="hidden"
                        accept=".csv,.tsv,.txt,.xlsx,.xls,.ods"
                        @change="pick"
                    />
                </label>

                <!-- Step 2: review, then confirm. -->
                <div v-else-if="!progress" class="space-y-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <label
                            class="flex flex-col gap-1 text-xs text-ink-muted"
                        >
                            {{ t('apps.builder.import.target') }}
                            <select
                                v-model="targetSlug"
                                class="rounded-md border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
                                @change="analyze"
                            >
                                <option value="">
                                    {{ t('apps.builder.import.new_object') }}
                                </option>
                                <option
                                    v-for="o in props.objects"
                                    :key="o.id"
                                    :value="o.slug"
                                >
                                    {{ o.name }}
                                </option>
                            </select>
                        </label>

                        <label
                            v-if="!targetSlug"
                            class="flex flex-col gap-1 text-xs text-ink-muted"
                        >
                            {{ t('apps.builder.import.object_name') }}
                            <input
                                v-model="objectName"
                                type="text"
                                class="rounded-md border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
                            />
                        </label>

                        <label
                            v-else
                            class="flex flex-col gap-1 text-xs text-ink-muted"
                        >
                            {{ t('apps.builder.import.upsert') }}
                            <select
                                v-model="upsertKey"
                                class="rounded-md border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
                                @change="analyze"
                            >
                                <option value="">
                                    {{ t('apps.builder.import.upsert_none') }}
                                </option>
                                <option
                                    v-for="m in plan.mappings.filter(
                                        (x) => x.field_slug,
                                    )"
                                    :key="m.header"
                                    :value="m.header"
                                >
                                    {{ m.header }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <p class="text-xs text-ink-muted">
                        {{
                            t('apps.builder.import.summary', {
                                rows: plan.total_rows,
                                mapped: mappedCount,
                                total: plan.mappings.length,
                            })
                        }}
                    </p>

                    <ul
                        v-if="plan.warnings.length"
                        class="space-y-1 rounded-md border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-[11px] text-amber-500"
                    >
                        <li v-for="w in plan.warnings" :key="w">{{ w }}</li>
                    </ul>

                    <!-- Every column in the file, and what happens to it. A
                         column that simply vanished from this list is how an
                         import quietly loses data. -->
                    <div
                        class="overflow-x-auto rounded-md border border-medium"
                    >
                        <table class="w-full text-left text-xs">
                            <thead class="bg-surface-muted text-ink-muted">
                                <tr>
                                    <th class="px-3 py-2 font-medium">
                                        {{ t('apps.builder.import.column') }}
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        {{ t('apps.builder.import.becomes') }}
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        {{ t('apps.builder.import.example') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="m in plan.mappings"
                                    :key="m.header"
                                    class="border-t border-medium"
                                >
                                    <td class="px-3 py-2 text-ink">
                                        {{ m.header }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <!-- Into an EXISTING object the target is
                                             editable: an auto-match that guessed
                                             wrong is fixed here, not by renaming
                                             the spreadsheet and re-uploading. -->
                                        <select
                                            v-if="targetSlug"
                                            class="rounded-md border border-medium bg-surface px-2 py-1 text-xs text-ink"
                                            :value="m.field_slug ?? ''"
                                            @change="
                                                remap(
                                                    m.header,
                                                    (
                                                        $event.target as HTMLSelectElement
                                                    ).value,
                                                )
                                            "
                                        >
                                            <option value="">
                                                {{
                                                    t(
                                                        'apps.builder.import.skipped',
                                                    )
                                                }}
                                            </option>
                                            <option
                                                v-for="f in targetFields"
                                                :key="f.slug"
                                                :value="f.slug"
                                            >
                                                {{ f.name }}
                                            </option>
                                        </select>
                                        <template v-else>
                                            <span
                                                v-if="m.field_slug"
                                                class="rounded-pill bg-accent-blue/10 px-2 py-0.5 text-accent-blue"
                                            >
                                                {{ m.field_slug }} ·
                                                {{ m.type }}
                                            </span>
                                            <span
                                                v-else
                                                class="text-ink-muted"
                                                :title="m.skip_reason ?? ''"
                                            >
                                                {{
                                                    t(
                                                        'apps.builder.import.skipped',
                                                    )
                                                }}
                                            </span>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2 text-ink-muted">
                                        {{ m.profile.samples.join(' · ') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="skipped.length" class="text-[11px] text-ink-muted">
                        {{
                            t('apps.builder.import.skipped_note', {
                                count: skipped.length,
                            })
                        }}
                    </p>
                </div>

                <!-- Step 3: the job, while it runs and after it stops. -->
                <div v-else class="space-y-3">
                    <p class="text-sm text-ink">
                        {{
                            progress.finished
                                ? t('apps.builder.import.done_summary', {
                                      created: progress.created,
                                      updated: progress.updated,
                                      failed: progress.failed,
                                  })
                                : t('apps.builder.import.running', {
                                      processed: progress.processed,
                                      total: progress.total_rows,
                                  })
                        }}
                    </p>

                    <div
                        v-if="!progress.finished"
                        class="bg-surface-muted h-1.5 w-full overflow-hidden rounded-full"
                    >
                        <div
                            class="h-full rounded-full bg-accent-blue transition-all"
                            :style="{ width: percent + '%' }"
                        />
                    </div>

                    <p
                        v-if="progress.status === 'failed' && progress.error"
                        class="text-xs text-red-400"
                    >
                        {{ progress.error }}
                    </p>

                    <div
                        v-if="progress.failed > 0"
                        class="space-y-1 rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2 text-[11px] text-red-400"
                    >
                        <p>{{ t('apps.builder.import.failed_rows') }}</p>
                        <ul class="space-y-0.5">
                            <li v-for="e in progress.errors" :key="e.row">
                                {{
                                    t('apps.builder.import.row', {
                                        row: e.row,
                                    })
                                }}:
                                {{ Object.values(e.errors).flat().join(' ') }}
                            </li>
                        </ul>
                    </div>
                </div>

                <p v-if="error" class="mt-3 text-xs text-red-400">
                    {{ error }}
                </p>
            </div>

            <footer
                class="flex items-center justify-end gap-2 border-t border-medium px-5 py-3"
            >
                <button
                    type="button"
                    class="rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs text-ink-muted transition-colors hover:text-ink"
                    @click="emit('close')"
                >
                    {{
                        progress
                            ? t('apps.builder.import.done')
                            : t('apps.builder.import.cancel')
                    }}
                </button>
                <button
                    v-if="plan && !progress"
                    type="button"
                    :disabled="busy || mappedCount === 0"
                    class="inline-flex items-center gap-1.5 rounded-pill border border-accent-blue/30 bg-accent-blue/10 px-3 py-1.5 text-xs font-medium text-accent-blue transition-colors hover:bg-accent-blue/20 disabled:opacity-50"
                    @click="run"
                >
                    <Loader2 v-if="busy" class="size-3.5 animate-spin" />
                    {{
                        t('apps.builder.import.confirm', {
                            rows: plan.total_rows,
                        })
                    }}
                </button>
            </footer>
        </div>
    </div>
</template>
