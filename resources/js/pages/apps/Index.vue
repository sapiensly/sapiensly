<script setup lang="ts">
import * as AppController from '@/actions/App/Http/Controllers/AppController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppCard from '@/components/apps/AppCard.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, router } from '@inertiajs/vue3';
import { AppWindow, Plus, Upload } from '@lucide/vue';
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

interface Template {
    slug: string;
    name: string;
    description: string;
    icon: string | null;
    kind: string;
    objects: number;
    pages: number;
    /** 'organization' — one this account saved; 'builtin' — one we ship. */
    source: string;
    records: boolean;
}

interface Props {
    apps: {
        data: AppItem[];
        current_page: number;
        last_page: number;
        total: number;
    };
    templates?: Template[];
}

const props = defineProps<Props>();

const { t } = useI18n();

// Start from a template instead of an empty page. It installs through the same
// package path an uploaded file takes, so what you get is a real app you can
// immediately export again.
function useTemplate(slug: string): void {
    if (creating.value) return;
    creating.value = true;
    router.post(
        '/apps/from-template',
        { template: slug },
        { onFinish: () => (creating.value = false) },
    );
}

// Install an app someone exported. Anything the package could not carry is
// reported on the other side, in the Builder.
const importInput = ref<HTMLInputElement | null>(null);
// Only an organization's own can be removed — the built-ins ship with the code.
function removeTemplate(slug: string): void {
    router.delete(`/apps/templates/${slug}`, { preserveScroll: true });
}

function importPackage(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    router.post('/apps/import', { package: file }, { forceFormData: true });
}
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
                    <!-- Install an app someone exported. -->
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink-muted transition-colors hover:text-ink"
                        @click="importInput?.click()"
                    >
                        <Upload class="size-3.5" />
                        {{ t('apps.index.import') }}
                    </button>
                    <input
                        ref="importInput"
                        type="file"
                        accept=".json,application/json"
                        class="hidden"
                        @change="importPackage"
                    />

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

            <!-- Starter templates. Shown above the grid so the first thing a
                 new account sees is something real to start from, not an empty
                 page and a blinking prompt. -->
            <section v-if="props.templates?.length" class="space-y-3">
                <h2
                    class="text-xs font-medium tracking-wide text-ink-muted uppercase"
                >
                    {{ t('apps.index.templates') }}
                </h2>
                <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="tpl in props.templates"
                        :key="tpl.slug"
                        type="button"
                        :disabled="creating"
                        class="rounded-sp-sm border border-soft bg-navy/40 px-4 py-3 text-left transition-colors hover:border-accent-blue/40 disabled:opacity-50"
                        @click="useTemplate(tpl.slug)"
                    >
                        <p class="text-sm font-medium text-ink">
                            {{ tpl.name }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ tpl.description }}
                        </p>
                        <p
                            class="mt-2 flex items-center gap-2 text-[11px] text-ink-muted"
                        >
                            <span>
                                {{
                                    t('apps.index.template_contents', {
                                        objects: tpl.objects,
                                        pages: tpl.pages,
                                    })
                                }}
                            </span>
                            <span
                                v-if="tpl.records"
                                class="rounded-pill bg-accent-blue/10 px-1.5 text-accent-blue"
                            >
                                {{ t('apps.index.template_with_data') }}
                            </span>
                            <span
                                v-if="tpl.source === 'organization'"
                                class="ml-auto cursor-pointer hover:text-red-400"
                                role="button"
                                @click.stop="removeTemplate(tpl.slug)"
                            >
                                {{ t('apps.index.template_remove') }}
                            </span>
                        </p>
                    </button>
                </div>
            </section>

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
