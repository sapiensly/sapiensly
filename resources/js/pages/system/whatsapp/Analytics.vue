<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{
    connection: { id: string; display_phone_number: string };
    stats: {
        conversations_total: number;
        conversations_open: number;
        conversations_escalated: number;
        conversations_resolved: number;
        messages_in: number;
        messages_out: number;
        delivery_rate: number;
    };
}>();
</script>

<template>
    <AppLayout>
        <div class="p-6">
            <h1 class="mb-6 text-2xl font-semibold">{{ $t('whatsapp.analytics.title') }}</h1>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div class="rounded border bg-card p-4">
                    <div class="text-xs text-muted-foreground">{{ $t('whatsapp.analytics.conversations') }}</div>
                    <div class="text-2xl font-semibold">{{ stats.conversations_total }}</div>
                </div>
                <div class="rounded border bg-card p-4">
                    <div class="text-xs text-muted-foreground">{{ $t('whatsapp.analytics.open') }}</div>
                    <div class="text-2xl font-semibold">{{ stats.conversations_open }}</div>
                </div>
                <div class="rounded border bg-card p-4">
                    <div class="text-xs text-muted-foreground">{{ $t('whatsapp.analytics.messages_in') }}</div>
                    <div class="text-2xl font-semibold">{{ stats.messages_in }}</div>
                </div>
                <div class="rounded border bg-card p-4">
                    <div class="text-xs text-muted-foreground">{{ $t('whatsapp.analytics.delivery_rate') }}</div>
                    <div class="text-2xl font-semibold">{{ stats.delivery_rate }}%</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
