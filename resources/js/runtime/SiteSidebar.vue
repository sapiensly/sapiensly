<script setup lang="ts">
import RuntimeIcon from '@/runtime/RuntimeIcon.vue';
import RuntimeUserMenu from '@/runtime/RuntimeUserMenu.vue';
import { ChevronDown } from '@lucide/vue';
import { computed, reactive } from 'vue';

interface Brand {
    name?: string;
    logo?: string;
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

// Use the authored navigation (supports nested children) when present, else the
// flat page list.
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
const collapsed = reactive<Record<string, boolean>>({});
function toggle(key: string) {
    collapsed[key] = !collapsed[key];
}
function isOpen(n: Node): boolean {
    return collapsed[n.key] === undefined ? true : !collapsed[n.key];
}

const headerStyle = computed(() =>
    props.brand?.header_bg ? { background: props.brand.header_bg } : {},
);
</script>

<template>
    <aside
        class="sticky top-0 flex h-screen w-60 shrink-0 flex-col border-r"
        :style="{
            borderColor: 'color-mix(in srgb, currentColor 12%, transparent)',
            backgroundColor: 'color-mix(in srgb, currentColor 4%, transparent)',
        }"
    >
        <!-- Brand header. -->
        <a
            class="flex items-center gap-2 border-b px-4 py-3.5 font-semibold"
            :style="{
                borderColor:
                    'color-mix(in srgb, currentColor 12%, transparent)',
                ...headerStyle,
            }"
            :href="hrefFor && pages?.[0] ? hrefFor(pages[0].slug) : '#'"
        >
            <img
                v-if="brand?.logo"
                :src="brand.logo"
                alt=""
                class="h-7 w-auto"
            />
            <span
                v-if="brand?.name"
                class="truncate text-base tracking-tight"
                >{{ brand.name }}</span
            >
        </a>

        <!-- Nav tree (one level of nested groups). -->
        <nav class="min-h-0 flex-1 space-y-0.5 overflow-y-auto p-2">
            <template v-for="node in tree" :key="node.key">
                <!-- Group with children. -->
                <div v-if="node.children">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors hover:bg-[color-mix(in_srgb,currentColor_8%,transparent)]"
                        @click="toggle(node.key)"
                    >
                        <RuntimeIcon
                            v-if="node.icon"
                            :name="node.icon"
                            :size="16"
                        />
                        <span class="flex-1 truncate text-left font-medium">{{
                            node.label
                        }}</span>
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
                                    ? {
                                          background:
                                              'color-mix(in srgb, var(--sp-accent, #3b82f6) 14%, transparent)',
                                          color: 'var(--sp-accent, #3b82f6)',
                                      }
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

                <!-- Leaf link. -->
                <a
                    v-else
                    :href="node.href ?? '#'"
                    class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors"
                    :style="
                        isActive(node)
                            ? {
                                  background:
                                      'color-mix(in srgb, var(--sp-accent, #3b82f6) 14%, transparent)',
                                  color: 'var(--sp-accent, #3b82f6)',
                              }
                            : { opacity: 0.8 }
                    "
                >
                    <RuntimeIcon
                        v-if="node.icon"
                        :name="node.icon"
                        :size="16"
                    />
                    <span class="truncate font-medium">{{ node.label }}</span>
                </a>
            </template>
        </nav>

        <!-- User widget (identity + exit to Sapiensly). -->
        <div
            class="border-t p-2"
            :style="{
                borderColor:
                    'color-mix(in srgb, currentColor 12%, transparent)',
            }"
        >
            <RuntimeUserMenu />
        </div>
    </aside>
</template>
