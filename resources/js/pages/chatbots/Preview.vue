<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Chatbot } from '@/types/chatbot';
import { Head, Link } from '@inertiajs/vue3';
import { MessageSquare, Send, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    chatbot: Chatbot;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Chatbots', href: ChatbotController.index().url },
    { title: props.chatbot.name, href: ChatbotController.show({ chatbot: props.chatbot.id }).url },
    { title: 'Preview', href: '#' },
]);

const isOpen = ref(true);
const message = ref('');
const messages = ref<Array<{ role: 'user' | 'assistant'; content: string }>>([]);

const config = computed(() => props.chatbot.config);

const sendMessage = () => {
    if (!message.value.trim()) return;

    messages.value.push({
        role: 'user',
        content: message.value,
    });

    const userMessage = message.value;
    message.value = '';

    // Simulate assistant response
    setTimeout(() => {
        messages.value.push({
            role: 'assistant',
            content: `This is a preview response to: "${userMessage}". The actual widget will connect to your ${props.chatbot.agent ? 'agent' : 'team'}.`,
        });
    }, 1000);
};
</script>

<template>
    <Head title="Preview Chatbot" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        title="Preview Widget"
                        :description="`See how ${chatbot.name} looks and behaves`"
                    />
                    <div class="flex gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="ChatbotController.edit({ chatbot: chatbot.id }).url">
                                Edit Settings
                            </Link>
                        </Button>
                        <Button as-child>
                            <Link :href="ChatbotController.embed({ chatbot: chatbot.id }).url">
                                Get Embed Code
                            </Link>
                        </Button>
                    </div>
                </div>

                <!-- Preview Container -->
                <div class="relative min-h-[600px] rounded-lg border bg-gradient-to-br from-gray-100 to-gray-200 p-8 dark:from-gray-800 dark:to-gray-900">
                    <!-- Simulated Website Content -->
                    <div class="space-y-4">
                        <div class="h-12 w-48 rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-full rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-3/4 rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-5/6 rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-32 w-full rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-full rounded bg-gray-300 dark:bg-gray-700" />
                        <div class="h-4 w-2/3 rounded bg-gray-300 dark:bg-gray-700" />
                    </div>

                    <!-- Widget Preview -->
                    <div
                        class="absolute bottom-4"
                        :class="config.appearance.position === 'bottom-right' ? 'right-4' : 'left-4'"
                    >
                        <!-- Chat Bubble -->
                        <button
                            v-if="!isOpen"
                            class="flex h-14 w-14 items-center justify-center rounded-full shadow-lg transition-transform hover:scale-105"
                            :style="{ backgroundColor: config.appearance.primary_color }"
                            @click="isOpen = true"
                        >
                            <MessageSquare class="h-6 w-6 text-white" />
                        </button>

                        <!-- Chat Window -->
                        <div
                            v-else
                            class="flex h-[480px] w-[360px] flex-col overflow-hidden rounded-xl shadow-2xl"
                            :style="{ backgroundColor: config.appearance.background_color }"
                        >
                            <!-- Header -->
                            <div
                                class="flex items-center justify-between px-4 py-3"
                                :style="{ backgroundColor: config.appearance.primary_color }"
                            >
                                <span class="font-semibold text-white">
                                    {{ config.appearance.widget_title }}
                                </span>
                                <button
                                    class="text-white/80 hover:text-white"
                                    @click="isOpen = false"
                                >
                                    <X class="h-5 w-5" />
                                </button>
                            </div>

                            <!-- Messages -->
                            <div
                                class="flex-1 overflow-y-auto p-4"
                                :style="{ color: config.appearance.text_color }"
                            >
                                <!-- Welcome Message -->
                                <div
                                    v-if="messages.length === 0"
                                    class="mb-4 rounded-lg p-3"
                                    :style="{
                                        backgroundColor: config.appearance.primary_color + '20',
                                    }"
                                >
                                    {{ config.appearance.welcome_message }}
                                </div>

                                <!-- Message List -->
                                <div class="space-y-3">
                                    <div
                                        v-for="(msg, index) in messages"
                                        :key="index"
                                        class="flex"
                                        :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
                                    >
                                        <div
                                            class="max-w-[80%] rounded-lg px-3 py-2"
                                            :style="{
                                                backgroundColor: msg.role === 'user'
                                                    ? config.appearance.primary_color
                                                    : config.appearance.primary_color + '20',
                                                color: msg.role === 'user'
                                                    ? '#ffffff'
                                                    : config.appearance.text_color,
                                            }"
                                        >
                                            {{ msg.content }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Input -->
                            <div class="border-t p-3">
                                <form class="flex gap-2" @submit.prevent="sendMessage">
                                    <input
                                        v-model="message"
                                        type="text"
                                        :placeholder="config.appearance.placeholder_text"
                                        class="flex-1 rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2"
                                        :style="{
                                            color: config.appearance.text_color,
                                            '--tw-ring-color': config.appearance.primary_color,
                                        }"
                                    />
                                    <button
                                        type="submit"
                                        class="flex h-10 w-10 items-center justify-center rounded-lg text-white"
                                        :style="{ backgroundColor: config.appearance.primary_color }"
                                    >
                                        <Send class="h-4 w-4" />
                                    </button>
                                </form>
                            </div>

                            <!-- Powered By -->
                            <div
                                v-if="config.behavior.show_powered_by"
                                class="border-t py-2 text-center text-xs text-muted-foreground"
                            >
                                Powered by Sapiensly
                            </div>
                        </div>
                    </div>
                </div>

                <p class="mt-4 text-center text-sm text-muted-foreground">
                    This is a preview of how your widget will appear. Try sending a message to see the interaction.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
