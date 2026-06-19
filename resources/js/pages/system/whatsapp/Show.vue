<script setup lang="ts">
import * as BotFlowController from '@/actions/App/Http/Controllers/BotFlowController';
import { Button } from '@/components/ui/button';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Link } from '@inertiajs/vue3';
import { Workflow } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    connection: Record<string, unknown>;
    webhook_url: string;
    verify_token: string;
}>();
</script>

<template>
    <AppLayoutV2 :title="t('app_v2.nav.whatsapp')">
        <div class="mx-auto max-w-3xl">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h1 class="text-2xl font-semibold">{{ (connection as any).display_phone_number }}</h1>
                <Button as-child variant="outline">
                    <Link
                        :href="
                            BotFlowController.editForWhatsApp({
                                whatsapp_connection: (props.connection as any).id,
                            }).url
                        "
                    >
                        <Workflow class="mr-2 h-4 w-4" />
                        {{ t('chatbots.show.edit_flow') }}
                    </Link>
                </Button>
            </div>
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
    </AppLayoutV2>
</template>
