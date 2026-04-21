<script setup lang="ts">
import '@/../css/admin.css';

import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { useLocaleSync } from '@/composables/useLocale';
import { Link } from '@inertiajs/vue3';

useLocaleSync();

interface Props {
    /** Background treatment — defaults to 'blueprint' per layout_spec §2. */
    bg?: 'blueprint' | 'flat' | 'spectrum' | 'subtle';
}

withDefaults(defineProps<Props>(), { bg: 'blueprint' });
</script>

<template>
    <div
        class="sp-app-shell flex min-h-screen flex-col items-center justify-center px-4 py-10"
        :data-bg="bg"
    >
        <div class="flex w-full max-w-md flex-col gap-10">
            <!-- Brand block centered above the card. -->
            <Link
                href="/"
                class="flex items-center justify-center gap-2 outline-none"
                aria-label="Sapiensly"
            >
                <AppLogoIcon class="size-11" />
                <span
                    class="text-[24px] font-bold italic tracking-[-0.02em] text-ink"
                    style="font-family: Montserrat, sans-serif"
                >
                    SAPIENSLY
                </span>
            </Link>

            <!-- Card — same recipe as admin-v2 SettingsCard / StatCard chrome. -->
            <section
                class="rounded-sp-sm border border-soft bg-navy px-7 py-8 shadow-sp-float"
            >
                <slot />
            </section>
        </div>
    </div>
</template>
