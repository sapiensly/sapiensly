<script setup lang="ts">
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    <AppLayoutV2 :title="t('app_v2.nav.whatsapp')">
        <div>
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
    </AppLayoutV2>
</template>
