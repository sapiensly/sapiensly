<script setup lang="ts">
import { Bell } from '@lucide/vue';
import axios from 'axios';
import { onMounted, ref } from 'vue';

/**
 * The in-app inbox `notify.send` writes to.
 *
 * A notification with nowhere to appear is worse than no notification at all —
 * the author believes people were told. This is where they actually see it.
 *
 * Loaded on mount and refreshed when opened, deliberately without polling: a
 * runtime page is often left open for hours, and a timer per open tab is a cost
 * every tenant pays for a feature most of them use rarely.
 */
const props = defineProps<{ appSlug: string }>();

interface Item {
    id: string;
    title: string;
    body: string | null;
    link: string | null;
    read: boolean;
    created_at: string | null;
}

const open = ref(false);
const items = ref<Item[]>([]);
const unread = ref(0);
const loaded = ref(false);

async function load() {
    try {
        const { data } = await axios.get(`/r/${props.appSlug}/notifications`);
        items.value = data.notifications ?? [];
        unread.value = data.unread ?? 0;
        loaded.value = true;
    } catch {
        // A failed inbox must never break the page it hangs off.
        loaded.value = true;
    }
}

async function toggle() {
    open.value = !open.value;
    if (open.value) await load();
}

async function markAllRead() {
    try {
        const { data } = await axios.post(
            `/r/${props.appSlug}/notifications/read`,
            {},
        );
        unread.value = data.unread ?? 0;
        items.value = items.value.map((i) => ({ ...i, read: true }));
    } catch {
        /* leave the badge as it was */
    }
}

onMounted(load);
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="relative inline-flex size-9 items-center justify-center rounded-full text-current opacity-70 transition-opacity hover:opacity-100"
            :aria-label="`Notificaciones${unread ? ` (${unread})` : ''}`"
            :aria-expanded="open"
            @click="toggle"
        >
            <Bell class="size-4" />
            <span
                v-if="unread > 0"
                class="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-4 font-semibold text-white"
                :style="{ backgroundColor: 'var(--sp-accent, #3b82f6)' }"
            >
                {{ unread > 9 ? '9+' : unread }}
            </span>
        </button>

        <!-- Click-away backdrop: transparent, so the panel closes without a
             document-level listener that would fight the runtime's own. -->
        <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />

        <div
            v-if="open"
            class="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-black/10 bg-white shadow-xl dark:border-white/10 dark:bg-neutral-900"
        >
            <div
                class="flex items-center justify-between border-b border-black/5 px-4 py-2.5 dark:border-white/10"
            >
                <span
                    class="text-xs font-medium text-neutral-700 dark:text-neutral-200"
                >
                    Notificaciones
                </span>
                <button
                    v-if="unread > 0"
                    type="button"
                    class="text-[11px] text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"
                    @click="markAllRead"
                >
                    Marcar todas leídas
                </button>
            </div>

            <p
                v-if="loaded && items.length === 0"
                class="px-4 py-6 text-center text-xs text-neutral-400"
            >
                Nada por ahora.
            </p>

            <ul v-else class="max-h-80 overflow-y-auto">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="border-b border-black/5 last:border-0 dark:border-white/10"
                >
                    <component
                        :is="item.link ? 'a' : 'div'"
                        :href="item.link ?? undefined"
                        class="block px-4 py-3"
                        :class="
                            item.link
                                ? 'hover:bg-black/[0.03] dark:hover:bg-white/[0.04]'
                                : ''
                        "
                    >
                        <div class="flex items-start gap-2">
                            <span
                                class="mt-1.5 size-1.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: item.read
                                        ? 'transparent'
                                        : 'var(--sp-accent, #3b82f6)',
                                }"
                            />
                            <div class="min-w-0">
                                <p
                                    class="truncate text-xs font-medium text-neutral-800 dark:text-neutral-100"
                                >
                                    {{ item.title }}
                                </p>
                                <p
                                    v-if="item.body"
                                    class="mt-0.5 line-clamp-2 text-[11px] text-neutral-500"
                                >
                                    {{ item.body }}
                                </p>
                            </div>
                        </div>
                    </component>
                </li>
            </ul>
        </div>
    </div>
</template>
