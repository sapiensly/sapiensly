<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Link } from '@inertiajs/vue3';
import { MessageCircle, Plus } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    channels: {
        data: Array<{
            id: string;
            name: string;
            status: string;
            whats_app_connection?: {
                display_phone_number?: string;
                phone_number_id?: string;
            } | null;
        }>;
    };
}>();

const statusTint: Record<string, string> = {
    connected: 'var(--sp-success)',
    active: 'var(--sp-success)',
    disconnected: 'var(--sp-text-secondary)',
    pending: 'var(--sp-accent-blue)',
    error: 'var(--sp-danger)',
};

function tintFor(status: string) {
    return statusTint[status] ?? 'var(--sp-text-secondary)';
}
</script>

<template>
    <AppLayoutV2 :title="t('app_v2.nav.whatsapp')">
        <div class="space-y-6">
            <PageHeader
                :title="t('whatsapp.connections.title')"
                :description="t('app_v2.common.empty_body')"
            >
                <template #actions>
                    <Link href="/system/whatsapp/create">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('whatsapp.connections.new') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <div
                v-if="channels.data.length === 0"
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <MessageCircle class="size-5" />
                </div>
                <p class="mt-4 text-sm text-ink-muted">
                    {{ t('whatsapp.connections.empty') }}
                </p>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="channel in channels.data"
                    :key="channel.id"
                    :href="`/system/whatsapp/${channel.whats_app_connection?.phone_number_id ?? channel.id}`"
                    class="flex flex-col rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue"
                            >
                                <MessageCircle class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-ink">
                                    {{ channel.name }}
                                </h3>
                                <p
                                    v-if="channel.whats_app_connection?.display_phone_number"
                                    class="mt-0.5 truncate text-xs text-ink-muted"
                                >
                                    {{ channel.whats_app_connection.display_phone_number }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="inline-flex shrink-0 items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                            :style="{
                                color: tintFor(channel.status),
                                borderColor: `color-mix(in oklab, ${tintFor(channel.status)} 45%, transparent)`,
                            }"
                        >
                            {{ channel.status }}
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayoutV2>
</template>
