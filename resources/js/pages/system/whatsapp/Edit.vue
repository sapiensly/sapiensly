<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    connection: Record<string, unknown>;
    agents: Array<{ id: string; name: string }>;
    agentTeams: Array<{ id: string; name: string }>;
}>();

const form = useForm({
    name: (props.connection as any).channel?.name ?? '',
    status: (props.connection as any).channel?.status ?? 'draft',
    auth: {
        access_token: '',
        app_id: '',
        app_secret: '',
        graph_api_version: (props.connection as any).masked_auth?.graph_api_version ?? 'v20.0',
    },
});

function submit() {
    form.put(`/system/whatsapp/${(props.connection as any).id}`);
}
</script>

<template>
    <AppLayout>
        <div class="max-w-2xl p-6">
            <h1 class="mb-6 text-2xl font-semibold">{{ $t('whatsapp.connections.edit') }}</h1>
            <form class="space-y-4" @submit.prevent="submit">
                <label class="block">
                    <span class="text-sm font-medium">{{ $t('whatsapp.connections.name') }}</span>
                    <input v-model="form.name" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">{{ $t('whatsapp.connections.status') }}</span>
                    <select v-model="form.status" class="mt-1 w-full rounded border px-3 py-2">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                    </select>
                </label>
                <p class="text-sm text-muted-foreground">
                    {{ $t('whatsapp.connections.credentials_hint') }}
                </p>
                <label class="block">
                    <span class="text-sm font-medium">Access Token</span>
                    <input v-model="form.auth.access_token" placeholder="••••" type="password" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium">App Secret</span>
                    <input v-model="form.auth.app_secret" placeholder="••••" type="password" class="mt-1 w-full rounded border px-3 py-2" />
                </label>
                <button type="submit" :disabled="form.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">
                    {{ $t('whatsapp.connections.save') }}
                </button>
            </form>
        </div>
    </AppLayout>
</template>
