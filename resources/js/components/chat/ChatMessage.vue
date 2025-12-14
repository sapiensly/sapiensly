<script setup lang="ts">
import { computed } from 'vue';
import { Bot, User } from 'lucide-vue-next';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import type { Message } from '@/types/chat';

const props = defineProps<{
    message: Message | { role: 'assistant' | 'user'; content: string };
    isStreaming?: boolean;
}>();

const isUser = computed(() => props.message.role === 'user');
const formattedTime = computed(() => {
    if ('created_at' in props.message && props.message.created_at) {
        return new Date(props.message.created_at).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        });
    }
    return '';
});
</script>

<template>
    <div
        class="flex gap-3"
        :class="isUser ? 'flex-row-reverse' : 'flex-row'"
    >
        <Avatar class="h-8 w-8 shrink-0">
            <AvatarFallback
                :class="isUser ? 'bg-primary text-primary-foreground' : 'bg-muted'"
            >
                <User v-if="isUser" class="h-4 w-4" />
                <Bot v-else class="h-4 w-4" />
            </AvatarFallback>
        </Avatar>

        <div
            class="max-w-[80%] rounded-lg px-4 py-2"
            :class="
                isUser
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted text-foreground'
            "
        >
            <p class="whitespace-pre-wrap text-sm">
                {{ message.content }}
                <span
                    v-if="isStreaming && !isUser"
                    class="inline-block h-4 w-1 animate-pulse bg-current"
                />
            </p>
            <p
                v-if="formattedTime"
                class="mt-1 text-xs opacity-70"
            >
                {{ formattedTime }}
            </p>
        </div>
    </div>
</template>
