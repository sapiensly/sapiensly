<script setup lang="ts">
import type { McpConnectionOption } from '@/types/tools';
import { CheckCircle2, ExternalLink, Plug, Server } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    connections: McpConnectionOption[];
    selectedId: string | null;
    error?: string;
}>();

const emit = defineEmits<{
    select: [connection: McpConnectionOption];
}>();

const connected = computed(() => props.connections.filter((c) => c.connected));
const notConnected = computed(() => props.connections.filter((c) => !c.connected));
</script>

<template>
    <div class="space-y-5">
        <p v-if="error" class="text-xs text-sp-danger">{{ error }}</p>

        <!-- Empty state — no MCP connections exist yet. -->
        <div
            v-if="connections.length === 0"
            class="rounded-lg border border-dashed p-6 text-center"
        >
            <Server class="mx-auto size-6 text-muted-foreground" />
            <p class="mt-2 text-sm text-ink">
                {{ t('tools.config.mcp.no_connections') }}
            </p>
            <a
                href="/system/integrations/create"
                class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
            >
                <ExternalLink class="size-3.5" />
                {{ t('tools.config.mcp.create_connection') }}
            </a>
        </div>

        <template v-else>
            <!-- Connected. -->
            <div v-if="connected.length > 0" class="space-y-2">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                    {{ t('tools.config.mcp.group_connected') }}
                </p>
                <button
                    v-for="c in connected"
                    :key="c.id"
                    type="button"
                    :class="[
                        'flex w-full items-center gap-3 rounded-lg border p-3 text-left transition-colors',
                        selectedId === c.id
                            ? 'border-accent-blue bg-accent-blue/5'
                            : 'border-medium hover:border-strong hover:bg-surface-hover',
                    ]"
                    @click="emit('select', c)"
                >
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xs bg-emerald-500/15 text-emerald-500">
                        <Plug class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-ink">{{ c.name }}</p>
                        <p class="truncate font-mono text-[11px] text-ink-subtle">{{ c.base_url }}</p>
                    </div>
                    <CheckCircle2 class="size-4 shrink-0 text-emerald-500" />
                </button>
            </div>

            <!-- Not connected. -->
            <div v-if="notConnected.length > 0" class="space-y-2">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                    {{ t('tools.config.mcp.group_not_connected') }}
                </p>
                <button
                    v-for="c in notConnected"
                    :key="c.id"
                    type="button"
                    :class="[
                        'flex w-full items-center gap-3 rounded-lg border p-3 text-left transition-colors',
                        selectedId === c.id
                            ? 'border-accent-blue bg-accent-blue/5'
                            : 'border-medium hover:border-strong hover:bg-surface-hover',
                    ]"
                    @click="emit('select', c)"
                >
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xs bg-amber-500/15 text-amber-500">
                        <Plug class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-ink">{{ c.name }}</p>
                        <p class="truncate font-mono text-[11px] text-ink-subtle">{{ c.base_url }}</p>
                    </div>
                    <span class="shrink-0 text-[11px] text-amber-600 dark:text-amber-400">
                        {{ t('tools.config.mcp.authorize_after_create') }}
                    </span>
                </button>
            </div>
        </template>
    </div>
</template>
