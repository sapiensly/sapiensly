<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md text-sidebar-primary-foreground"
    >
        <AppLogoIcon
            class="size-5 fill-transparent text-white dark:text-black"
        />
    </div>
    <div
        class="ml-1 grid flex-1 text-left text-sm"
        style="font-family: 'Montserrat', sans-serif; color: rgb(0 89 255)"
    >
        <span class="ml-[-7px] truncate text-[16px] font-bold italic"
            >SAPIENSLY</span
        >
    </div>
</template>
