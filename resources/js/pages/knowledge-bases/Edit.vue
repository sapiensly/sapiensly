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
import type { KnowledgeBase } from '@/types/knowledge-base';
import { Head, Link, useForm } from '@inertiajs/vue3';
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
        <div class="mx-auto max-w-2xl space-y-6">
            <PageHeader
                :title="`${t('knowledge_bases.edit.title')} ${knowledgeBase.name}`"
                :description="t('knowledge_bases.edit.description')"
            />

                <form class="mt-8 space-y-8" @submit.prevent="submit">
                    <div class="space-y-6">
                        <HeadingSmall
                            title="Basic Information"
                            description="Name and describe your knowledge base"
                        />

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="name">Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    placeholder="My Knowledge Base"
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">Description</Label>
                                <Textarea
                                    id="description"
                                    v-model="form.description"
                                    placeholder="What kind of documents will this knowledge base contain?"
                                    rows="3"
                                />
                                <InputError
                                    :message="form.errors.description"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="keywords">Keywords</Label>
                                <KeywordsInput v-model="form.keywords" />
                                <p class="text-xs text-muted-foreground">
                                    Add keywords to help with search and
                                    categorization
                                </p>
                                <InputError :message="form.errors.keywords" />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <HeadingSmall
                            title="Processing Configuration"
                            description="Configure how documents are processed"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="chunk_size">Chunk Size</Label>
                                <Input
                                    id="chunk_size"
                                    v-model.number="form.config.chunk_size"
                                    type="number"
                                    min="100"
                                    max="4000"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Number of characters per chunk (100-4000)
                                </p>
                                <InputError
                                    :message="form.errors['config.chunk_size']"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="chunk_overlap">Chunk Overlap</Label>
                                <Input
                                    id="chunk_overlap"
                                    v-model.number="form.config.chunk_overlap"
                                    type="number"
                                    min="0"
                                    max="500"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Overlap between chunks (0-500)
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
                            <Link
                                :href="
                                    KnowledgeBaseController.show({
                                        knowledge_base: knowledgeBase.id,
                                    }).url
                                "
                            >
                                {{ t('common.cancel') }}
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ t('common.save_changes') }}
                        </Button>
                    </div>
                </form>
        </div>
    </AppLayoutV2>
</template>
