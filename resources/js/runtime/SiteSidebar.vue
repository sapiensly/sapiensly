<script setup lang="ts">
import RuntimeIcon from '@/runtime/RuntimeIcon.vue';
import RuntimeUserMenu from '@/runtime/RuntimeUserMenu.vue';
import { ChevronDown, PanelLeftClose, PanelLeftOpen } from '@lucide/vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';

interface Brand {
    name?: string;
    logo?: string;
    icon?: string;
    header_bg?: string;
}
interface NavItem {
    id: string;
    label: string;
    icon?: string;
    page_id?: string;
    children?: NavItem[];
}
interface PageLink {
    id: string;
    slug: string;
    name: string;
    icon?: string;
}
interface Node {
    key: string;
    label: string;
    icon?: string;
    slug?: string;
    href?: string;
    children?: Node[];
}

const props = defineProps<{
    brand?: Brand;
    navItems?: NavItem[];
    pages?: PageLink[];
    currentSlug?: string;
    hrefFor?: (slug: string) => string;
    /** Embedded in the builder preview pane (fills its container instead of the viewport). */
    embedded?: boolean;
}>();

const slugById = computed<Record<string, string>>(() =>
    Object.fromEntries((props.pages ?? []).map((p) => [p.id, p.slug])),
);

function resolve(items: NavItem[]): Node[] {
    return items.map((it) => {
        const slug = it.page_id ? slugById.value[it.page_id] : undefined;
        return {
            key: it.id,
            label: it.label,
            icon: it.icon,
            slug,
            href: slug && props.hrefFor ? props.hrefFor(slug) : undefined,
            children:
                it.children && it.children.length
                    ? resolve(it.children)
                    : undefined,
        };
    });
}

// Authored navigation (supports nested children) when present, else the flat pages.
const tree = computed<Node[]>(() =>
    props.navItems && props.navItems.length
        ? resolve(props.navItems)
        : (props.pages ?? []).map((p) => ({
              key: p.id,
              label: p.name,
              icon: p.icon,
              slug: p.slug,
              href: props.hrefFor ? props.hrefFor(p.slug) : undefined,
          })),
);

function isActive(n: Node): boolean {
    return !!n.slug && n.slug === props.currentSlug;
}

// Collapsible groups — open by default.
const groupCollapsed = reactive<Record<string, boolean>>({});
function toggleGroup(key: string) {
    groupCollapsed[key] = !groupCollapsed[key];
}
function isOpen(n: Node): boolean {
    return groupCollapsed[n.key] === undefined ? true : !groupCollapsed[n.key];
}

// --- Collapse the whole rail to an icons-only strip (persisted per browser). ---
const STORAGE_KEY = 'sp-sidebar-collapsed';
const collapsed = ref(false);
onMounted(() => {
    try {
        collapsed.value = localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        /* ignore (SSR / blocked storage) */
    }
});
watch(collapsed, (v) => {
    try {
        localStorage.setItem(STORAGE_KEY, v ? '1' : '0');
    } catch {
        /* ignore */
    }
});

const brandIcon = computed(() => props.brand?.icon ?? null);
function isUrl(s: string): boolean {
    return /^https?:\/\//.test(s) || s.startsWith('/');
}
function initial(label: string): string {
    return (label.trim()[0] ?? '•').toUpperCase();
}

const headerStyle = computed(() =>
    props.brand?.header_bg ? { background: props.brand.header_bg } : {},
);
const activeStyle = {
    background:
        'color-mix(in srgb, var(--sp-accent, #3b82f6) 14%, transparent)',
    color: 'var(--sp-accent, #3b82f6)',
};
</script>

<template>
    <aside
        :class="[
            'flex shrink-0 flex-col border-r transition-[width] duration-150',
            collapsed ? 'w-16' : 'w-60',
            embedded ? 'self-stretch' : 'sticky top-0 h-screen',
        ]"
        :style="{
            borderColor: 'color-mix(in srgb, currentColor 12%, transparent)',
            backgroundColor: 'color-mix(in srgb, currentColor 4%, transparent)',
        }"
    >
        <!-- Brand header. Collapsed: just the brand icon (nothing if there is none). -->
        <a
            v-if="!collapsed || brandIcon"
            class="flex border-b px-3 py-3.5 font-semibold"
            :class="
                collapsed
                    ? 'items-center justify-center'
                    : 'flex-col items-start gap-2'
            "
            :style="{
                borderColor:
                    'color-mix(in srgb, currentColor 12%, transparent)',
                ...headerStyle,
            }"
            :href="hrefFor && pages?.[0] ? hrefFor(pages[0].slug) : '#'"
        >
            <template v-if="collapsed">
                <img
                    v-if="brandIcon && isUrl(brandIcon)"
                    :src="brandIcon"
                    alt=""
                    class="size-7 rounded object-contain"
                />
                <RuntimeIcon
                    v-else-if="brandIcon"
                    :name="brandIcon"
                    :size="22"
                />
            </template>
            <template v-else>
                <img
                    v-if="brand?.logo"
                    :src="brand.logo"
                    alt=""
                    class="h-7 w-auto max-w-full object-contain"
                />
                <span
                    v-if="brand?.name"
                    class="text-base leading-tight tracking-tight"
                    >{{ brand.name }}</span
                >
            </template>
        </a>

        <!-- Nav. -->
        <nav class="min-h-0 flex-1 space-y-0.5 overflow-y-auto p-2">
            <!-- Collapsed: top-level icons only (groups expand the rail on click). -->
            <template v-if="collapsed">
                <template v-for="node in tree" :key="node.key">
                    <a
                        v-if="!node.children"
                        :href="node.href ?? '#'"
                        :title="node.label"
                        class="grid h-10 place-items-center rounded-md transition-colors"
                        :style="isActive(node) ? activeStyle : { opacity: 0.8 }"
                    >
                        <RuntimeIcon
                            v-if="node.icon"
                            :name="node.icon"
                            :size="18"
                        />
                        <span v-else class="text-sm font-semibold">{{
                            initial(node.label)
                        }}</span>
                    </a>
                    <button
                        v-else
                        type="button"
                        :title="node.label"
                        class="grid h-10 w-full place-items-center rounded-md transition-colors hover:bg-[color-mix(in_srgb,currentColor_8%,transparent)]"
                        @click="collapsed = false"
                    >
                        <RuntimeIcon
                            v-if="node.icon"
                            :name="node.icon"
                            :size="18"
                        />
                        <span v-else class="text-sm font-semibold">{{
                            initial(node.label)
                        }}</span>
                    </button>
                </template>
            </template>

            <!-- Expanded: full tree with collapsible groups. -->
            <template v-else>
                <template v-for="node in tree" :key="node.key">
                    <div v-if="node.children">
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors hover:bg-[color-mix(in_srgb,currentColor_8%,transparent)]"
                            @click="toggleGroup(node.key)"
                        >
                            <RuntimeIcon
                                v-if="node.icon"
                                :name="node.icon"
                                :size="16"
                            />
                            <span
                                class="flex-1 truncate text-left font-medium"
                                >{{ node.label }}</span
                            >
                            <ChevronDown
                                class="size-3.5 shrink-0 transition-transform"
                                :class="isOpen(node) ? '' : '-rotate-90'"
                            />
                        </button>
                        <div
                            v-show="isOpen(node)"
                            class="mt-0.5 ml-3 space-y-0.5 border-l border-[color-mix(in_srgb,currentColor_12%,transparent)] pl-2"
                        >
                            <a
                                v-for="child in node.children"
                                :key="child.key"
                                :href="child.href ?? '#'"
                                class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors"
                                :style="
                                    isActive(child)
                                        ? activeStyle
                                        : { opacity: 0.8 }
                                "
                            >
                                <RuntimeIcon
                                    v-if="child.icon"
                                    :name="child.icon"
                                    :size="15"
                                />
                                <span class="truncate">{{ child.label }}</span>
                            </a>
                        </div>
                    </div>
                    <a
                        v-else
                        :href="node.href ?? '#'"
                        class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors"
                        :style="isActive(node) ? activeStyle : { opacity: 0.8 }"
                    >
                        <RuntimeIcon
                            v-if="node.icon"
                            :name="node.icon"
                            :size="16"
                        />
                        <span class="truncate font-medium">{{
                            node.label
                        }}</span>
                    </a>
                </template>
            </template>
        </nav>

        <!-- Footer: collapse toggle + user widget. -->
        <div
            class="flex items-center gap-2 border-t p-2"
            :class="collapsed ? 'flex-col' : 'justify-between'"
            :style="{
                borderColor:
                    'color-mix(in srgb, currentColor 12%, transparent)',
            }"
        >
            <RuntimeUserMenu :compact="collapsed" />
            <button
                type="button"
                class="grid size-8 shrink-0 place-items-center rounded-md text-ink-muted transition-colors hover:bg-[color-mix(in_srgb,currentColor_8%,transparent)]"
                :title="collapsed ? 'Expandir' : 'Colapsar'"
                @click="collapsed = !collapsed"
            >
                <PanelLeftOpen v-if="collapsed" class="size-4" />
                <PanelLeftClose v-else class="size-4" />
            </button>
        </div>
    </aside>
</template>
