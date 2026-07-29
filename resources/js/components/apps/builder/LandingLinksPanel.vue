<script setup lang="ts">
/**
 * Where the landing's buttons go — the whole page at once, grouped by
 * destination.
 *
 * The grouping is the feature. On a real rebuilt landing, nine anchors pointed
 * at `#waitlist` across five sections: "send the primary CTA to sign-up" is ONE
 * intention, and editing it nine times is nine chances to leave the page half
 * changed. Editing a group here retargets every link in it in a single
 * versioned patch; expanding it splits one link off from the rest.
 *
 * It also answers the question nobody thinks to ask until a visitor clicks:
 * which links go nowhere. Placeholder `href="#"` and `<button>` CTAs (which the
 * sanitiser forces inert — a button on a landing can never navigate) both land
 * in the "no destination" group, sorted to the top.
 */
import {
    AlertTriangle,
    ChevronDown,
    ChevronRight,
    ExternalLink,
    Hash,
    Link2,
    Loader2,
    Mail,
    Phone,
    Slash,
    X,
} from '@lucide/vue';
import axios from 'axios';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';

type LinkRow = {
    id: string;
    block_id: string;
    label: string;
    element: 'a' | 'button';
};
type LinkGroup = {
    target: string;
    kind: 'none' | 'anchor' | 'internal' | 'external' | 'email' | 'phone';
    count: number;
    inert_count: number;
    links: LinkRow[];
};

const props = defineProps<{
    appId: string;
    /**
     * The destination to open the editor on — set when the author reached the
     * panel by clicking a control in the preview. '' is the no-destination
     * group; null opens the plain list.
     */
    focusTarget?: string | null;
}>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>();

const { t } = useI18n();

const loading = ref(true);
const busy = ref(false);
const groups = ref<LinkGroup[]>([]);
const anchors = ref<string[]>([]);
/** What is being retargeted: a whole group, or one link split off from it. */
const editing = ref<{ key: string; ids: string[] } | null>(null);
const draft = ref('');
const error = ref('');
/** Groups whose individual links are listed. */
const expanded = ref<Set<string>>(new Set());
const inputEl = ref<HTMLInputElement | null>(null);

const KIND_ICON = {
    none: Slash,
    anchor: Hash,
    internal: Link2,
    external: ExternalLink,
    email: Mail,
    phone: Phone,
} as const;

const broken = computed(() =>
    groups.value
        .filter((g) => g.kind === 'none')
        .reduce((n, g) => n + g.count, 0),
);

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get(`/apps/${props.appId}/builder/links`);
        groups.value = data.groups ?? [];
        anchors.value = data.anchors ?? [];
        openFocusedGroup();
    } catch {
        toast.error(t('apps.builder.links_load_failed'));
        emit('close');
    } finally {
        loading.value = false;
    }
}
load();

/**
 * Arriving from a click in the preview: open that control's group straight
 * away, so the gesture is "click the button, type where it goes".
 */
let pendingFocus: string | null | undefined = props.focusTarget;
function openFocusedGroup() {
    if (pendingFocus == null) return;
    const group = groups.value.find((g) => g.target === pendingFocus);
    pendingFocus = null;
    if (!group) return;
    expanded.value = new Set([...expanded.value, group.target]);
    startEdit(
        'g:' + group.target,
        group.links.map((l) => l.id),
        group.target,
    );
}
// Clicking another control while the panel is already open re-aims it.
watch(
    () => props.focusTarget,
    (target) => {
        pendingFocus = target;
        openFocusedGroup();
    },
);

function startEdit(key: string, ids: string[], current: string) {
    editing.value = { key, ids };
    // A dead link opens empty: its current value is the placeholder we are here
    // to replace, so offering it as a starting point would be a joke.
    draft.value = current;
    error.value = '';
    nextTick(() => inputEl.value?.focus());
}

function toggleExpanded(target: string) {
    const next = new Set(expanded.value);
    if (next.has(target)) {
        next.delete(target);
    } else {
        next.add(target);
    }
    expanded.value = next;
}

/** The same rule the server enforces (which is the sanitiser's own). */
function isValidTarget(value: string): boolean {
    const v = value.trim();
    if (v === '' || v === '#') return false;
    if (v.startsWith('//')) return false;
    if (v.startsWith('#') || v.startsWith('/')) return true;
    const colon = v.indexOf(':');
    if (colon === -1) return true;
    return ['http', 'https', 'mailto', 'tel'].includes(
        v.slice(0, colon).toLowerCase(),
    );
}

async function submit() {
    const target = editing.value;
    if (!target || busy.value) return;
    if (!isValidTarget(draft.value)) {
        error.value = t('apps.builder.links_invalid_target');
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/links/retarget`,
            { link_ids: target.ids, to: draft.value.trim() },
        );
        editing.value = null;
        emit('changed');
        toast.success(
            t('apps.builder.links_retargeted', { count: data.changed ?? 0 }),
        );
        await load();
    } catch (e) {
        error.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message || t('apps.builder.section_action_failed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <aside
        data-sp-links-panel
        class="fixed inset-y-0 right-0 z-[70] flex w-[min(24rem,100vw)] flex-col border-l border-soft bg-navy shadow-2xl"
    >
        <header
            class="flex items-center justify-between gap-2 border-b border-soft px-4 py-3"
        >
            <div class="flex min-w-0 items-center gap-2">
                <Link2 class="size-4 shrink-0 text-ink-muted" />
                <h2 class="truncate text-sm font-semibold text-ink">
                    {{ t('apps.builder.links_title') }}
                </h2>
            </div>
            <button
                type="button"
                class="flex size-6 shrink-0 items-center justify-center rounded-pill text-ink-muted transition-colors hover:bg-surface-hover hover:text-ink"
                :title="t('apps.builder.style_done')"
                @click="emit('close')"
            >
                <X class="size-4" />
            </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-3 py-3">
            <div
                v-if="loading"
                class="flex items-center justify-center gap-2 py-10 text-xs text-ink-muted"
            >
                <Loader2 class="size-4 animate-spin" />
                {{ t('apps.builder.links_loading') }}
            </div>

            <p
                v-else-if="!groups.length"
                class="px-1 py-8 text-center text-xs text-ink-muted"
            >
                {{ t('apps.builder.links_empty') }}
            </p>

            <template v-else>
                <p
                    v-if="broken"
                    class="mb-3 flex items-start gap-2 rounded-sp-sm border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-[11px] leading-relaxed text-amber-200"
                >
                    <AlertTriangle class="mt-px size-3.5 shrink-0" />
                    <span>{{
                        t('apps.builder.links_broken_warning', {
                            count: broken,
                        })
                    }}</span>
                </p>

                <ul class="flex flex-col gap-2">
                    <li
                        v-for="group in groups"
                        :key="group.target || '__none__'"
                        class="rounded-sp-sm border bg-surface"
                        :class="
                            group.kind === 'none'
                                ? 'border-amber-500/30'
                                : 'border-soft'
                        "
                    >
                        <div class="flex items-center gap-2 px-3 py-2.5">
                            <component
                                :is="KIND_ICON[group.kind]"
                                class="size-3.5 shrink-0"
                                :class="
                                    group.kind === 'none'
                                        ? 'text-amber-300'
                                        : 'text-ink-muted'
                                "
                            />
                            <span
                                class="min-w-0 flex-1 truncate font-mono text-[11px]"
                                :class="
                                    group.kind === 'none'
                                        ? 'text-amber-200 italic'
                                        : 'text-ink'
                                "
                                :title="group.target"
                            >
                                {{
                                    group.target ||
                                    t('apps.builder.links_no_target')
                                }}
                            </span>
                            <button
                                type="button"
                                class="shrink-0 rounded-pill border border-medium px-2 py-0.5 text-[10px] text-ink-muted transition-colors hover:border-strong hover:text-ink"
                                :title="t('apps.builder.links_expand')"
                                @click="toggleExpanded(group.target)"
                            >
                                <component
                                    :is="
                                        expanded.has(group.target)
                                            ? ChevronDown
                                            : ChevronRight
                                    "
                                    class="mr-0.5 inline size-3 align-[-1px]"
                                />
                                {{ group.count }}
                            </button>
                            <button
                                type="button"
                                class="shrink-0 rounded-pill border border-accent-blue/40 bg-accent-blue/10 px-2 py-0.5 text-[10px] font-semibold text-accent-blue transition-colors hover:bg-accent-blue/20"
                                @click="
                                    startEdit(
                                        'g:' + group.target,
                                        group.links.map((l) => l.id),
                                        group.target,
                                    )
                                "
                            >
                                {{ t('apps.builder.links_edit') }}
                            </button>
                        </div>

                        <p
                            v-if="group.inert_count"
                            class="border-t border-soft px-3 py-1.5 text-[10px] leading-relaxed text-amber-200/80"
                        >
                            {{
                                t('apps.builder.links_inert_button', {
                                    count: group.inert_count,
                                })
                            }}
                        </p>

                        <!-- Editing the group: one destination, every link in it. -->
                        <div
                            v-if="editing?.key === 'g:' + group.target"
                            class="border-t border-soft px-3 py-2.5"
                        >
                            <label
                                class="mb-1 block text-[10px] tracking-wide text-ink-subtle uppercase"
                            >
                                {{
                                    t('apps.builder.links_new_target', {
                                        count: group.count,
                                    })
                                }}
                            </label>
                            <input
                                :ref="
                                    (el) => {
                                        if (el)
                                            inputEl = el as HTMLInputElement;
                                    }
                                "
                                v-model="draft"
                                list="sp-landing-anchors"
                                type="text"
                                spellcheck="false"
                                placeholder="#waitlist · /precios · https://…"
                                class="w-full rounded-sp-sm border border-medium bg-navy px-2 py-1.5 font-mono text-[11px] text-ink outline-none focus:border-accent-blue"
                                @keydown.enter.prevent="submit"
                                @keydown.esc.prevent="editing = null"
                            />
                            <p
                                v-if="error"
                                class="mt-1 text-[10px] text-red-300"
                            >
                                {{ error }}
                            </p>
                            <div class="mt-2 flex items-center gap-2">
                                <button
                                    type="button"
                                    :disabled="busy"
                                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-2.5 py-1 text-[11px] font-semibold text-white transition-opacity disabled:opacity-40"
                                    @click="submit"
                                >
                                    <Loader2
                                        v-if="busy"
                                        class="size-3 animate-spin"
                                    />
                                    {{
                                        t('apps.builder.links_apply', {
                                            count: group.count,
                                        })
                                    }}
                                </button>
                                <button
                                    type="button"
                                    class="text-[11px] text-ink-muted hover:text-ink"
                                    @click="editing = null"
                                >
                                    {{ t('common.cancel') }}
                                </button>
                            </div>
                        </div>

                        <!-- The individual links, so one of nine can be split off. -->
                        <ul
                            v-if="expanded.has(group.target)"
                            class="border-t border-soft"
                        >
                            <li v-for="link in group.links" :key="link.id">
                                <div
                                    class="flex items-center gap-2 px-3 py-1.5 text-[11px]"
                                >
                                    <span
                                        class="min-w-0 flex-1 truncate text-ink-muted"
                                    >
                                        {{
                                            link.label ||
                                            t('apps.builder.links_unlabelled')
                                        }}
                                    </span>
                                    <span
                                        v-if="link.element === 'button'"
                                        class="shrink-0 rounded-pill bg-amber-500/15 px-1.5 py-px font-mono text-[9px] text-amber-200"
                                        >button</span
                                    >
                                    <button
                                        type="button"
                                        class="shrink-0 text-[10px] text-accent-blue hover:underline"
                                        @click="
                                            startEdit(
                                                'l:' + link.id,
                                                [link.id],
                                                group.target,
                                            )
                                        "
                                    >
                                        {{ t('apps.builder.links_edit_one') }}
                                    </button>
                                </div>
                                <div
                                    v-if="editing?.key === 'l:' + link.id"
                                    class="px-3 pb-2.5"
                                >
                                    <input
                                        :ref="
                                            (el) => {
                                                if (el)
                                                    inputEl =
                                                        el as HTMLInputElement;
                                            }
                                        "
                                        v-model="draft"
                                        list="sp-landing-anchors"
                                        type="text"
                                        spellcheck="false"
                                        placeholder="#waitlist · /precios · https://…"
                                        class="w-full rounded-sp-sm border border-medium bg-navy px-2 py-1.5 font-mono text-[11px] text-ink outline-none focus:border-accent-blue"
                                        @keydown.enter.prevent="submit"
                                        @keydown.esc.prevent="editing = null"
                                    />
                                    <p
                                        v-if="error"
                                        class="mt-1 text-[10px] text-red-300"
                                    >
                                        {{ error }}
                                    </p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <button
                                            type="button"
                                            :disabled="busy"
                                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-2.5 py-1 text-[11px] font-semibold text-white transition-opacity disabled:opacity-40"
                                            @click="submit"
                                        >
                                            <Loader2
                                                v-if="busy"
                                                class="size-3 animate-spin"
                                            />
                                            {{
                                                t('apps.builder.links_apply', {
                                                    count: 1,
                                                })
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="text-[11px] text-ink-muted hover:text-ink"
                                            @click="editing = null"
                                        >
                                            {{ t('common.cancel') }}
                                        </button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </li>
                </ul>

                <datalist id="sp-landing-anchors">
                    <option v-for="a in anchors" :key="a" :value="a" />
                </datalist>
            </template>
        </div>
    </aside>
</template>
