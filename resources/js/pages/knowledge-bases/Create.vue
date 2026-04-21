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
import type { DocumentTypeOption } from '@/types/knowledge-base';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Database, Slice } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    documentTypes: DocumentTypeOption[];
}

defineProps<Props>();

const form = useForm({
    name: '',
    description: '',
    keywords: [] as string[],
    config: {
        chunk_size: 1000,
        chunk_overlap: 200,
    },
});

const submit = () => {
    form.post(KnowledgeBaseController.store().url);
};
</script>

<template>
    <Head :title="t('knowledge_bases.create.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.knowledge_base')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="t('knowledge_bases.create.heading')"
                :description="t('knowledge_bases.create.description')"
            />

            <form class="space-y-4" @submit.prevent="submit">
                <!-- Basic info. -->
                <SettingsCard
                    :icon="Database"
                    :title="t('knowledge_bases.create.basic_info')"
                    :description="t('knowledge_bases.create.basic_info_description')"
                >
                    <div class="space-y-1.5">
                        <Label for="name">{{ t('common.name') }}</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            :placeholder="t('knowledge_bases.create.name_placeholder')"
                            class="h-9"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description">
                            {{ t('knowledge_bases.create.description_label') }}
                        </Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            :placeholder="t('knowledge_bases.create.description_placeholder')"
                            rows="3"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="keywords">
                            {{ t('knowledge_bases.create.keywords_label') }}
                        </Label>
                        <KeywordsInput v-model="form.keywords" />
                        <p class="text-[11px] text-ink-subtle">
                            {{ t('knowledge_bases.create.keywords_description') }}
                        </p>
                        <InputError :message="form.errors.keywords" />
                    </div>
                </SettingsCard>

                <!-- Chunking / processing. -->
                <SettingsCard
                    :icon="Slice"
                    :title="t('knowledge_bases.create.processing_title')"
                    :description="t('knowledge_bases.create.processing_description')"
                    tint="var(--sp-accent-cyan)"
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="chunk_size">
                                {{ t('knowledge_bases.create.chunk_size') }}
                            </Label>
                            <Input
                                id="chunk_size"
                                v-model.number="form.config.chunk_size"
                                type="number"
                                min="100"
                                max="4000"
                                class="h-9"
                            />
                            <p class="text-[11px] text-ink-subtle">
                                {{ t('knowledge_bases.create.chunk_size_description') }}
                            </p>
                            <InputError :message="form.errors['config.chunk_size']" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="chunk_overlap">
                                {{ t('knowledge_bases.create.chunk_overlap') }}
                            </Label>
                            <Input
                                id="chunk_overlap"
                                v-model.number="form.config.chunk_overlap"
                                type="number"
                                min="0"
                                max="500"
                                class="h-9"
                            />
                            <p class="text-[11px] text-ink-subtle">
                                {{ t('knowledge_bases.create.chunk_overlap_description') }}
                            </p>
                            <InputError :message="form.errors['config.chunk_overlap']" />
                        </div>
                    </div>
                </SettingsCard>

                <!-- Footer actions. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link :href="KnowledgeBaseController.index().url">
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
                        {{ t('knowledge_bases.create.submit') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
