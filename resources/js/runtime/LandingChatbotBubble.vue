<script setup lang="ts">
import { MessageCircle } from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * The chatbot a landing carries, in the two states a landing has.
 *
 * `live` (the published /l page) loads the SAME widget bundle an external site
 * embeds and hands it the page's own look. Reusing that bundle rather than
 * writing a second chat client is the whole point: a streaming client with
 * sessions, attachments and retries is exactly the kind of thing that rots when
 * it exists twice.
 *
 * `inert` (the builder preview) is a static bubble that loads nothing and talks
 * to nobody. The author needs to see where it sits and that it is there; opening
 * a real session on every preview render would create conversations and bill
 * tokens for a page nobody has visited yet.
 *
 * Note the third state, which is an absence: the off-screen pane the design
 * director is screenshotted from renders neither mode, so the director never
 * sees the bubble and cannot mark a page down for carrying one.
 */
interface Appearance {
    primary_color?: string;
    position?: 'bottom-left' | 'bottom-right';
    welcome_message?: string;
}

const props = withDefaults(
    defineProps<{
        mode?: 'live' | 'inert';
        token?: string | null;
        position?: 'left' | 'right';
        greeting?: string | null;
        /** The page's accent, so the bubble belongs to this design. */
        accent?: string | null;
    }>(),
    {
        mode: 'inert',
        token: null,
        position: 'right',
        greeting: null,
        accent: null,
    },
);

const SCRIPT_ID = 'sapiensly-widget-script';

type WidgetQueue = ((...args: unknown[]) => void) & { q?: unknown[][] };

declare global {
    interface Window {
        SapienslyWidget?: string;
        sapiensly?: WidgetQueue;
    }
}

/** The overrides this page imposes on the bot's stored appearance. */
function appearanceOverrides(): Appearance {
    const appearance: Appearance = {
        position: props.position === 'left' ? 'bottom-left' : 'bottom-right',
    };
    if (props.greeting) appearance.welcome_message = props.greeting;
    if (props.accent) appearance.primary_color = props.accent;

    return appearance;
}

onMounted(() => {
    if (props.mode !== 'live' || !props.token) return;

    // The same command-queue shim the copy-paste snippet installs, so commands
    // issued before the bundle lands are replayed once it does.
    const queue: WidgetQueue =
        window.sapiensly ??
        Object.assign((...args: unknown[]) => {
            (queue.q = queue.q ?? []).push(args);
        }, {} as WidgetQueue);
    window.SapienslyWidget = 'sapiensly';
    window.sapiensly = queue;

    if (!document.getElementById(SCRIPT_ID)) {
        const script = document.createElement('script');
        script.id = SCRIPT_ID;
        script.src = '/widget/v1/widget.js';
        script.async = true;
        document.head.appendChild(script);
    }

    window.sapiensly('init', props.token, {
        appearance: appearanceOverrides(),
    });
});

onBeforeUnmount(() => {
    // The widget mounts itself on <body>, outside this component's tree, so
    // Vue cannot clean it up — leaving it would strand a live bubble over the
    // next page of an SPA navigation.
    if (props.mode === 'live') window.sapiensly?.('destroy');
});
</script>

<template>
    <!-- Live mode renders nothing of its own: the bundle owns its DOM. -->
    <button
        v-if="mode === 'inert'"
        type="button"
        disabled
        aria-hidden="true"
        class="fixed bottom-5 z-40 flex size-14 cursor-default items-center justify-center rounded-full text-white shadow-lg"
        :class="position === 'left' ? 'left-5' : 'right-5'"
        :style="{ background: accent || '#0096ff' }"
        :title="greeting ?? undefined"
    >
        <MessageCircle class="size-6" />
    </button>
</template>
