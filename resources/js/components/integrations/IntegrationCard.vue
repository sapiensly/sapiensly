<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, Database, MoreVertical, Plug, Server, XCircle } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface Integration {
    id: string;
    name: string;
    slug: string;
    base_url: string;
    kind?: string;
    is_mcp?: boolean;
    auth_type: string;
    visibility: string;
    status: string;
    last_tested_at: string | null;
    last_test_status: string | null;
    request_count: number;
}

const props = defineProps<{ integration: Integration }>();

// A database connection has no HTTP auth — label it by kind, and pick the icon
// that matches what's being connected.
const kindIcon = computed(() => {
    if (props.integration.kind === 'database') return Database;
    if (props.integration.kind === 'mcp' || props.integration.is_mcp) return Server;
    return Plug;
});
const isDatabase = computed(() => props.integration.kind === 'database');

defineEmits<{
    duplicate: [id: string];
    delete: [id: string];
}>();

const { t } = useI18n();
</script>

<template>
    <div
        class="flex items-start justify-between gap-3 rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30"
    >
        <Link
            :href="`/system/integrations/${integration.id}`"
            class="flex min-w-0 flex-1 items-start gap-3"
        >
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue"
            >
                <component :is="kindIcon" class="size-5" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <p class="truncate text-sm font-semibold text-ink">
                        {{ integration.name }}
                    </p>
                    <CheckCircle2
                        v-if="integration.last_test_status === 'success'"
                        class="size-3.5 text-sp-success"
                    />
                    <XCircle
                        v-else-if="integration.last_test_status === 'failure'"
                        class="size-3.5 text-sp-danger"
                    />
                </div>
                <p class="truncate text-xs text-ink-muted">
                    {{ integration.base_url }}
                </p>
                <div class="mt-2.5 flex flex-wrap gap-1">
                    <span
                        class="inline-flex items-center rounded-pill border border-medium bg-surface px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                    >
                        {{ isDatabase ? t('system.integrations.starter.database_label') : integration.auth_type }}
                    </span>
                    <span
                        v-if="integration.visibility !== 'private'"
                        class="inline-flex items-center rounded-pill border border-soft bg-surface px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                    >
                        {{ integration.visibility }}
                    </span>
                    <span
                        v-if="!isDatabase"
                        class="inline-flex items-center rounded-pill border border-soft bg-surface px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                    >
                        {{ t('system.integrations.requests_count', { count: integration.request_count }) }}
                    </span>
                </div>
            </div>
        </Link>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="flex size-7 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                >
                    <MoreVertical class="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="sp-admin-menu">
                <DropdownMenuItem @select="$emit('duplicate', integration.id)">
                    {{ t('system.integrations.duplicate') }}
                </DropdownMenuItem>
                <DropdownMenuItem @select="$emit('delete', integration.id)">
                    {{ t('common.delete') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
