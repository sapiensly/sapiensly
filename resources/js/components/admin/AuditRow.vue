<script setup lang="ts">
import type { AuditEntry } from '@/lib/admin/types';
import {
    Activity,
    Ban,
    Eye,
    Library,
    RefreshCw as Refresh,
    SlidersHorizontal as Sliders,
} from '@/lib/admin/icons';
import { User } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed } from 'vue';

interface Props {
    entry: AuditEntry;
}

const props = defineProps<Props>();

const iconMap: Record<string, Component> = {
    sliders: Sliders,
    library: Library,
    user: User,
    eye: Eye,
    refresh: Refresh,
    ban: Ban,
};

const icon = computed<Component>(() => iconMap[props.entry.icon] ?? Activity);

const timeAgo = computed(() => {
    const then = new Date(props.entry.at).getTime();
    const now = Date.now();
    const s = Math.round((now - then) / 1000);
    if (s < 60) return `${s}s ago`;
    const m = Math.round(s / 60);
    if (m < 60) return `${m}m ago`;
    const h = Math.round(m / 60);
    if (h < 24) return `${h}h ago`;
    return `${Math.round(h / 24)}d ago`;
});
</script>

<template>
    <div class="flex items-start gap-3 px-1 py-2.5">
        <div
            class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
        >
            <component :is="icon" class="size-4" />
        </div>

        <div class="min-w-0 flex-1 leading-snug">
            <p class="text-sm">
                <span class="font-medium text-ink">{{ entry.actor.name }}</span>
                <span class="mx-1 text-ink-muted">{{ entry.action }}</span>
                <Link
                    v-if="entry.targetHref"
                    :href="entry.targetHref"
                    class="text-accent-blue hover:underline"
                >
                    {{ entry.target }}
                </Link>
                <span v-else class="text-accent-blue">
                    {{ entry.target }}
                </span>
            </p>
            <p v-if="entry.context" class="truncate text-xs text-ink-muted">
                {{ entry.context }}
            </p>
        </div>

        <span class="shrink-0 text-[11px] text-ink-subtle">{{ timeAgo }}</span>
    </div>
</template>
