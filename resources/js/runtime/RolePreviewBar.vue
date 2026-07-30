<script setup lang="ts">
import { Eye } from '@lucide/vue';
import { computed } from 'vue';

/**
 * "You are looking at this app as ROLE."
 *
 * Shown only to someone who would otherwise bypass every policy, because they
 * are the only ones the server honours the preview for. It is deliberately a
 * BAR and not a quiet dropdown: while a preview is active the page is lying
 * about what this person can reach, and that has to be impossible to forget —
 * an admin who mistakes a narrowed view for a broken app files the bug against
 * the wrong thing.
 *
 * Navigation is a plain full page load: the role is a server-side decision, so
 * the server has to make it again.
 */
const props = defineProps<{
    current: string | null;
    roles: Array<{ slug: string; name: string }>;
}>();

const currentName = computed(
    () =>
        props.roles.find((r) => r.slug === props.current)?.name ??
        props.current,
);

function view(slug: string): void {
    const url = new URL(window.location.href);
    if (slug) {
        url.searchParams.set('as_role', slug);
    } else {
        url.searchParams.delete('as_role');
    }
    window.location.href = url.toString();
}
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-2 border-b px-[var(--sp-bleed,1.25rem)] py-2 text-xs"
        :class="
            current
                ? 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400'
                : 'border-transparent'
        "
    >
        <span v-if="current" class="flex items-center gap-1.5 font-medium">
            <Eye class="size-3.5" />
            {{ `Viendo como «${currentName}» — así ve la app este rol.` }}
        </span>
        <span v-else class="flex items-center gap-1.5 text-ink-muted">
            <Eye class="size-3.5" />
            Ver la app como…
        </span>

        <div class="flex items-center gap-1.5">
            <select
                class="rounded-md border border-medium bg-surface px-2 py-1 text-xs text-ink"
                :value="current ?? ''"
                @change="view(($event.target as HTMLSelectElement).value)"
            >
                <option value="">Sin restricciones (tú)</option>
                <option v-for="r in props.roles" :key="r.slug" :value="r.slug">
                    {{ r.name }}
                </option>
            </select>
        </div>
    </div>
</template>
