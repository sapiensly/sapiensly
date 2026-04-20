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

const wordmarkColor = computed(() =>
    props.tone === 'white' ? '#E3E3EE' : 'rgb(0 89 255)',
);
</script>

<template>
    <div
        class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md text-sidebar-primary-foreground"
    >
        <AppLogoIcon
            class="size-5 fill-transparent text-white dark:text-black"
        />
    </div>
    <div
        v-if="!collapsed"
        class="ml-1 grid flex-1 text-left text-sm"
        :style="{
            fontFamily: 'Montserrat, sans-serif',
            color: wordmarkColor,
        }"
    >
        <span class="ml-[-7px] truncate text-[16px] font-bold italic"
            >SAPIENSLY</span
        >
    </div>
</template>
