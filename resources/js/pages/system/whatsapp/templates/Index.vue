<script setup lang="ts">
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { useI18n } from 'vue-i18n';

const { t: translate } = useI18n();

defineProps<{
    connection: { id: string; display_phone_number: string };
    templates: Array<{ id: string; name: string; language: string; category: string; status: string }>;
}>();
</script>

<template>
    <AppLayoutV2 :title="translate('app_v2.nav.whatsapp')">
        <div>
            <h1 class="mb-4 text-2xl font-semibold">{{ $t('whatsapp.templates.title') }}</h1>
            <ul class="divide-y rounded border bg-card">
                <li v-for="t in templates" :key="t.id" class="flex items-center justify-between p-3">
                    <div>
                        <div class="font-medium">{{ t.name }}</div>
                        <div class="text-xs text-muted-foreground">{{ t.language }} · {{ t.category }}</div>
                    </div>
                    <span class="rounded bg-secondary px-2 py-0.5 text-xs">{{ t.status }}</span>
                </li>
            </ul>
        </div>
    </AppLayoutV2>
</template>
