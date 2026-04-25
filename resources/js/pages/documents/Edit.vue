<script setup lang="ts">
import ArtifactWorkbench from '@/components/documents/ArtifactWorkbench.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { VisibilityOption } from '@/types/document';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

interface EditableDocument {
    id: string;
    name: string;
    body: string;
    type: string;
    keywords: string[];
    visibility: string;
    folder_id: string | null;
}

interface ChatModelOption {
    value: string;
    label: string;
    provider: string;
}

interface Props {
    document: EditableDocument;
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
    availableChatModels: ChatModelOption[];
    defaultChatModelId: string | null;
}

const props = defineProps<Props>();
const { t } = useI18n();

function goBackToShow() {
    router.visit(`/documents/${props.document.id}`);
}
</script>

<template>
    <Head :title="document.name || t('documents.workbench.title')" />

    <AppLayoutV2 :title="document.name || t('documents.workbench.title')">
        <div class="flex h-[calc(100vh-9rem)] w-full flex-col">
            <div class="mb-3">
                <!--
                  Document is already persisted, so "back" takes the user
                  to that document's Show page — their prior context —
                  not to the flat listing. Discarding unsaved changes is
                  a separate action (Descartar button inside the workbench).
                -->
                <Link
                    :href="`/documents/${document.id}`"
                    class="inline-flex items-center gap-1 text-[11px] text-ink-muted transition-colors hover:text-ink"
                >
                    <ArrowLeft class="size-3" />
                    {{ t('documents.workbench.back_to_document') }}
                </Link>
            </div>
            <div class="min-h-0 flex-1">
                <ArtifactWorkbench
                    save-mode="update"
                    :document-id="document.id"
                    :initial-body="document.body"
                    :initial-name="document.name"
                    :initial-keywords="document.keywords"
                    :initial-visibility="document.visibility"
                    :current-folder-id="document.folder_id"
                    :visibility-options="visibilityOptions"
                    :can-share-with-org="canShareWithOrg"
                    :available-chat-models="availableChatModels"
                    :default-chat-model-id="defaultChatModelId"
                    @discard="goBackToShow"
                />
            </div>
        </div>
    </AppLayoutV2>
</template>
