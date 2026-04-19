<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    conversations: {
        data: Array<{
            id: string;
            status: string;
            last_inbound_at: string | null;
            contact?: { profile_name?: string | null; phone_e164?: string | null } | null;
        }>;
    };
}>();
</script>

<template>
    <AppLayout>
        <div class="p-6">
            <h1 class="mb-6 text-2xl font-semibold">{{ $t('whatsapp.inbox.title') }}</h1>
            <div v-if="conversations.data.length === 0" class="rounded border p-8 text-center text-muted-foreground">
                {{ $t('whatsapp.inbox.empty') }}
            </div>
            <ul v-else class="divide-y rounded border bg-card">
                <li v-for="c in conversations.data" :key="c.id">
                    <Link :href="`/system/whatsapp/inbox/${c.id}`" class="flex items-center justify-between p-3 hover:bg-accent">
                        <div>
                            <div class="font-medium">{{ c.contact?.profile_name ?? c.contact?.phone_e164 ?? '—' }}</div>
                            <div class="text-xs text-muted-foreground">{{ c.last_inbound_at ?? '' }}</div>
                        </div>
                        <span class="rounded bg-secondary px-2 py-0.5 text-xs">{{ c.status }}</span>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
