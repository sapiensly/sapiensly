<script setup lang="ts">
/**
 * An app's two documents: the user guide and the technical sheet.
 *
 * Both are generated from the manifest on every request, so this page never
 * shows a stale one and there is nothing to regenerate. Opened from the
 * builder's header, and readable by anyone who can see the app.
 */
import PageHeader from '@/components/app-v2/PageHeader.vue';
import DocBody, { type DocSection } from '@/components/apps/DocBody.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookOpen, Download, Wrench } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface Doc {
    kind: string;
    title: string;
    subject: string;
    sections: DocSection[];
}

const props = defineProps<{
    app: {
        id: string;
        slug: string;
        name: string;
        icon: string | null;
        kind: string | null;
        version: number | null;
    };
    documents: { manual: Doc; technical: Doc };
    kind: string;
}>();

const { t } = useI18n();

const active = ref<'manual' | 'technical'>(
    props.kind === 'technical' ? 'technical' : 'manual',
);

const doc = computed<Doc>(() => props.documents[active.value]);

const tabs = [
    { key: 'manual' as const, icon: BookOpen },
    { key: 'technical' as const, icon: Wrench },
];
</script>

<template>
    <Head :title="`${doc.title} · ${app.name}`" />

    <AppLayoutV2 :title="app.name">
        <div class="space-y-6">
            <PageHeader :title="doc.title" :description="doc.subject">
                <template #actions>
                    <Link
                        :href="`/apps/${app.id}/builder`"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink-muted transition-colors hover:text-ink"
                    >
                        <ArrowLeft class="size-3.5" />
                        {{ t('apps.docs.back') }}
                    </Link>
                    <a
                        :href="`/apps/${app.id}/docs/${active}.md`"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink-muted transition-colors hover:text-ink"
                    >
                        <Download class="size-3.5" />
                        {{ t('apps.docs.download') }}
                    </a>
                </template>
            </PageHeader>

            <!-- Both documents are already in the payload: switching is a tab,
                 not a request. -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    :data-sp-doc-tab="tab.key"
                    :aria-pressed="active === tab.key"
                    class="inline-flex items-center gap-1.5 rounded-pill border px-3.5 py-1.5 text-xs transition-colors"
                    :class="
                        active === tab.key
                            ? 'border-accent-blue/30 bg-accent-blue/10 font-medium text-accent-blue'
                            : 'border-medium bg-surface text-ink-muted hover:text-ink'
                    "
                    @click="active = tab.key"
                >
                    <component :is="tab.icon" class="size-3.5" />
                    {{ documents[tab.key].title }}
                </button>
            </div>

            <div class="flex flex-col gap-8 lg:flex-row">
                <!-- The contents list: on a long technical sheet it is the only
                     way to reach the permissions table without scrolling past
                     eleven page trees. -->
                <nav
                    v-if="doc.sections.length > 2"
                    class="order-first shrink-0 lg:order-last lg:w-56"
                >
                    <div class="lg:sticky lg:top-6">
                        <p
                            class="mb-2 text-[11px] tracking-wide text-ink-subtle uppercase"
                        >
                            {{ t('apps.docs.contents') }}
                        </p>
                        <ul class="space-y-1">
                            <li
                                v-for="section in doc.sections"
                                :key="section.id"
                            >
                                <a
                                    :href="`#doc-${section.id}`"
                                    class="block truncate text-xs text-ink-muted transition-colors hover:text-ink"
                                >
                                    {{ section.heading }}
                                </a>
                            </li>
                        </ul>
                    </div>
                </nav>

                <article class="min-w-0 flex-1">
                    <DocBody :sections="doc.sections" />

                    <p
                        class="mt-10 border-t border-soft pt-4 text-xs text-ink-subtle"
                    >
                        {{
                            t('apps.docs.generated', {
                                version: app.version ?? '—',
                            })
                        }}
                    </p>
                </article>
            </div>
        </div>
    </AppLayoutV2>
</template>
