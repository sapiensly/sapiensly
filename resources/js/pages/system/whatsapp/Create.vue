<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    agents: Array<{ id: string; name: string; type: string; status: string }>;
    agentTeams: Array<{ id: string; name: string; status: string }>;
}>();

const form = useForm({
    name: '',
    display_phone_number: '',
    phone_number_id: '',
    business_account_id: '',
    messaging_tier: 'unverified',
    agent_id: null as string | null,
    agent_team_id: null as string | null,
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
    <AppLayout>
        <div class="max-w-2xl p-6">
            <h1 class="mb-6 text-2xl font-semibold">{{ $t('whatsapp.connections.new') }}</h1>
            <form class="space-y-4" @submit.prevent="submit">
                <label class="block">
                    <span class="text-sm font-medium">{{ $t('whatsapp.connections.name') }}</span>
                    <input v-model="form.name" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">{{ $t('whatsapp.connections.phone') }}</span>
                    <input v-model="form.display_phone_number" placeholder="+15551234567" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Phone Number ID</span>
                    <input v-model="form.phone_number_id" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Business Account ID</span>
                    <input v-model="form.business_account_id" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Access Token</span>
                    <input v-model="form.auth.access_token" type="password" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">App ID</span>
                    <input v-model="form.auth.app_id" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">App Secret</span>
                    <input v-model="form.auth.app_secret" type="password" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">{{ $t('whatsapp.connections.target') }}</span>
                    <select v-model="form.agent_id" class="mt-1 w-full rounded border px-3 py-2">
                        <option :value="null">{{ $t('whatsapp.connections.target_none') }}</option>
                        <option v-for="a in props.agents" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </label>
                <button type="submit" :disabled="form.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">
                    {{ $t('whatsapp.connections.create') }}
                </button>
            </form>
        </div>
    </AppLayout>
</template>
