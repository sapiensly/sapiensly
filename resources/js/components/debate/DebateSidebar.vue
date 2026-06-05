<script setup lang="ts">
import WorkspaceSwitcher from '@/components/app-v2/WorkspaceSwitcher.vue';
import type { DebateListItem, DebateStatus } from '@/types/debateModule';
import { router } from '@inertiajs/vue3';
import { Check, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    debates: DebateListItem[];
    activeId: string | null;
}>();

const editingId = ref<string | null>(null);
const editingTitle = ref('');

const groups = computed(() => {
    const now = new Date();
    const startOfToday = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
    ).getTime();
    const dayMs = 86400000;
    const buckets: Record<string, DebateListItem[]> = {
        today: [],
        yesterday: [],
        previous7: [],
        older: [],
    };
    for (const d of props.debates) {
        const ts = d.last_activity_at
            ? new Date(d.last_activity_at).getTime()
            : 0;
        if (ts >= startOfToday) buckets.today.push(d);
        else if (ts >= startOfToday - dayMs) buckets.yesterday.push(d);
        else if (ts >= startOfToday - 7 * dayMs) buckets.previous7.push(d);
        else buckets.older.push(d);
    }
    return [
        {
            key: 'today',
            label: t('debate.history.today'),
            items: buckets.today,
        },
        {
            key: 'yesterday',
            label: t('debate.history.yesterday'),
            items: buckets.yesterday,
        },
        {
            key: 'previous7',
            label: t('debate.history.previous_7_days'),
            items: buckets.previous7,
        },
        {
            key: 'older',
            label: t('debate.history.older'),
            items: buckets.older,
        },
    ].filter((g) => g.items.length > 0);
});

const statusDot: Record<DebateStatus, string> = {
    pending: 'bg-amber-500',
    debating: 'bg-amber-500',
    assessing: 'bg-amber-500',
    converged: 'bg-accent-blue',
    completed: 'bg-emerald-500',
    stopped: 'bg-rose-500',
    failed: 'bg-rose-500',
};

function newDebate() {
    router.visit('/debates');
}

function openDebate(id: string) {
    if (id === props.activeId) return;
    router.get(
        `/debates/${id}`,
        {},
        { only: ['activeDebate'], preserveState: true, preserveScroll: true },
    );
}

function startRename(d: DebateListItem) {
    editingId.value = d.id;
    editingTitle.value = d.title ?? '';
}

function commitRename(d: DebateListItem) {
    const title = editingTitle.value.trim();
    editingId.value = null;
    if (title === '' || title === (d.title ?? '')) return;
    router.patch(
        `/debates/${d.id}`,
        { title },
        {
            only: ['debates', 'activeDebate'],
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function deleteDebate(d: DebateListItem) {
    if (!window.confirm(t('debate.delete_confirm'))) return;
    router.delete(`/debates/${d.id}`, { preserveScroll: true });
}
</script>

<template>
    <aside
        class="flex h-full w-72 shrink-0 flex-col border-r border-soft bg-navy"
    >
        <div class="p-3">
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-xl border border-medium bg-surface px-3 py-2.5 text-sm font-medium text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                @click="newDebate"
            >
                <Plus class="size-4 text-accent-blue" />
                {{ t('debate.new_debate') }}
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-2 pb-4">
            <div v-for="g in groups" :key="g.key" class="mb-3">
                <p
                    class="px-2 py-1 text-[11px] font-semibold tracking-wider text-ink-subtle uppercase"
                >
                    {{ g.label }}
                </p>
                <div class="space-y-0.5">
                    <div
                        v-for="d in g.items"
                        :key="d.id"
                        :class="[
                            'group flex items-center gap-1 rounded-lg pr-1 pl-2 transition-colors',
                            d.id === activeId
                                ? 'bg-accent-blue/10'
                                : 'hover:bg-white/5',
                        ]"
                    >
                        <span
                            :class="[
                                'size-1.5 shrink-0 rounded-full',
                                statusDot[d.status],
                            ]"
                        />
                        <template v-if="editingId === d.id">
                            <input
                                v-model="editingTitle"
                                class="min-w-0 flex-1 rounded bg-transparent py-1.5 text-sm text-ink focus:outline-none"
                                autofocus
                                @keydown.enter="commitRename(d)"
                                @keydown.esc="editingId = null"
                                @blur="commitRename(d)"
                            />
                            <button
                                type="button"
                                class="rounded p-1 text-ink-subtle hover:text-ink"
                                @mousedown.prevent="commitRename(d)"
                            >
                                <Check class="size-3.5" />
                            </button>
                        </template>
                        <template v-else>
                            <button
                                type="button"
                                class="min-w-0 flex-1 truncate py-1.5 text-left text-sm text-ink-muted group-hover:text-ink"
                                :class="{
                                    'font-medium text-ink': d.id === activeId,
                                }"
                                @click="openDebate(d.id)"
                            >
                                {{ d.title || t('debate.untitled') }}
                            </button>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-ink-subtle opacity-0 transition-opacity group-hover:opacity-100 hover:text-ink"
                                :title="t('debate.rename')"
                                @click.stop="startRename(d)"
                            >
                                <Pencil class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-ink-subtle opacity-0 transition-opacity group-hover:opacity-100 hover:text-sp-danger"
                                :title="t('debate.delete')"
                                @click.stop="deleteDebate(d)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <p
                v-if="!debates.length"
                class="px-2 py-6 text-center text-xs text-ink-subtle"
            >
                {{ t('debate.no_history') }}
            </p>
        </div>

        <WorkspaceSwitcher active="debate" />
    </aside>
</template>
