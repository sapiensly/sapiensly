<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

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
</script>

<template>
    <AppLayout>
        <div class="p-6">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-semibold">{{ $t('whatsapp.connections.title') }}</h1>
                <Link href="/system/whatsapp/create" class="rounded bg-primary px-4 py-2 text-primary-foreground">
                    {{ $t('whatsapp.connections.new') }}
                </Link>
            </div>
            <div v-if="channels.data.length === 0" class="rounded border p-8 text-center text-muted-foreground">
                {{ $t('whatsapp.connections.empty') }}
            </div>
            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="channel in channels.data"
                    :key="channel.id"
                    :href="`/system/whatsapp/${channel.whats_app_connection?.phone_number_id ?? channel.id}`"
                    class="block rounded border bg-card p-4 hover:bg-accent"
                >
                    <div class="font-medium">{{ channel.name }}</div>
                    <div class="text-sm text-muted-foreground">{{ channel.whats_app_connection?.display_phone_number }}</div>
                    <div class="mt-2 inline-block rounded bg-secondary px-2 py-0.5 text-xs">{{ channel.status }}</div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
