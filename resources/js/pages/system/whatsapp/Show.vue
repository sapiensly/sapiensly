<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{
    connection: Record<string, unknown>;
    webhook_url: string;
    verify_token: string;
}>();
</script>

<template>
    <AppLayout>
        <div class="max-w-3xl p-6">
            <h1 class="mb-4 text-2xl font-semibold">{{ (connection as any).display_phone_number }}</h1>
            <div class="mb-4 rounded border bg-card p-4">
                <div class="mb-2 text-sm font-medium">{{ $t('whatsapp.webhook.url') }}</div>
                <code class="block truncate rounded bg-muted p-2 text-xs">{{ webhook_url }}</code>
                <div class="mt-3 text-sm font-medium">{{ $t('whatsapp.webhook.verify_token') }}</div>
                <code class="block truncate rounded bg-muted p-2 text-xs">{{ verify_token }}</code>
            </div>
            <div class="rounded border bg-card p-4">
                <h2 class="mb-2 font-medium">{{ $t('whatsapp.connections.credentials') }}</h2>
                <pre class="overflow-x-auto text-xs">{{ (connection as any).masked_auth }}</pre>
            </div>
        </div>
    </AppLayout>
</template>
