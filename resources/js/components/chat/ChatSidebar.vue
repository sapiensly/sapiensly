<script setup lang="ts">
import WorkspaceSwitcher from '@/components/app-v2/WorkspaceSwitcher.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ChatListItem, ChatProjectDto, KnowledgeBaseOption } from '@/types/chatModule';
import { router } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    Database,
    FolderClosed,
    MessageSquarePlus,
    Pencil,
    Plus,
    Settings2,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    chats: ChatListItem[];
    projects: ChatProjectDto[];
    knowledgeBases: KnowledgeBaseOption[];
    activeId: string | null;
}>();

const projectFilter = ref<string | null>(null);
const projectsOpen = ref(true);
const editingId = ref<string | null>(null);
const editingTitle = ref('');

const visibleChats = computed(() =>
    projectFilter.value
        ? props.chats.filter((c) => c.chat_project_id === projectFilter.value)
        : props.chats,
);

// Group by recency buckets for the Claude-style history list.
const groups = computed(() => {
    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const dayMs = 86400000;
    const buckets: Record<string, ChatListItem[]> = {
        today: [],
        yesterday: [],
        previous7: [],
        older: [],
    };
    for (const c of visibleChats.value) {
        const ts = c.last_message_at ? new Date(c.last_message_at).getTime() : 0;
        if (ts >= startOfToday) buckets.today.push(c);
        else if (ts >= startOfToday - dayMs) buckets.yesterday.push(c);
        else if (ts >= startOfToday - 7 * dayMs) buckets.previous7.push(c);
        else buckets.older.push(c);
    }
    return [
        { key: 'today', label: t('chat.history.today'), items: buckets.today },
        { key: 'yesterday', label: t('chat.history.yesterday'), items: buckets.yesterday },
        { key: 'previous7', label: t('chat.history.previous_7_days'), items: buckets.previous7 },
        { key: 'older', label: t('chat.history.older'), items: buckets.older },
    ].filter((g) => g.items.length > 0);
});

function newChat() {
    router.post('/chat', {}, { preserveScroll: false });
}

function openChat(id: string) {
    if (id === props.activeId) return;
    router.get(`/chat/${id}`, {}, { only: ['activeChat'], preserveState: true, preserveScroll: true });
}

function startRename(c: ChatListItem) {
    editingId.value = c.id;
    editingTitle.value = c.title ?? '';
}

function commitRename(c: ChatListItem) {
    const title = editingTitle.value.trim();
    editingId.value = null;
    if (title === '' || title === (c.title ?? '')) return;
    router.patch(`/chat/${c.id}`, { title }, { only: ['chats', 'activeChat'], preserveScroll: true, preserveState: true });
}

function deleteChat(c: ChatListItem) {
    if (!window.confirm(t('chat.delete_confirm'))) return;
    router.delete(`/chat/${c.id}`, { preserveScroll: true });
}

// Project create/edit dialog.
const projectDialog = ref(false);
const projectEditId = ref<string | null>(null);
const projectName = ref('');
const projectInstructions = ref('');
const projectKbIds = ref<string[]>([]);

function openNewProject() {
    projectEditId.value = null;
    projectName.value = '';
    projectInstructions.value = '';
    projectKbIds.value = [];
    projectDialog.value = true;
}

function openEditProject(p: ChatProjectDto) {
    projectEditId.value = p.id;
    projectName.value = p.name;
    projectInstructions.value = p.custom_instructions ?? '';
    projectKbIds.value = [...(p.knowledge_base_ids ?? [])];
    projectDialog.value = true;
}

function toggleKb(id: string) {
    projectKbIds.value = projectKbIds.value.includes(id)
        ? projectKbIds.value.filter((k) => k !== id)
        : [...projectKbIds.value, id];
}

function saveProject() {
    if (projectName.value.trim() === '') return;
    const payload = {
        name: projectName.value.trim(),
        custom_instructions: projectInstructions.value.trim() || null,
        knowledge_base_ids: projectKbIds.value,
    };
    const opts = {
        only: ['projects'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            projectDialog.value = false;
        },
    };
    if (projectEditId.value) {
        router.patch(`/chat-projects/${projectEditId.value}`, payload, opts);
    } else {
        router.post('/chat-projects', payload, opts);
    }
}

function deleteProject(p: ChatProjectDto) {
    if (!window.confirm(t('chat.delete_project_confirm'))) return;
    if (projectFilter.value === p.id) projectFilter.value = null;
    router.delete(`/chat-projects/${p.id}`, { only: ['projects', 'chats'], preserveScroll: true, preserveState: true });
}
</script>

<template>
    <aside class="flex h-full w-72 shrink-0 flex-col border-r border-soft bg-navy">
        <div class="p-3">
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-xl border border-medium bg-surface px-3 py-2.5 text-sm font-medium text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                @click="newChat"
            >
                <MessageSquarePlus class="size-4 text-accent-blue" />
                {{ t('chat.new_chat') }}
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-2 pb-4">
            <!-- Projects -->
            <div class="mb-2">
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-ink-subtle hover:text-ink-muted"
                    @click="projectsOpen = !projectsOpen"
                >
                    <span>{{ t('chat.projects') }}</span>
                    <ChevronDown :class="['size-3.5 transition-transform', projectsOpen ? '' : '-rotate-90']" />
                </button>
                <div v-if="projectsOpen" class="mt-0.5 space-y-0.5">
                    <div
                        v-for="p in projects"
                        :key="p.id"
                        :class="[
                            'group flex items-center gap-1 rounded-lg pl-2 pr-1 transition-colors',
                            projectFilter === p.id ? 'bg-accent-blue/10' : 'hover:bg-white/5',
                        ]"
                    >
                        <button
                            type="button"
                            :class="[
                                'flex min-w-0 flex-1 items-center gap-2 py-1.5 text-left text-sm transition-colors',
                                projectFilter === p.id ? 'text-ink' : 'text-ink-muted group-hover:text-ink',
                            ]"
                            @click="projectFilter = projectFilter === p.id ? null : p.id"
                        >
                            <FolderClosed class="size-3.5 shrink-0" />
                            <span class="truncate">{{ p.name }}</span>
                            <span
                                v-if="p.knowledge_base_ids.length"
                                :title="t('chat.kb_attached', { count: p.knowledge_base_ids.length })"
                                class="inline-flex items-center gap-0.5 text-[10px] text-ink-subtle"
                            >
                                <Database class="size-3" />{{ p.knowledge_base_ids.length }}
                            </span>
                        </button>
                        <button
                            type="button"
                            class="shrink-0 rounded p-1 text-ink-subtle opacity-0 transition-opacity hover:text-ink group-hover:opacity-100"
                            :title="t('chat.edit_project')"
                            @click.stop="openEditProject(p)"
                        >
                            <Settings2 class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            class="shrink-0 rounded p-1 text-ink-subtle opacity-0 transition-opacity hover:text-sp-danger group-hover:opacity-100"
                            :title="t('chat.delete')"
                            @click.stop="deleteProject(p)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-ink-subtle transition-colors hover:bg-white/5 hover:text-ink"
                        @click="openNewProject"
                    >
                        <Plus class="size-3.5" />
                        {{ t('chat.new_project') }}
                    </button>
                </div>
            </div>

            <!-- History -->
            <div v-for="g in groups" :key="g.key" class="mb-3">
                <p class="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-ink-subtle">
                    {{ g.label }}
                </p>
                <div class="space-y-0.5">
                    <div
                        v-for="c in g.items"
                        :key="c.id"
                        :class="[
                            'group flex items-center gap-1 rounded-lg pl-2 pr-1 transition-colors',
                            c.id === activeId ? 'bg-accent-blue/10' : 'hover:bg-white/5',
                        ]"
                    >
                        <template v-if="editingId === c.id">
                            <input
                                v-model="editingTitle"
                                class="min-w-0 flex-1 rounded bg-transparent py-1.5 text-sm text-ink focus:outline-none"
                                autofocus
                                @keydown.enter="commitRename(c)"
                                @keydown.esc="editingId = null"
                                @blur="commitRename(c)"
                            />
                            <button type="button" class="rounded p-1 text-ink-subtle hover:text-ink" @mousedown.prevent="commitRename(c)">
                                <Check class="size-3.5" />
                            </button>
                        </template>
                        <template v-else>
                            <button
                                type="button"
                                class="min-w-0 flex-1 truncate py-1.5 text-left text-sm text-ink-muted group-hover:text-ink"
                                :class="{ 'font-medium text-ink': c.id === activeId }"
                                @click="openChat(c.id)"
                            >
                                {{ c.title || t('chat.untitled') }}
                            </button>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-ink-subtle opacity-0 transition-opacity hover:text-ink group-hover:opacity-100"
                                :title="t('chat.rename')"
                                @click.stop="startRename(c)"
                            >
                                <Pencil class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-ink-subtle opacity-0 transition-opacity hover:text-sp-danger group-hover:opacity-100"
                                :title="t('chat.delete')"
                                @click.stop="deleteChat(c)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <p v-if="!chats.length" class="px-2 py-6 text-center text-xs text-ink-subtle">
                {{ t('chat.no_history') }}
            </p>
        </div>

        <WorkspaceSwitcher active="chat" />

        <Dialog v-model:open="projectDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ projectEditId ? t('chat.edit_project') : t('chat.new_project') }}</DialogTitle>
                    <DialogDescription>{{ t('chat.project_dialog_hint') }}</DialogDescription>
                </DialogHeader>
                <div class="space-y-3">
                    <input
                        v-model="projectName"
                        :placeholder="t('chat.project_name_placeholder')"
                        class="h-9 w-full rounded-lg border border-medium bg-surface px-3 text-sm text-ink placeholder:text-ink-subtle focus:border-strong focus:outline-none"
                    />
                    <textarea
                        v-model="projectInstructions"
                        :placeholder="t('chat.project_instructions_placeholder')"
                        rows="3"
                        class="w-full resize-none rounded-lg border border-medium bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-subtle focus:border-strong focus:outline-none"
                    />

                    <!-- Knowledge bases -->
                    <div>
                        <p class="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-ink-muted">
                            <Database class="size-3.5" />
                            {{ t('chat.project_knowledge') }}
                        </p>
                        <p v-if="!knowledgeBases.length" class="rounded-lg border border-dashed border-medium px-3 py-2.5 text-xs text-ink-subtle">
                            {{ t('chat.no_knowledge_bases') }}
                        </p>
                        <div v-else class="max-h-44 space-y-0.5 overflow-y-auto rounded-lg border border-medium p-1">
                            <button
                                v-for="kb in knowledgeBases"
                                :key="kb.id"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors hover:bg-white/5"
                                @click="toggleKb(kb.id)"
                            >
                                <span
                                    :class="[
                                        'flex size-4 shrink-0 items-center justify-center rounded border',
                                        projectKbIds.includes(kb.id) ? 'border-accent-blue bg-accent-blue text-white' : 'border-medium',
                                    ]"
                                >
                                    <Check v-if="projectKbIds.includes(kb.id)" class="size-3" />
                                </span>
                                <span class="min-w-0 flex-1 truncate text-ink">{{ kb.name }}</span>
                                <span class="shrink-0 text-[10px] text-ink-subtle">{{ kb.document_count }} docs</span>
                            </button>
                        </div>
                        <p class="mt-1.5 text-[11px] text-ink-subtle">{{ t('chat.project_knowledge_hint') }}</p>
                    </div>
                </div>
                <DialogFooter>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink hover:bg-surface-hover"
                        @click="projectDialog = false"
                    >
                        <X class="size-3.5" />
                        {{ t('common.cancel') }}
                    </button>
                    <button
                        type="button"
                        :disabled="projectName.trim() === ''"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary hover:bg-accent-blue-hover disabled:opacity-40"
                        @click="saveProject"
                    >
                        <Check class="size-3.5" />
                        {{ projectEditId ? t('common.save') : t('chat.create_project') }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </aside>
</template>
