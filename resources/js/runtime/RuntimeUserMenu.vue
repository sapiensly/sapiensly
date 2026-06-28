<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getInitials } from '@/composables/useInitials';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, LogOut } from '@lucide/vue';
import { computed } from 'vue';

/**
 * The default user widget for an app's main nav: shows who's signed in and folds
 * the "exit to Sapiensly" action into a menu, replacing the old standalone bar.
 * Renders nothing for an anonymous viewer (public apps).
 */
const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const initials = computed(() => getInitials(user.value?.name ?? '') || '·');

// Compact = avatar only (no name/chevron), for a collapsed sidebar rail.
defineProps<{ compact?: boolean }>();
</script>

<template>
    <DropdownMenu v-if="user">
        <DropdownMenuTrigger
            class="inline-flex items-center gap-1.5 rounded-pill border text-sm transition-colors outline-none"
            :class="compact ? 'p-1' : 'py-1 pr-2 pl-1'"
            :style="{
                borderColor:
                    'color-mix(in srgb, currentColor 14%, transparent)',
                backgroundColor:
                    'color-mix(in srgb, currentColor 4%, transparent)',
            }"
        >
            <span
                class="grid size-6 place-items-center rounded-full text-[11px] font-semibold text-white"
                :style="{ backgroundColor: 'var(--sp-accent, #3b82f6)' }"
            >
                {{ initials }}
            </span>
            <span
                v-if="!compact"
                class="hidden max-w-[10rem] truncate sm:inline"
                >{{ user.name }}</span
            >
            <ChevronDown v-if="!compact" class="size-3.5 opacity-60" />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel class="p-0 font-normal">
                <div class="flex flex-col px-2 py-1.5 text-left">
                    <span class="truncate text-sm font-medium">{{
                        user.name
                    }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{
                        user.email
                    }}</span>
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem :as-child="true">
                <Link
                    href="/apps"
                    as="button"
                    class="block w-full cursor-pointer"
                >
                    <LogOut class="mr-2 h-4 w-4" />
                    Salir a Sapiensly
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
