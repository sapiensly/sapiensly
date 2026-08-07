<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getInitials } from '@/composables/useInitials';
import { Link, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, Eye, FlaskConical, LogOut } from '@lucide/vue';
import { computed, inject } from 'vue';
import { switchTo } from './previewSwitch';
import { runtimeWord } from './words';

/**
 * The default user widget for an app's main nav: shows who's signed in and folds
 * the "exit to Sapiensly" action into a menu, replacing the old standalone bar.
 * Renders nothing for an anonymous viewer (public apps).
 *
 * It also holds the two ways of looking at the app as somebody else — opening
 * the demo, and previewing a role. Those had a row of their own above the
 * header, which spent a line of every screen (on a phone, a tenth of it) to
 * tell an administrator that nothing was wrong. Whether a pretence is ON is
 * worth a bar; the way IN to one is a thing you do as yourself, and this is
 * where the other things you do as yourself already live.
 */
const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const initials = computed(() => getInitials(user.value?.name ?? '') || '·');

interface PreviewOptions {
    environment: string;
    canSwitchEnvironment: boolean;
    role: string | null;
    roles: Array<{ slug: string; name: string }>;
}

/**
 * Injected rather than passed: this widget is mounted by SiteHeader and by
 * SiteSidebar, and threading four props through both to reach a dropdown is
 * four props that exist only to be forwarded. Absent outside a runtime page,
 * where the menu is just the sign-out.
 */
const preview = inject<PreviewOptions | null>('previewOptions', null);

/**
 * Read separately from the preview options, because the sign-out is here even
 * on a page that never offers a pretence — a public app, a portal — and it was
 * hardcoded Spanish until now. Taking the language from something optional
 * would have kept that bug for exactly those readers.
 */
const locale = inject<string | undefined>('runtimeLocale', undefined);

const word = (key: string) => runtimeWord(locale, key);

const canOpenDemo = computed(
    () =>
        preview !== null &&
        preview.canSwitchEnvironment &&
        preview.environment !== 'demo',
);

const canPreviewRoles = computed(() => (preview?.roles.length ?? 0) > 0);

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

            <template v-if="canPreviewRoles || canOpenDemo">
                <DropdownMenuSeparator />

                <!-- A submenu rather than a list of roles inline: an app with
                     six of them would otherwise push sign-out off the bottom of
                     the menu on a phone. -->
                <DropdownMenuSub v-if="canPreviewRoles">
                    <DropdownMenuSubTrigger data-sp-role-open>
                        <Eye class="mr-2 size-4" />
                        {{ word('preview_role_pick') }}
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent class="w-56">
                        <DropdownMenuItem
                            data-sp-role-set=""
                            class="cursor-pointer"
                            @select="switchTo('as_role', null)"
                        >
                            <Check
                                class="mr-2 size-4"
                                :class="preview?.role ? 'opacity-0' : ''"
                            />
                            {{ word('preview_role_none') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-for="r in preview?.roles ?? []"
                            :key="r.slug"
                            :data-sp-role-set="r.slug"
                            class="cursor-pointer"
                            @select="switchTo('as_role', r.slug)"
                        >
                            <Check
                                class="mr-2 size-4"
                                :class="
                                    preview?.role === r.slug ? '' : 'opacity-0'
                                "
                            />
                            {{ r.name }}
                        </DropdownMenuItem>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>

                <DropdownMenuItem
                    v-if="canOpenDemo"
                    data-sp-environment-switch="demo"
                    class="cursor-pointer"
                    @select="switchTo('env', 'demo')"
                >
                    <FlaskConical class="mr-2 size-4" />
                    {{ word('demo_enter') }}
                </DropdownMenuItem>
            </template>

            <DropdownMenuSeparator />
            <DropdownMenuItem :as-child="true">
                <Link
                    href="/apps"
                    as="button"
                    class="block w-full cursor-pointer"
                >
                    <LogOut class="mr-2 h-4 w-4" />
                    {{ word('exit_to_platform') }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
