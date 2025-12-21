<script setup lang="ts">
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Chatbot, ChatbotApiToken } from '@/types/chatbot';
import { Head, Link } from '@inertiajs/vue3';
import { Check, Copy, Key } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    chatbot: Chatbot;
    embedCode: string;
    apiTokens: ChatbotApiToken[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Chatbots', href: ChatbotController.index().url },
    { title: props.chatbot.name, href: ChatbotController.show({ chatbot: props.chatbot.id }).url },
    { title: 'Embed', href: '#' },
]);

const copied = ref(false);

const copyToClipboard = async () => {
    await navigator.clipboard.writeText(props.embedCode);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};

const formatDate = (date: string | null) => {
    if (!date) return 'Never';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};
</script>

<template>
    <Head title="Embed Chatbot" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-3xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        title="Embed Chatbot"
                        :description="`Add ${chatbot.name} to your website`"
                    />
                    <Button variant="outline" as-child>
                        <Link :href="ChatbotController.show({ chatbot: chatbot.id }).url">
                            Back to Chatbot
                        </Link>
                    </Button>
                </div>

                <div class="space-y-8">
                    <!-- Embed Code Section -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Embed Code"
                            description="Copy and paste this code into your website's HTML, just before the closing </body> tag"
                        />

                        <Card>
                            <CardContent class="pt-6">
                                <div class="relative">
                                    <pre
                                        class="overflow-x-auto rounded-lg bg-muted p-4 font-mono text-sm"
                                    ><code>{{ embedCode }}</code></pre>
                                    <Button
                                        size="sm"
                                        class="absolute right-2 top-2"
                                        :variant="copied ? 'default' : 'outline'"
                                        @click="copyToClipboard"
                                    >
                                        <Check v-if="copied" class="mr-2 h-4 w-4" />
                                        <Copy v-else class="mr-2 h-4 w-4" />
                                        {{ copied ? 'Copied!' : 'Copy' }}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Installation Guide -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Installation Guide"
                            description="Follow these steps to install the widget"
                        />

                        <div class="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle class="text-base">1. Copy the embed code</CardTitle>
                                    <CardDescription>
                                        Click the "Copy" button above to copy the embed code to your clipboard.
                                    </CardDescription>
                                </CardHeader>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle class="text-base">2. Paste into your HTML</CardTitle>
                                    <CardDescription>
                                        Add the code just before the closing &lt;/body&gt; tag on every page where you want the widget to appear.
                                    </CardDescription>
                                </CardHeader>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle class="text-base">3. Verify installation</CardTitle>
                                    <CardDescription>
                                        Reload your website and look for the chat bubble in the corner of your page.
                                    </CardDescription>
                                </CardHeader>
                            </Card>
                        </div>
                    </div>

                    <!-- API Usage -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Widget API"
                            description="Control the widget programmatically"
                        />

                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Basic Commands</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <pre
                                    class="overflow-x-auto rounded-lg bg-muted p-4 font-mono text-sm"
                                ><code>// Open the widget
sapiensly('open');

// Close the widget
sapiensly('close');

// Toggle the widget
sapiensly('toggle');

// Remove the widget
sapiensly('destroy');</code></pre>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Visitor Identification</CardTitle>
                                <CardDescription>
                                    Identify logged-in users to personalize their experience and track conversations.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <pre
                                    class="overflow-x-auto rounded-lg bg-muted p-4 font-mono text-sm"
                                ><code>// Identify a visitor with email and name
sapiensly('identify', {
  email: 'user@example.com',
  name: 'John Doe',
  metadata: {
    plan: 'pro',
    company: 'Acme Inc'
  }
});</code></pre>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Event Listeners</CardTitle>
                                <CardDescription>
                                    Subscribe to widget events for custom integrations.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <pre
                                    class="overflow-x-auto rounded-lg bg-muted p-4 font-mono text-sm"
                                ><code>// Widget is ready
sapiensly('on', 'ready', function() {
  console.log('Widget loaded');
});

// Widget opened/closed
sapiensly('on', 'open', function() {
  console.log('Widget opened');
});

sapiensly('on', 'close', function() {
  console.log('Widget closed');
});

// Message events
sapiensly('on', 'message:sent', function(msg) {
  console.log('User sent:', msg.content);
});

sapiensly('on', 'message:received', function(msg) {
  console.log('Assistant replied:', msg.content);
});

// Error handling
sapiensly('on', 'error', function(error) {
  console.error('Widget error:', error);
});</code></pre>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Framework Integration -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Framework Integration"
                            description="Examples for popular frameworks"
                        />

                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">React / Next.js</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <pre
                                    class="overflow-x-auto rounded-lg bg-muted p-4 font-mono text-sm"
                                ><code>// components/ChatWidget.tsx
'use client';

import { useEffect } from 'react';

declare global {
  interface Window {
    sapiensly: (...args: unknown[]) => void;
  }
}

export function ChatWidget() {
  useEffect(() => {
    // Load the widget script
    const script = document.createElement('script');
    script.src = '{{ embedCode.match(/src="([^"]+)"/)?.[1] ?? '' }}';
    script.async = true;
    document.body.appendChild(script);

    script.onload = () => {
      window.sapiensly('init', 'YOUR_TOKEN');
    };

    return () => {
      window.sapiensly?.('destroy');
      script.remove();
    };
  }, []);

  return null;
}</code></pre>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Vue.js / Nuxt</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <pre
                                    class="overflow-x-auto rounded-lg bg-muted p-4 font-mono text-sm"
                                ><code>&lt;!-- components/ChatWidget.vue --&gt;
&lt;script setup&gt;
import { onMounted, onUnmounted } from 'vue';

onMounted(() => {
  const script = document.createElement('script');
  script.src = 'YOUR_WIDGET_URL';
  script.async = true;
  document.body.appendChild(script);

  script.onload = () => {
    window.sapiensly('init', 'YOUR_TOKEN');
  };
});

onUnmounted(() => {
  window.sapiensly?.('destroy');
});
&lt;/script&gt;

&lt;template&gt;
  &lt;!-- Widget renders itself --&gt;
&lt;/template&gt;</code></pre>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Troubleshooting -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Troubleshooting"
                            description="Common issues and solutions"
                        />

                        <div class="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle class="text-base">Widget not appearing</CardTitle>
                                    <CardDescription>
                                        <ul class="mt-2 list-disc space-y-1 pl-4 text-sm">
                                            <li>Check that the embed code is placed before the closing &lt;/body&gt; tag</li>
                                            <li>Verify that your domain is in the "Allowed Origins" list in chatbot settings</li>
                                            <li>Check the browser console for JavaScript errors</li>
                                            <li>Ensure the chatbot status is "Active"</li>
                                        </ul>
                                    </CardDescription>
                                </CardHeader>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle class="text-base">CORS errors</CardTitle>
                                    <CardDescription>
                                        Add your website's domain to the "Allowed Origins" in the chatbot settings.
                                        Use the exact origin format: <code class="rounded bg-muted px-1">https://yourdomain.com</code>
                                        (no trailing slash). For local development, add <code class="rounded bg-muted px-1">http://localhost:3000</code>.
                                    </CardDescription>
                                </CardHeader>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle class="text-base">Widget styling conflicts</CardTitle>
                                    <CardDescription>
                                        The widget uses isolated styles, but if you notice conflicts, check for
                                        global CSS rules that might affect elements inside the widget container
                                        (#sapiensly-widget).
                                    </CardDescription>
                                </CardHeader>
                            </Card>
                        </div>
                    </div>

                    <!-- API Tokens -->
                    <div class="space-y-6">
                        <HeadingSmall
                            title="API Tokens"
                            description="Manage tokens for this chatbot"
                        />

                        <div class="space-y-3">
                            <Card v-for="token in apiTokens" :key="token.id">
                                <CardHeader class="py-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <Key class="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <CardTitle class="text-sm">{{ token.name }}</CardTitle>
                                                <CardDescription class="font-mono text-xs">
                                                    {{ token.token }}
                                                </CardDescription>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Badge v-if="token.expires_at" variant="outline">
                                                Expires {{ formatDate(token.expires_at) }}
                                            </Badge>
                                            <span class="text-xs text-muted-foreground">
                                                Last used: {{ formatDate(token.last_used_at) }}
                                            </span>
                                        </div>
                                    </div>
                                </CardHeader>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
