<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import {
    Bot,
    ChevronDown,
    ChevronRight,
    Clock,
    Database,
    LayoutDashboard,
    Link2,
    Workflow as WorkflowIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

/**
 * Unified, at-a-glance explorer of every layer of a built app — objects &
 * fields, pages & blocks, workflows, integrations, the agent, and version
 * history — so the maker can consult the whole structure without leaving the
 * builder. Read-only: it reflects the active manifest, it does not edit it.
 */
interface VersionEntry {
    id: string;
    version: number;
    summary: string | null;
    created_at: string | null;
    current: boolean;
}

const props = defineProps<{
    manifest: Record<string, unknown> | null;
    schema: { record_counts?: Record<string, number> } | null;
    versions?: VersionEntry[];
}>();

// Generic helpers — the manifest is loosely typed (it comes from JSON), so we
// read each layer defensively and never assume a shape that may be absent.
function arr(value: unknown): Array<Record<string, unknown>> {
    return Array.isArray(value) ? (value as Array<Record<string, unknown>>) : [];
}
function str(value: unknown, fallback = ''): string {
    return typeof value === 'string' ? value : fallback;
}

const objects = computed(() => arr(props.manifest?.objects));
const pages = computed(() => arr(props.manifest?.pages));
const workflows = computed(() => arr(props.manifest?.workflows));
const integrations = computed(() => arr(props.manifest?.integrations));
const agent = computed(() => (props.manifest?.agent ?? null) as Record<string, unknown> | null);
const versionList = computed(() => props.versions ?? []);

function recordCount(objectId: string): number | null {
    const c = props.schema?.record_counts?.[objectId];
    return typeof c === 'number' ? c : null;
}

function sourceType(object: Record<string, unknown>): string {
    const source = object.source as Record<string, unknown> | undefined;
    return str(source?.type, 'internal');
}

// Two-level expand state, keyed like "obj:obj_123" / "page:pag_9".
const expanded = ref<Set<string>>(new Set());
function toggle(key: string): void {
    const next = new Set(expanded.value);
    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }
    expanded.value = next;
}
function isOpen(key: string): boolean {
    return expanded.value.has(key);
}

// Collapsible top-level sections; objects + pages start open (the common
// consultation targets), the rest collapsed to keep the panel scannable.
const openSections = ref<Set<string>>(new Set(['objects', 'pages']));
function toggleSection(key: string): void {
    const next = new Set(openSections.value);
    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }
    openSections.value = next;
}

function fmtDate(iso: string | null): string {
    if (! iso) {
        return '';
    }
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function agentCapabilitySummary(): string {
    const caps = (agent.value?.capabilities ?? {}) as Record<string, unknown>;
    const fmt = (g: unknown): string => (g === 'all' ? 'all' : Array.isArray(g) ? String(g.length) : '0');
    return t('apps.builder.layers.caps_summary', { read: fmt(caps.read), write: fmt(caps.write) });
}
</script>

<template>
    <div class="flex h-full flex-col text-sm">
        <!-- OBJECTS -->
        <section class="border-b border-soft">
            <button type="button" class="lx-section" @click="toggleSection('objects')">
                <component :is="openSections.has('objects') ? ChevronDown : ChevronRight" class="size-3.5 text-ink-subtle" />
                <Database class="size-4 text-accent-blue" />
                <span class="font-medium text-ink">{{ t('apps.builder.layers.objects') }}</span>
                <Badge variant="secondary" class="ml-auto">{{ objects.length }}</Badge>
            </button>
            <div v-if="openSections.has('objects')" class="pb-1">
                <p v-if="objects.length === 0" class="lx-empty">{{ t('apps.builder.layers.no_objects') }}</p>
                <div v-for="o in objects" :key="String(o.id)">
                    <button type="button" class="lx-item" @click="toggle('obj:' + o.id)">
                        <component :is="isOpen('obj:' + o.id) ? ChevronDown : ChevronRight" class="size-3 text-ink-subtle" />
                        <span class="truncate text-ink">{{ str(o.name, str(o.slug)) }}</span>
                        <Badge v-if="sourceType(o) === 'connected'" variant="outline" class="ml-1 text-[10px]">{{ t('apps.builder.layers.badge_connected') }}</Badge>
                        <span v-if="recordCount(String(o.id)) !== null" class="ml-auto text-xs text-ink-subtle">
                            {{ recordCount(String(o.id)) }}
                        </span>
                    </button>
                    <ul v-if="isOpen('obj:' + o.id)" class="lx-children">
                        <li v-for="f in arr(o.fields)" :key="String(f.id)" class="lx-leaf">
                            <span class="truncate text-ink-muted">{{ str(f.name, str(f.slug)) }}</span>
                            <span class="ml-auto text-[10px] text-ink-subtle">{{ str(f.type) }}</span>
                        </li>
                        <li v-if="arr(o.fields).length === 0" class="lx-leaf text-ink-subtle">{{ t('apps.builder.layers.no_fields') }}</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- PAGES -->
        <section class="border-b border-soft">
            <button type="button" class="lx-section" @click="toggleSection('pages')">
                <component :is="openSections.has('pages') ? ChevronDown : ChevronRight" class="size-3.5 text-ink-subtle" />
                <LayoutDashboard class="size-4 text-accent-blue" />
                <span class="font-medium text-ink">{{ t('apps.builder.layers.pages') }}</span>
                <Badge variant="secondary" class="ml-auto">{{ pages.length }}</Badge>
            </button>
            <div v-if="openSections.has('pages')" class="pb-1">
                <p v-if="pages.length === 0" class="lx-empty">{{ t('apps.builder.layers.no_pages') }}</p>
                <div v-for="p in pages" :key="String(p.id)">
                    <button type="button" class="lx-item" @click="toggle('page:' + p.id)">
                        <component :is="isOpen('page:' + p.id) ? ChevronDown : ChevronRight" class="size-3 text-ink-subtle" />
                        <span class="truncate text-ink">{{ str(p.name, str(p.slug)) }}</span>
                        <span class="ml-auto text-xs text-ink-subtle">{{ arr(p.blocks).length }}</span>
                    </button>
                    <ul v-if="isOpen('page:' + p.id)" class="lx-children">
                        <li v-for="(b, i) in arr(p.blocks)" :key="String(b.id ?? i)" class="lx-leaf">
                            <span class="truncate text-ink-muted">{{ str(b.type, 'block') }}</span>
                        </li>
                        <li v-if="arr(p.blocks).length === 0" class="lx-leaf text-ink-subtle">{{ t('apps.builder.layers.no_blocks') }}</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- WORKFLOWS -->
        <section class="border-b border-soft">
            <button type="button" class="lx-section" @click="toggleSection('workflows')">
                <component :is="openSections.has('workflows') ? ChevronDown : ChevronRight" class="size-3.5 text-ink-subtle" />
                <WorkflowIcon class="size-4 text-accent-blue" />
                <span class="font-medium text-ink">{{ t('apps.builder.layers.workflows') }}</span>
                <Badge variant="secondary" class="ml-auto">{{ workflows.length }}</Badge>
            </button>
            <div v-if="openSections.has('workflows')" class="pb-1">
                <p v-if="workflows.length === 0" class="lx-empty">{{ t('apps.builder.layers.no_workflows') }}</p>
                <div v-for="w in workflows" :key="String(w.id)">
                    <button type="button" class="lx-item" @click="toggle('wf:' + w.id)">
                        <component :is="isOpen('wf:' + w.id) ? ChevronDown : ChevronRight" class="size-3 text-ink-subtle" />
                        <span class="truncate text-ink">{{ str(w.name, str(w.slug)) }}</span>
                        <span class="ml-auto text-[10px] text-ink-subtle">
                            {{ str((w.trigger as Record<string, unknown> | undefined)?.type as string, 'manual') }}
                        </span>
                    </button>
                    <ul v-if="isOpen('wf:' + w.id)" class="lx-children">
                        <li v-for="(s, i) in arr(w.steps)" :key="String(s.id ?? i)" class="lx-leaf">
                            <span class="truncate text-ink-muted">{{ str(s.type, 'step') }}</span>
                        </li>
                        <li v-if="arr(w.steps).length === 0" class="lx-leaf text-ink-subtle">{{ t('apps.builder.layers.no_steps') }}</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- INTEGRATIONS -->
        <section v-if="integrations.length > 0" class="border-b border-soft">
            <button type="button" class="lx-section" @click="toggleSection('integrations')">
                <component :is="openSections.has('integrations') ? ChevronDown : ChevronRight" class="size-3.5 text-ink-subtle" />
                <Link2 class="size-4 text-accent-blue" />
                <span class="font-medium text-ink">{{ t('apps.builder.layers.integrations') }}</span>
                <Badge variant="secondary" class="ml-auto">{{ integrations.length }}</Badge>
            </button>
            <div v-if="openSections.has('integrations')" class="pb-1">
                <div v-for="ig in integrations" :key="String(ig.id)" class="lx-item">
                    <Link2 class="size-3 text-ink-subtle" />
                    <span class="truncate text-ink">{{ str(ig.name, str(ig.slug)) }}</span>
                    <span class="ml-auto text-[10px] text-ink-subtle">{{ str(ig.status) }}</span>
                </div>
            </div>
        </section>

        <!-- AGENT -->
        <section v-if="agent" class="border-b border-soft">
            <button type="button" class="lx-section" @click="toggleSection('agent')">
                <component :is="openSections.has('agent') ? ChevronDown : ChevronRight" class="size-3.5 text-ink-subtle" />
                <Bot class="size-4 text-accent-blue" />
                <span class="font-medium text-ink">{{ t('apps.builder.layers.agent') }}</span>
                <Badge :variant="agent.enabled ? 'default' : 'secondary'" class="ml-auto">
                    {{ agent.enabled ? t('apps.builder.layers.agent_on') : t('apps.builder.layers.agent_off') }}
                </Badge>
            </button>
            <div v-if="openSections.has('agent')" class="px-3 pb-2 text-xs text-ink-muted">
                <div>{{ str(agent.name, t('apps.builder.layers.agent_default_name')) }}</div>
                <div class="mt-0.5 text-ink-subtle">{{ agentCapabilitySummary() }} · {{ t('apps.builder.layers.autonomy_label') }} {{ str(agent.autonomy, 'propose') }}</div>
            </div>
        </section>

        <!-- VERSIONS -->
        <section>
            <button type="button" class="lx-section" @click="toggleSection('versions')">
                <component :is="openSections.has('versions') ? ChevronDown : ChevronRight" class="size-3.5 text-ink-subtle" />
                <Clock class="size-4 text-accent-blue" />
                <span class="font-medium text-ink">{{ t('apps.builder.layers.history') }}</span>
                <Badge variant="secondary" class="ml-auto">{{ versionList.length }}</Badge>
            </button>
            <div v-if="openSections.has('versions')" class="pb-2">
                <p v-if="versionList.length === 0" class="lx-empty">{{ t('apps.builder.layers.no_versions') }}</p>
                <div v-for="v in versionList" :key="v.id" class="px-3 py-1.5">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium text-ink">v{{ v.version }}</span>
                        <Badge v-if="v.current" variant="default" class="text-[10px]">{{ t('apps.builder.layers.badge_current') }}</Badge>
                        <span class="ml-auto text-[10px] text-ink-subtle">{{ fmtDate(v.created_at) }}</span>
                    </div>
                    <p v-if="v.summary" class="mt-0.5 truncate text-xs text-ink-muted">{{ v.summary }}</p>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.lx-section {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.6rem 0.75rem;
    text-align: left;
}
.lx-section:hover {
    background: var(--sp-surface-hover, rgba(255, 255, 255, 0.04));
}
.lx-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    width: 100%;
    padding: 0.3rem 0.75rem 0.3rem 1.5rem;
    text-align: left;
}
.lx-item:hover {
    background: var(--sp-surface-hover, rgba(255, 255, 255, 0.04));
}
.lx-children {
    margin: 0;
    padding: 0 0.75rem 0.25rem 2.6rem;
    list-style: none;
}
.lx-leaf {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.15rem 0;
    font-size: 0.75rem;
}
.lx-empty {
    padding: 0.25rem 0.75rem 0.5rem 1.75rem;
    font-size: 0.75rem;
    color: var(--sp-text-tertiary);
}
</style>
