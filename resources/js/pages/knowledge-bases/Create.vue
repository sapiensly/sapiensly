<script setup lang="ts">
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { DocumentTypeOption } from '@/types/knowledge-base';
import { Head, Link, useForm } from '@inertiajs/vue3';
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
        <div class="mx-auto max-w-2xl space-y-6">
            <PageHeader
                :title="t('knowledge_bases.create.heading')"
                :description="t('knowledge_bases.create.description')"
            />

                <form class="mt-8 space-y-8" @submit.prevent="submit">
                    <div class="space-y-6">
                        <HeadingSmall
                            :title="t('knowledge_bases.create.basic_info')"
                            :description="
                                t(
                                    'knowledge_bases.create.basic_info_description',
                                )
                            "
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">{{ t('common.name') }}</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    :placeholder="
                                        t(
                                            'knowledge_bases.create.name_placeholder',
                                        )
                                    "
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">{{
                                    t(
                                        'knowledge_bases.create.description_label',
                                    )
                                }}</Label>
                                <Textarea
                                    id="description"
                                    v-model="form.description"
                                    :placeholder="
                                        t(
                                            'knowledge_bases.create.description_placeholder',
                                        )
                                    "
                                    rows="3"
                                />
                                <InputError
                                    :message="form.errors.description"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="keywords">{{
                                    t('knowledge_bases.create.keywords_label')
                                }}</Label>
                                <KeywordsInput v-model="form.keywords" />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            'knowledge_bases.create.keywords_description',
                                        )
                                    }}
                                </p>
                                <InputError :message="form.errors.keywords" />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <HeadingSmall
                            :title="
                                t('knowledge_bases.create.processing_title')
                            "
                            :description="
                                t(
                                    'knowledge_bases.create.processing_description',
                                )
                            "
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="chunk_size">{{
                                    t('knowledge_bases.create.chunk_size')
                                }}</Label>
                                <Input
                                    id="chunk_size"
                                    v-model.number="form.config.chunk_size"
                                    type="number"
                                    min="100"
                                    max="4000"
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            'knowledge_bases.create.chunk_size_description',
                                        )
                                    }}
                                </p>
                                <InputError
                                    :message="form.errors['config.chunk_size']"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="chunk_overlap">{{
                                    t('knowledge_bases.create.chunk_overlap')
                                }}</Label>
                                <Input
                                    id="chunk_overlap"
                                    v-model.number="form.config.chunk_overlap"
                                    type="number"
                                    min="0"
                                    max="500"
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            'knowledge_bases.create.chunk_overlap_description',
                                        )
                                    }}
                                </p>
                                <InputError
                                    :message="
                                        form.errors['config.chunk_overlap']
                                    "
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-4">
                        <Button variant="outline" as-child>
                            <Link :href="KnowledgeBaseController.index().url">
                                {{ t('common.cancel') }}
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ t('knowledge_bases.create.submit') }}
                        </Button>
                    </div>
                </form>
        </div>
    </AppLayoutV2>
</template>
