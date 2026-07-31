<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { computed } from 'vue';

interface Props {
    /** 'brand' = brand blue (default, legacy admin). 'white' = inverted for dark chrome. */
    tone?: 'brand' | 'white';
    /** Icon-only mode for collapsed sidebars. */
    collapsed?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    tone: 'brand',
    collapsed: false,
});

// Brand wordmark colour. tone='brand' is the blue logo in both themes.
// tone='white' is meant for dark chrome (light grey), but on a light theme
// the chrome is light too — so it flips to the brand blue there.
const wordmarkClass = computed(() =>
    props.tone === 'white'
        ? 'text-[rgb(0_89_255)] dark:text-[#E3E3EE]'
        : 'text-[rgb(0_89_255)]',
);
</script>

<template>
    <div
        class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md text-sidebar-primary-foreground"
    >
        <AppLogoIcon
            class="size-7 fill-transparent text-white dark:text-black"
        />
    </div>
    <!-- The mark is filled white on light chrome and black on dark, and the
         dark glyph reads a touch tighter against the wordmark — so the text
         gets 5px of breathing room back in dark mode only. -->
    <div
        v-if="!collapsed"
        class="ml-1 grid flex-1 text-left text-sm dark:pl-[5px]"
        :class="wordmarkClass"
        :style="{ fontFamily: 'Montserrat, sans-serif' }"
    >
        <span class="ml-[-7px] truncate text-[16px] font-bold italic"
            >SAPIENSLY</span
        >
    </div>
</template>
