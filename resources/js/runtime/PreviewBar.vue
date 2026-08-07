<script setup lang="ts">
/**
 * "What you are looking at is not the real thing."
 *
 * ONE bar, because the two it replaces were saying one sentence between them.
 * A demo environment and a role preview are the same warning — the page in
 * front of you is not what it would be — and stacked they took a quarter of a
 * phone screen before any of the app appeared.
 *
 * It stays a BAR rather than becoming a quiet dropdown, for the reason both of
 * them were bars: while either mode is on, every number on the page is fiction
 * or every permission is narrowed, and somebody who forgets reports invented
 * figures in a meeting or files a bug against the wrong thing. The cost of
 * missing it is asymmetric. What changed is the SIZE of the reminder, not
 * whether there is one.
 *
 * THE COLOUR IS THE OTHER HALF. It used to be `text-amber-300` on
 * `bg-amber-500/10` — a pair chosen for the dark theme, printed on a light app
 * at about 1.5:1, which is not "hard to read" but "not legible". The palette
 * now has both themes, and the runtime follows the platform's `.dark` class, so
 * `dark:` variants land where the tokens do.
 *
 * Switching is a full page load in both cases: the environment and the previewed
 * role are server-side decisions, so the server has to make them again.
 */
import { router } from '@inertiajs/vue3';
import {
    Eye,
    FlaskConical,
    MoreHorizontal,
    RotateCcw,
    Sparkles,
} from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import { runtimeWord } from './words';

const props = defineProps<{
    /** 'demo' or 'production'. */
    environment: string;
    canSwitchEnvironment: boolean;
    /** The role being previewed as, or null for "no restrictions". */
    role: string | null;
    roles: Array<{ slug: string; name: string }>;
    locale?: string;
    /** Needed only to seed or empty the sandbox. */
    appSlug?: string;
}>();

const word = (key: string, replace: Record<string, string> = {}) =>
    runtimeWord(props.locale, key, replace);

const inDemo = computed(() => props.environment === 'demo');
const canPreviewRoles = computed(() => props.roles.length > 0);

/** Whether the page is lying about something, in either of the two ways. */
const pretending = computed(() => inDemo.value || props.role !== null);

const roleName = computed(
    () =>
        props.roles.find((r) => r.slug === props.role)?.name ??
        props.role ??
        '',
);

const menuOpen = ref(false);
const seeding = ref(false);
const resetting = ref(false);

/**
 * Emptying the sandbox asks twice.
 *
 * It has moved into the menu — a destructive action does not belong at the same
 * weight as the way out — but it keeps its own confirm step, because the server
 * refusing it outside the demo is the second lock, not the only one.
 */
const confirming = ref(false);

function seed(): void {
    if (!props.appSlug) return;
    seeding.value = true;
    router.post(
        `/r/${props.appSlug}/environment/seed`,
        {},
        { onFinish: () => ((seeding.value = false), (menuOpen.value = false)) },
    );
}

function reset(): void {
    if (!props.appSlug) return;
    if (!confirming.value) {
        confirming.value = true;

        return;
    }

    confirming.value = false;
    resetting.value = true;
    router.post(
        `/r/${props.appSlug}/environment/reset`,
        {},
        {
            onFinish: () => (
                (resetting.value = false),
                (menuOpen.value = false)
            ),
        },
    );
}

/** Both switches are the same move: set a query parameter and reload. */
function go(param: string, value: string | null): void {
    const url = new URL(window.location.href);
    if (value === null || value === '') {
        url.searchParams.delete(param);
    } else {
        url.searchParams.set(param, value);
    }
    window.location.href = url.toString();
}

function onDocumentClick(): void {
    menuOpen.value = false;
    confirming.value = false;
}

function toggleMenu(): void {
    menuOpen.value = !menuOpen.value;
    if (menuOpen.value) {
        // Next tick would still catch the click that opened it.
        setTimeout(
            () =>
                document.addEventListener('click', onDocumentClick, {
                    once: true,
                }),
            0,
        );
    }
}

onUnmounted(() => document.removeEventListener('click', onDocumentClick));

/**
 * Amber that reads on both surfaces. Deep ink on a pale wash in light, a bright
 * tone on a dim wash in dark — the same relationship, twice.
 */
const warn =
    'border-amber-400/70 bg-amber-100 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200';
</script>

<template>
    <!--
        ACTIVE: one line. The chips say which pretence is on; the button beside
        them is the way OUT, which is the only action that belongs at full
        weight. Everything else is behind the menu.
    -->
    <div
        v-if="pretending"
        data-sp-preview-bar="on"
        :data-sp-environment="inDemo ? 'demo' : undefined"
        :class="[
            'mb-2 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border px-2.5 py-1.5 text-xs',
            warn,
        ]"
    >
        <span
            v-if="inDemo"
            class="inline-flex items-center gap-1.5 font-medium"
        >
            <FlaskConical class="size-3.5 shrink-0" />
            {{ word('demo_banner') }}
        </span>

        <span v-if="role" class="inline-flex items-center gap-1.5 font-medium">
            <Eye class="size-3.5 shrink-0" />
            {{ word('preview_role_as', { role: roleName }) }}
        </span>

        <!-- The sentence that explains the chip, where there is room for it. On
             a phone the chip is the whole message: «Demo» said once is enough,
             and said on every screen forever it is just height. -->
        <span class="hidden opacity-80 sm:inline">{{
            inDemo ? word('demo_explains') : word('preview_role_explains')
        }}</span>

        <span class="ml-auto flex items-center gap-1.5">
            <button
                v-if="inDemo && canSwitchEnvironment"
                type="button"
                data-sp-environment-switch="production"
                class="rounded-pill border border-current/40 px-2.5 py-0.5 font-medium transition-colors hover:bg-amber-500/20"
                @click="go('env', 'production')"
            >
                {{ word('demo_leave') }}
            </button>
            <button
                v-else-if="role"
                type="button"
                data-sp-role-exit
                class="rounded-pill border border-current/40 px-2.5 py-0.5 font-medium transition-colors hover:bg-amber-500/20"
                @click="go('as_role', null)"
            >
                {{ word('preview_role_exit') }}
            </button>

            <!-- Everything that is not the way out. A reset that empties the
                 sandbox sat beside it at the same weight; one of those two is
                 destructive and the other is an exit. -->
            <div v-if="canPreviewRoles || appSlug" class="relative">
                <button
                    type="button"
                    data-sp-preview-menu
                    :aria-label="word('preview_more')"
                    :aria-expanded="menuOpen"
                    class="grid size-6 place-items-center rounded-pill border border-current/40 transition-colors hover:bg-amber-500/20"
                    @click.stop="toggleMenu"
                >
                    <MoreHorizontal class="size-3.5" />
                </button>

                <div
                    v-if="menuOpen"
                    role="menu"
                    class="absolute right-0 z-40 mt-1 w-56 rounded-md border border-soft bg-navy p-1 text-ink shadow-lg"
                    @click.stop
                >
                    <button
                        v-if="inDemo && appSlug"
                        type="button"
                        data-sp-environment-seed
                        :disabled="seeding || resetting"
                        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left transition-colors hover:bg-surface disabled:opacity-50"
                        @click="seed()"
                    >
                        <Sparkles class="size-3.5 shrink-0" />
                        {{ word('demo_seed') }}
                    </button>
                    <button
                        v-if="inDemo && appSlug"
                        type="button"
                        data-sp-environment-reset
                        :disabled="resetting || seeding"
                        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sp-danger transition-colors hover:bg-surface disabled:opacity-50"
                        @click="reset()"
                    >
                        <RotateCcw class="size-3.5 shrink-0" />
                        {{
                            confirming
                                ? word('demo_reset_sure')
                                : word('demo_reset')
                        }}
                    </button>

                    <div v-if="canPreviewRoles" class="px-2 pt-2 pb-1">
                        <label class="mb-1 block text-[11px] text-ink-muted">
                            {{ word('preview_role_pick') }}
                        </label>
                        <select
                            class="w-full rounded-md border border-medium bg-surface px-2 py-1 text-xs text-ink"
                            :value="role ?? ''"
                            @change="
                                go(
                                    'as_role',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">
                                {{ word('preview_role_none') }}
                            </option>
                            <option
                                v-for="r in roles"
                                :key="r.slug"
                                :value="r.slug"
                            >
                                {{ r.name }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>
        </span>
    </div>

    <!--
        IDLE: the ways IN, and nothing else.

        A quiet right-aligned line rather than a bar, because there is nothing
        to warn about — the old role bar rendered its whole row, select and all,
        even with no preview running, which is how «Ver la app como… / Sin
        restricciones (tú)» came to occupy a tenth of a phone screen saying that
        everything was normal.
    -->
    <div
        v-else-if="canSwitchEnvironment || canPreviewRoles"
        data-sp-preview-bar="off"
        data-sp-environment="production"
        class="relative mb-2 flex items-center justify-end gap-3 text-[11px] text-ink-subtle"
    >
        <button
            v-if="canPreviewRoles"
            type="button"
            data-sp-role-open
            class="inline-flex items-center gap-1.5 transition-opacity hover:opacity-100"
            @click.stop="toggleMenu"
        >
            <Eye class="size-3" />
            {{ word('preview_role_pick') }}
        </button>
        <button
            v-if="canSwitchEnvironment"
            type="button"
            data-sp-environment-switch="demo"
            class="inline-flex items-center gap-1.5 transition-opacity hover:opacity-100"
            @click="go('env', 'demo')"
        >
            <FlaskConical class="size-3" />
            {{ word('demo_enter') }}
        </button>

        <div
            v-if="menuOpen && canPreviewRoles"
            role="menu"
            class="absolute right-[var(--sp-bleed,1.25rem)] z-40 mt-6 w-56 rounded-md border border-soft bg-navy p-2 text-ink shadow-lg"
            @click.stop
        >
            <select
                class="w-full rounded-md border border-medium bg-surface px-2 py-1 text-xs text-ink"
                :value="role ?? ''"
                @change="
                    go('as_role', ($event.target as HTMLSelectElement).value)
                "
            >
                <option value="">{{ word('preview_role_none') }}</option>
                <option v-for="r in roles" :key="r.slug" :value="r.slug">
                    {{ r.name }}
                </option>
            </select>
        </div>
    </div>
</template>
