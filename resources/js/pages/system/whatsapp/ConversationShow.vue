<script setup lang="ts">
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    conversation: {
        id: string;
        status: string;
        contact: { profile_name?: string | null; phone_e164?: string | null };
        messages: Array<{ id: string; role: string; direction: string; content: string; created_at: string }>;
    };
    templates: Array<{ id: string; name: string; language: string }>;
    within_session_window: boolean;
}>();

const replyForm = useForm({ content: '', template_id: null as string | null });

function takeover() {
    router.post(`/system/whatsapp/inbox/${props.conversation.id}/takeover`);
}

function release() {
    router.post(`/system/whatsapp/inbox/${props.conversation.id}/release`);
}

function submitReply() {
    replyForm.post(`/system/whatsapp/inbox/${props.conversation.id}/reply`, {
        onSuccess: () => replyForm.reset('content'),
    });
}
</script>

<template>
    <AppLayoutV2 :title="t('app_v2.nav.whatsapp')">
        <div class="mx-auto max-w-3xl">
            <div class="mb-4 flex items-center justify-between">
                <h1 class="text-xl font-semibold">{{ props.conversation.contact.profile_name ?? props.conversation.contact.phone_e164 }}</h1>
                <div class="flex gap-2">
                    <button
                        v-if="props.conversation.status !== 'escalated'"
                        class="rounded bg-secondary px-3 py-1 text-sm"
                        @click="takeover"
                    >
                        {{ $t('whatsapp.inbox.takeover') }}
                    </button>
                    <button v-else class="rounded bg-secondary px-3 py-1 text-sm" @click="release">
                        {{ $t('whatsapp.inbox.release') }}
                    </button>
                </div>
            </div>

            <div class="mb-4 space-y-2 rounded border bg-card p-4">
                <div
                    v-for="m in props.conversation.messages"
                    :key="m.id"
                    :class="m.direction === 'outbound' ? 'ml-auto bg-primary text-primary-foreground' : 'bg-muted'"
                    class="max-w-[80%] rounded p-2 text-sm"
                >
                    {{ m.content }}
                </div>
            </div>

            <form class="space-y-2" @submit.prevent="submitReply">
                <div v-if="!props.within_session_window" class="rounded bg-amber-50 p-2 text-xs text-amber-900">
                    {{ $t('whatsapp.inbox.out_of_window') }}
                </div>
                <textarea
                    v-model="replyForm.content"
                    rows="3"
                    class="w-full rounded border px-3 py-2"
                    :placeholder="$t('whatsapp.inbox.reply_placeholder')"
                />
                <button
                    type="submit"
                    :disabled="replyForm.processing || !replyForm.content"
                    class="rounded bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                >
                    {{ $t('whatsapp.inbox.send') }}
                </button>
            </form>
        </div>
    </AppLayoutV2>
</template>
