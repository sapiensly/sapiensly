<script setup lang="ts">
import * as AppBuilderController from '@/actions/App/Http/Controllers/AppBuilderController';
import * as AppController from '@/actions/App/Http/Controllers/AppController';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AppWindow,
    Building2,
    Check,
    ChevronDown,
    Database,
    ExternalLink,
    FileText,
    Globe,
    History,
    LayoutDashboard,
    Lock,
    Pencil,
    Plus,
    Sparkles,
    Trash2,
    Workflow as WorkflowIcon,
    X,
} from '@lucide/vue';
import { computed, ref, type Component } from 'vue';
import { useI18n } from 'vue-i18n';

interface AppItem {
    id: string;
    slug: string;
    name: string;
    description: string | null;
    icon: string | null;
    color: string | null;
    visibility: string;
    current_version_id: string | null;
    created_at: string;
}

interface VersionItem {
    id: string;
    version_number: number;
    change_summary: string | null;
    created_at: string;
    created_by?: { id: number; name: string } | null;
}

interface Overview {
    stats: { pages: number; objects: number; records: number; workflows: number };
    pages: Array<{ id: string; slug: string; name: string; icon: string | null; block_count: number }>;
    objects: Array<{ id: string; slug: string; name: string; field_count: number; record_count: number }>;
    workflows: Array<{ id: string; name: string; trigger_type: string | null; object_name: string | null }>;
    settings: { default_locale: string | null; default_currency: string | null; default_timezone: string | null };
}

interface Props {
    app: AppItem;
    manifest: Record<string, unknown> | null;
    overview: Overview | null;
    versions: VersionItem[];
}

const props = defineProps<Props>();

const { t } = useI18n();

const tint = props.app.color ?? 'var(--sp-accent-blue)';

// Inline edit of name + description (the slug is fixed — it's the runtime URL).
const editing = ref(false);
const editName = ref(props.app.name);
const editDescription = ref(props.app.description ?? '');
const savingEdit = ref(false);

function startEdit(): void {
    editName.value = props.app.name;
    editDescription.value = props.app.description ?? '';
    editing.value = true;
}
function cancelEdit(): void {
    editing.value = false;
}
function saveEdit(): void {
    const name = editName.value.trim();
    if (name === '' || savingEdit.value) return;
    savingEdit.value = true;
    router.put(
        AppController.update(props.app.id).url,
        { name, description: editDescription.value.trim() || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = false;
            },
            onFinish: () => {
                savingEdit.value = false;
            },
        },
    );
}

interface VisibilityPill {
    icon: Component;
    classes: string;
    label: string;
}

const visibilityPill = computed<VisibilityPill>(() => {
    switch (props.app.visibility) {
        case 'organization':
            return { icon: Building2, classes: 'border-accent-blue/30 bg-accent-blue/10 text-accent-blue', label: 'Org' };
        case 'public':
            return { icon: Globe, classes: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300', label: 'Public' };
        case 'global':
            return { icon: Globe, classes: 'border-spectrum-magenta/30 bg-spectrum-magenta/10 text-spectrum-magenta', label: 'Global' };
        default:
            return { icon: Lock, classes: 'border-medium bg-surface text-ink-muted', label: 'Private' };
    }
});

const stats = computed(() => {
    const s = props.overview?.stats;
    return [
        { id: 'pages', icon: LayoutDashboard, label: t('apps.show.stat_pages'), value: s?.pages ?? 0 },
        { id: 'objects', icon: Database, label: t('apps.show.stat_objects'), value: s?.objects ?? 0 },
        { id: 'records', icon: FileText, label: t('apps.show.stat_records'), value: s?.records ?? 0 },
        { id: 'workflows', icon: WorkflowIcon, label: t('apps.show.stat_workflows'), value: s?.workflows ?? 0 },
    ];
});

const isEmptyApp = computed(
    () =>
        !props.overview ||
        (props.overview.pages.length === 0 &&
            props.overview.objects.length === 0 &&
            props.overview.workflows.length === 0),
);

// Manifest + version history are secondary detail — collapsed by default so
// the useful overview leads.
const showAdvanced = ref(false);

function destroyApp() {
    if (!confirm(t('apps.show.delete_confirm'))) return;
    router.delete(AppController.destroy(props.app.id).url);
}

function workflowTriggerLabel(w: Overview['workflows'][number]): string {
    const parts: string[] = [];
    if (w.trigger_type) parts.push(w.trigger_type);
    if (w.object_name) parts.push(w.object_name);
    return parts.join(' · ');
}

function formatDate(value: string | null): string {
    if (!value) return '—';
    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
}
</script>

<template>
    <Head :title="app.name" />

    <AppLayoutV2 :title="t('app_v2.nav.apps')">
        <div class="mx-auto max-w-5xl space-y-6">
            <header class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xs"
                        :style="{
                            backgroundColor: `color-mix(in oklab, ${tint} 15%, transparent)`,
                            color: tint,
                        }"
                    >
                        <AppWindow class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <template v-if="!editing">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-[22px] font-semibold leading-tight text-ink">
                                    {{ app.name }}
                                </h1>
                                <span
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-pill border px-2 py-0.5 text-[10px] uppercase tracking-wider',
                                        visibilityPill.classes,
                                    ]"
                                >
                                    <component :is="visibilityPill.icon" class="size-3" />
                                    {{ visibilityPill.label }}
                                </span>
                                <button
                                    type="button"
                                    @click="startEdit"
                                    :title="t('apps.show.edit_details')"
                                    class="inline-flex size-6 items-center justify-center rounded-md text-ink-subtle transition-colors hover:bg-surface hover:text-ink"
                                >
                                    <Pencil class="size-3.5" />
                                </button>
                            </div>
                            <p class="font-mono text-[11px] text-ink-subtle">/r/{{ app.slug }}</p>
                            <p v-if="app.description" class="text-xs text-ink-muted">
                                {{ app.description }}
                            </p>
                        </template>

                        <template v-else>
                            <input
                                v-model="editName"
                                type="text"
                                maxlength="100"
                                :placeholder="t('apps.show.name_placeholder')"
                                class="w-full max-w-md rounded-md border border-medium bg-surface px-2.5 py-1.5 text-[18px] font-semibold text-ink focus:border-strong focus:outline-none"
                                @keydown.enter="saveEdit"
                                @keydown.esc="cancelEdit"
                            />
                            <p class="font-mono text-[11px] text-ink-subtle">
                                /r/{{ app.slug }}
                                <span class="ml-1 italic">· {{ t('apps.show.slug_locked') }}</span>
                            </p>
                            <textarea
                                v-model="editDescription"
                                rows="3"
                                maxlength="500"
                                :placeholder="t('apps.show.description_placeholder')"
                                class="w-full max-w-xl rounded-md border border-medium bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-strong focus:outline-none"
                            />
                            <div class="flex items-center gap-2 pt-1">
                                <button
                                    type="button"
                                    @click="saveEdit"
                                    :disabled="savingEdit || !editName.trim()"
                                    class="inline-flex items-center gap-1 rounded-pill bg-accent-blue px-3 py-1 text-xs font-medium text-white transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                >
                                    <Check class="size-3.5" />
                                    {{ t('common.save') }}
                                </button>
                                <button
                                    type="button"
                                    @click="cancelEdit"
                                    class="inline-flex items-center gap-1 rounded-pill border border-medium px-3 py-1 text-xs text-ink-muted transition-colors hover:text-ink"
                                >
                                    <X class="size-3.5" />
                                    {{ t('common.cancel') }}
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <Link :href="AppBuilderController.show(app.id).url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Sparkles class="size-3.5" />
                            {{ t('apps.show.open_builder') }}
                        </button>
                    </Link>
                    <a v-if="overview && overview.pages.length" :href="`/r/${app.slug}`" target="_blank" rel="noopener">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                        >
                            <ExternalLink class="size-3.5" />
                            {{ t('apps.show.view_runtime') }}
                        </button>
                    </a>
                </div>
            </header>

            <!-- At-a-glance counts. The whole story of an App in four numbers. -->
            <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div
                    v-for="stat in stats"
                    :key="stat.id"
                    class="rounded-sp-sm border border-soft bg-navy px-4 py-3"
                >
                    <div class="flex items-center gap-1.5 text-ink-muted">
                        <component :is="stat.icon" class="size-3.5" />
                        <span class="text-[11px] uppercase tracking-wider">{{ stat.label }}</span>
                    </div>
                    <p class="mt-1.5 text-2xl font-semibold leading-none text-ink">{{ stat.value }}</p>
                </div>
            </section>

            <!-- Empty-app nudge: a fresh App has nothing to show, so point the
                 user straight at the Builder instead of three empty cards. -->
            <section
                v-if="isEmptyApp"
                class="rounded-sp-sm border border-dashed border-soft bg-navy px-5 py-10 text-center"
            >
                <Sparkles class="mx-auto size-7 text-accent-blue" />
                <p class="mt-3 text-sm font-medium text-ink">{{ t('apps.show.empty_title') }}</p>
                <p class="mx-auto mt-1 max-w-sm text-xs text-ink-muted">{{ t('apps.show.empty_hint') }}</p>
                <Link :href="AppBuilderController.show(app.id).url" class="mt-4 inline-block">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Sparkles class="size-3.5" />
                        {{ t('apps.show.open_builder') }}
                    </button>
                </Link>
            </section>

            <template v-else-if="overview">
                <!-- Pages — the user-facing surfaces, each opens in the runtime. -->
                <section v-if="overview.pages.length" class="rounded-sp-sm border border-soft bg-navy">
                    <header class="flex items-center justify-between border-b border-soft px-5 py-3">
                        <h2 class="flex items-center gap-2 text-sm font-medium text-ink">
                            <LayoutDashboard class="size-4 text-ink-muted" />
                            {{ t('apps.show.pages_section') }}
                        </h2>
                        <span class="text-[11px] text-ink-subtle">{{ overview.pages.length }}</span>
                    </header>
                    <ul class="divide-y divide-soft">
                        <li
                            v-for="p in overview.pages"
                            :key="p.id"
                            class="flex items-center justify-between gap-3 px-5 py-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm text-ink">{{ p.name }}</p>
                                <p class="text-[11px] text-ink-subtle">
                                    {{ t('apps.show.block_count', { count: p.block_count }) }}
                                </p>
                            </div>
                            <a
                                :href="`/r/${app.slug}/${p.slug}`"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex shrink-0 items-center gap-1 rounded-pill border border-medium bg-surface px-2.5 py-1 text-[11px] text-ink-muted transition-colors hover:border-strong hover:text-ink"
                            >
                                <ExternalLink class="size-3" />
                                {{ t('apps.show.open_page') }}
                            </a>
                        </li>
                    </ul>
                </section>

                <!-- Data model — objects with their live record counts. Clicking
                     jumps to the Builder where records can be browsed/edited. -->
                <section v-if="overview.objects.length" class="rounded-sp-sm border border-soft bg-navy">
                    <header class="flex items-center justify-between border-b border-soft px-5 py-3">
                        <h2 class="flex items-center gap-2 text-sm font-medium text-ink">
                            <Database class="size-4 text-ink-muted" />
                            {{ t('apps.show.data_model_section') }}
                        </h2>
                        <span class="text-[11px] text-ink-subtle">{{ overview.objects.length }}</span>
                    </header>
                    <ul class="divide-y divide-soft">
                        <li
                            v-for="o in overview.objects"
                            :key="o.id"
                            class="flex items-center justify-between gap-3 px-5 py-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm text-ink">{{ o.name }}</p>
                                <p class="text-[11px] text-ink-subtle">
                                    {{ t('apps.show.field_count', { count: o.field_count }) }}
                                </p>
                            </div>
                            <span class="shrink-0 text-right text-xs text-ink-muted">
                                {{ t('apps.show.record_count', { count: o.record_count }) }}
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- Automations — always shown so the user knows automations
                     exist as a concept even before they add one. -->
                <section class="rounded-sp-sm border border-soft bg-navy">
                    <header class="flex items-center justify-between border-b border-soft px-5 py-3">
                        <h2 class="flex items-center gap-2 text-sm font-medium text-ink">
                            <WorkflowIcon class="size-4 text-ink-muted" />
                            {{ t('apps.show.workflows_section') }}
                        </h2>
                        <span class="text-[11px] text-ink-subtle">{{ overview.workflows.length }}</span>
                    </header>
                    <ul v-if="overview.workflows.length" class="divide-y divide-soft">
                        <li
                            v-for="w in overview.workflows"
                            :key="w.id"
                            class="flex items-center justify-between gap-3 px-5 py-3"
                        >
                            <p class="truncate text-sm text-ink">{{ w.name }}</p>
                            <span
                                v-if="workflowTriggerLabel(w)"
                                class="shrink-0 rounded-pill border border-medium bg-surface px-2 py-0.5 text-[10px] uppercase tracking-wider text-ink-muted"
                            >
                                {{ workflowTriggerLabel(w) }}
                            </span>
                        </li>
                    </ul>
                    <div v-else class="flex flex-col items-center gap-2 px-5 py-8 text-center">
                        <p class="text-xs text-ink-muted">{{ t('apps.show.no_workflows') }}</p>
                        <Link
                            :href="AppBuilderController.show(app.id).url"
                            class="inline-flex items-center gap-1 rounded-pill border border-medium bg-surface px-2.5 py-1 text-[11px] text-ink-muted transition-colors hover:border-accent-blue/40 hover:bg-accent-blue/10 hover:text-accent-blue"
                        >
                            <Plus class="size-3" />
                            {{ t('apps.show.add_workflow') }}
                        </Link>
                    </div>
                </section>
            </template>

            <!-- Advanced: raw manifest + version history. Secondary detail, so
                 collapsed behind a disclosure. -->
            <section class="rounded-sp-sm border border-soft bg-navy">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 px-5 py-3 text-left"
                    :aria-expanded="showAdvanced"
                    @click="showAdvanced = !showAdvanced"
                >
                    <span class="flex items-center gap-2 text-sm font-medium text-ink">
                        <History class="size-4 text-ink-muted" />
                        {{ t('apps.show.advanced_section') }}
                    </span>
                    <ChevronDown
                        class="size-4 text-ink-muted transition-transform"
                        :class="showAdvanced ? 'rotate-180' : ''"
                    />
                </button>

                <div v-if="showAdvanced" class="space-y-6 border-t border-soft px-5 py-4">
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-xs font-medium uppercase tracking-wider text-ink-muted">
                                {{ t('apps.show.versions_section') }}
                            </h3>
                            <span class="text-[11px] text-ink-subtle">{{ versions.length }}</span>
                        </div>
                        <ul v-if="versions.length" class="divide-y divide-soft rounded-xs border border-soft">
                            <li
                                v-for="v in versions"
                                :key="v.id"
                                class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                            >
                                <div class="min-w-0">
                                    <p class="text-ink">
                                        {{ t('apps.show.version_short', { number: v.version_number }) }}
                                        <span
                                            v-if="app.current_version_id === v.id"
                                            class="ml-1.5 text-[10px] uppercase tracking-wider text-accent-blue"
                                        >
                                            current
                                        </span>
                                    </p>
                                    <p v-if="v.change_summary" class="truncate text-xs text-ink-muted">
                                        {{ v.change_summary }}
                                    </p>
                                </div>
                                <div class="shrink-0 text-right text-[11px] text-ink-subtle">
                                    <div>{{ v.created_by?.name ?? 'system' }}</div>
                                    <div>{{ formatDate(v.created_at) }}</div>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-xs text-ink-muted">{{ t('apps.show.no_versions') }}</p>
                    </div>

                    <div>
                        <h3 class="mb-2 text-xs font-medium uppercase tracking-wider text-ink-muted">
                            {{ t('apps.show.manifest_section') }}
                        </h3>
                        <pre
                            v-if="manifest"
                            class="max-h-96 overflow-auto rounded-xs border border-soft bg-black/20 p-4 font-mono text-[11px] leading-snug text-ink"
                        >{{ JSON.stringify(manifest, null, 2) }}</pre>
                        <p v-else class="text-xs text-ink-muted">{{ t('apps.show.no_manifest') }}</p>
                    </div>
                </div>
            </section>

            <div class="flex justify-end pt-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-pill border border-red-500/40 bg-red-500/5 px-3.5 py-1.5 text-xs text-red-400 transition-colors hover:border-red-500/70 hover:bg-red-500/10"
                    @click="destroyApp"
                >
                    <Trash2 class="size-3.5" />
                    {{ t('apps.show.delete') }}
                </button>
            </div>
        </div>
    </AppLayoutV2>
</template>
