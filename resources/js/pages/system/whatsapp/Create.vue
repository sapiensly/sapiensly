<script setup lang="ts">
import SettingsCard from '@/components/admin/SettingsCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bot, Key, MessageCircle } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const form = useForm({
    name: '',
    display_phone_number: '',
    phone_number_id: '',
    business_account_id: '',
    messaging_tier: 'unverified',
    auth: {
        access_token: '',
        app_id: '',
        app_secret: '',
        graph_api_version: 'v20.0',
    },
});

function submit() {
    form.post('/system/whatsapp');
}
</script>

<template>
    <Head :title="t('whatsapp.connections.new')" />

    <AppLayoutV2 :title="t('app_v2.nav.whatsapp')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="t('whatsapp.connections.new')"
                description="Connect a WhatsApp Business Cloud number so agents can reply to customer messages."
            />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- Channel identity. -->
                <SettingsCard
                    :icon="MessageCircle"
                    title="Channel"
                    description="Phone number and WhatsApp Business Cloud identifiers"
                    tint="var(--sp-success)"
                >
                    <div class="space-y-1.5">
                        <Label for="name">
                            {{ t('whatsapp.connections.name') }}
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            placeholder="Customer Support"
                            class="h-9"
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="display_phone_number">
                                {{ t('whatsapp.connections.phone') }}
                            </Label>
                            <Input
                                id="display_phone_number"
                                v-model="form.display_phone_number"
                                placeholder="+15551234567"
                                class="h-9"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="phone_number_id">Phone Number ID</Label>
                            <Input
                                id="phone_number_id"
                                v-model="form.phone_number_id"
                                placeholder="100000000000000"
                                class="h-9"
                            />
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="business_account_id">
                            Business Account ID
                        </Label>
                        <Input
                            id="business_account_id"
                            v-model="form.business_account_id"
                            placeholder="200000000000000"
                            class="h-9"
                        />
                    </div>
                </SettingsCard>

                <!-- Meta Graph credentials. -->
                <SettingsCard
                    :icon="Key"
                    title="Credentials"
                    description="Meta Graph API tokens used to send and receive messages"
                    tint="var(--sp-spectrum-magenta)"
                >
                    <div class="space-y-1.5">
                        <Label for="access_token">Access Token</Label>
                        <Input
                            id="access_token"
                            v-model="form.auth.access_token"
                            type="password"
                            placeholder="EAA..."
                            class="h-9"
                        />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="app_id">App ID</Label>
                            <Input
                                id="app_id"
                                v-model="form.auth.app_id"
                                class="h-9"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="app_secret">App Secret</Label>
                            <Input
                                id="app_secret"
                                v-model="form.auth.app_secret"
                                type="password"
                                class="h-9"
                            />
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="graph_api_version">Graph API Version</Label>
                        <Input
                            id="graph_api_version"
                            v-model="form.auth.graph_api_version"
                            class="h-9"
                        />
                    </div>
                </SettingsCard>

                <!-- Agents live in the Bot Flow. -->
                <SettingsCard
                    :icon="Bot"
                    :title="t('chatbots.create.agents_title')"
                    :description="t('chatbots.create.agents_description')"
                    tint="#a855f7"
                >
                    <p class="text-[11px] text-ink-subtle">
                        {{ t('chatbots.create.agents_note') }}
                    </p>
                </SettingsCard>

                <!-- Footer actions. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link href="/system/whatsapp">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                        >
                            {{ t('common.cancel') }}
                        </button>
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('whatsapp.connections.create') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
