<script setup lang="ts">
import * as SlidesController from '@/actions/App/Http/Controllers/SlidesController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Play, Presentation, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

interface DeckItem {
    id: string;
    name: string;
    theme: string;
    slide_count: number;
    updated_at: string | null;
    created_by: string | null;
}

defineProps<{ decks: DeckItem[] }>();

const { t, locale } = useI18n();

function formatDate(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(locale.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function destroyDeck(deck: DeckItem) {
    if (!confirm(t('slides.index.delete_confirm', { name: deck.name }))) {
        return;
    }
    router.delete(SlidesController.destroy(deck.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="t('slides.index.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.slides')">
        <div class="space-y-6">
            <PageHeader
                :title="t('slides.index.title')"
                :description="t('slides.index.description')"
            />

            <div
                v-if="decks.length > 0"
                class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
            >
                <Link
                    v-for="deck in decks"
                    :key="deck.id"
                    :href="SlidesController.builder(deck.id).url"
                    class="block"
                >
                    <article
                        class="group flex h-full flex-col gap-3 rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/40"
                    >
                        <header class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                                >
                                    <Presentation class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <h3
                                        class="truncate text-sm font-semibold text-ink"
                                    >
                                        {{ deck.name }}
                                    </h3>
                                    <p class="mt-0.5 text-xs text-ink-muted">
                                        {{
                                            t('slides.index.slide_count', {
                                                count: deck.slide_count,
                                            })
                                        }}
                                        · {{ deck.theme }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <a
                                    :href="
                                        SlidesController.present(deck.id).url
                                    "
                                    target="_blank"
                                    class="rounded-xs p-1.5 text-ink-subtle opacity-0 transition-opacity group-hover:opacity-100 hover:text-accent-blue"
                                    :aria-label="t('slides.index.present')"
                                    :title="t('slides.index.present')"
                                    @click.stop
                                >
                                    <Play class="size-3.5" />
                                </a>
                                <button
                                    type="button"
                                    class="rounded-xs p-1.5 text-ink-subtle opacity-0 transition-opacity group-hover:opacity-100 hover:text-sp-danger"
                                    :aria-label="t('slides.index.delete')"
                                    @click.prevent.stop="destroyDeck(deck)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </div>
                        </header>
                        <footer
                            class="mt-auto flex items-center justify-between text-[11px] text-ink-subtle"
                        >
                            <span>{{ deck.created_by }}</span>
                            <span>{{ formatDate(deck.updated_at) }}</span>
                        </footer>
                    </article>
                </Link>
            </div>

            <div
                v-else
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-surface text-ink-muted"
                >
                    <Presentation class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{ t('slides.index.empty_title') }}
                </h3>
                <p class="mx-auto mt-1 max-w-md text-xs text-ink-muted">
                    {{ t('slides.index.empty_description') }}
                </p>
            </div>
        </div>
    </AppLayoutV2>
</template>
