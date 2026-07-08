<script setup lang="ts">
import * as AppController from '@/actions/App/Http/Controllers/AppController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppCard from '@/components/apps/AppCard.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, router } from '@inertiajs/vue3';
import { AppWindow, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

// A new app starts empty and opens straight into the Builder — the first prompt
// names it. No create form: POST to store, which redirects to the Builder.
const creating = ref(false);
function createApp(): void {
    if (creating.value) return;
    creating.value = true;
    router.post(
        AppController.store().url,
        {},
        { onFinish: () => (creating.value = false) },
    );
}

interface AppItem {
    id: string;
    slug: string;
    name: string;
    description: string | null;
    icon: string | null;
    color: string | null;
    kind?: string | null;
    visibility: string;
    created_at: string;
    current_version?: {
        id: string;
        version_number: number;
        created_at: string;
    } | null;
}

interface Props {
    apps: {
        data: AppItem[];
        current_page: number;
        last_page: number;
        total: number;
    };
}

defineProps<Props>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('apps.index.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.apps')">
        <div class="space-y-6">
            <PageHeader
                :title="t('apps.index.title')"
                :description="t('apps.index.description')"
            >
                <template #actions>
                    <button
                        type="button"
                        @click="createApp"
                        :disabled="creating"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        <Plus class="size-3.5" />
                        {{ t('apps.index.create_app') }}
                    </button>
                </template>
            </PageHeader>

            <div
                v-if="apps.data.length > 0"
                class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
            >
                <AppCard v-for="app in apps.data" :key="app.id" :app="app" />
            </div>

            <div
                v-else
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-surface text-ink-muted"
                >
                    <AppWindow class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{ t('apps.index.no_apps') }}
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ t('apps.index.no_apps_description') }}
                </p>
                <button
                    type="button"
                    @click="createApp"
                    :disabled="creating"
                    class="mt-4 inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                >
                    <Plus class="size-3.5" />
                    {{ t('apps.index.create_first') }}
                </button>
            </div>
        </div>
    </AppLayoutV2>
</template>
