<script setup lang="ts">
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { KnowledgeBase } from '@/types/knowledge-base';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Database, Slice } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    knowledgeBase: KnowledgeBase;
}

const props = defineProps<Props>();

const form = useForm({
    name: props.knowledgeBase.name,
    description: props.knowledgeBase.description ?? '',
    keywords: props.knowledgeBase.keywords ?? [],
    config: {
        chunk_size: props.knowledgeBase.config?.chunk_size ?? 1000,
        chunk_overlap: props.knowledgeBase.config?.chunk_overlap ?? 200,
    },
});

const submit = () => {
    form.put(
        KnowledgeBaseController.update({
            knowledge_base: props.knowledgeBase.id,
        }).url,
    );
};
</script>

<template>
    <Head :title="`${t('knowledge_bases.edit.title')} ${knowledgeBase.name}`" />

    <AppLayoutV2 :title="t('app_v2.nav.knowledge_base')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="`${t('knowledge_bases.edit.title')} ${knowledgeBase.name}`"
                :description="t('knowledge_bases.edit.description')"
            />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- Basic info. -->
                <SettingsCard
                    :icon="Database"
                    title="Basic Information"
                    description="Name and describe your knowledge base"
                >
                    <div class="space-y-1.5">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            placeholder="My Knowledge Base"
                            class="h-9"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description">Description</Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            placeholder="What kind of documents will this knowledge base contain?"
                            rows="3"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="keywords">Keywords</Label>
                        <KeywordsInput v-model="form.keywords" />
                        <p class="text-[11px] text-ink-subtle">
                            Add keywords to help with search and categorization
                        </p>
                        <InputError :message="form.errors.keywords" />
                    </div>
                </SettingsCard>

                <!-- Chunking / processing. -->
                <SettingsCard
                    :icon="Slice"
                    title="Processing Configuration"
                    description="Configure how documents are processed"
                    tint="var(--sp-accent-cyan)"
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="chunk_size">Chunk Size</Label>
                            <Input
                                id="chunk_size"
                                v-model.number="form.config.chunk_size"
                                type="number"
                                min="100"
                                max="4000"
                                class="h-9"
                            />
                            <p class="text-[11px] text-ink-subtle">
                                Number of characters per chunk (100–4000)
                            </p>
                            <InputError :message="form.errors['config.chunk_size']" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="chunk_overlap">Chunk Overlap</Label>
                            <Input
                                id="chunk_overlap"
                                v-model.number="form.config.chunk_overlap"
                                type="number"
                                min="0"
                                max="500"
                                class="h-9"
                            />
                            <p class="text-[11px] text-ink-subtle">
                                Overlap between chunks (0–500)
                            </p>
                            <InputError :message="form.errors['config.chunk_overlap']" />
                        </div>
                    </div>
                </SettingsCard>

                <!-- Footer actions. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link
                        :href="
                            KnowledgeBaseController.show({
                                knowledge_base: knowledgeBase.id,
                            }).url
                        "
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        >
                            {{ t('common.cancel') }}
                        </button>
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('common.save_changes') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
