<script setup lang="ts">
import KeywordsInput from '@/components/KeywordsInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { VisibilityOption } from '@/types/document';
import { useForm } from '@inertiajs/vue3';
import { FileUp, Globe, Lock, Pencil, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface InlineDocumentType {
    value: string;
    label: string;
    extension: string;
}

interface Props {
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
    currentFolderId?: string | null;
    inlineDocumentTypes: InlineDocumentType[];
    knowledgeBaseId?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    currentFolderId: null,
    knowledgeBaseId: null,
});

const open = defineModel<boolean>('open', { required: true });
const { t } = useI18n();

const form = useForm({
    type: 'md' as string,
    name: '',
    body: '',
    keywords: [] as string[],
    visibility: 'private',
    folder_id: props.currentFolderId,
    knowledge_base_id: props.knowledgeBaseId,
});

const activeTab = ref<'write' | 'import'>('write');
const fileInput = ref<HTMLInputElement | null>(null);
const importError = ref<string | null>(null);

watch(
    () => props.currentFolderId,
    (next) => {
        form.folder_id = next;
    },
);

watch(
    () => form.type,
    () => {
        importError.value = null;
    },
);

const acceptForType = computed(() => {
    switch (form.type) {
        case 'md':
            return '.md,.markdown,.txt';
        case 'artifact':
            return '.html,.htm';
        default:
            return '.txt';
    }
});

const placeholderForType = computed(() => {
    switch (form.type) {
        case 'md':
            return '# Title\n\nParagraph...\n\n```mermaid\ngraph TD;\n  A-->B;\n```';
        case 'artifact':
            return '<!doctype html>\n<html>\n  <body>\n    <h1>Hello</h1>\n    <script>console.log("running in sandbox");<\/script>\n  </body>\n</html>';
        default:
            return 'Type the document content here…';
    }
});

async function onFileSelected(e: Event) {
    const target = e.target as HTMLInputElement;
    const file = target.files?.[0];
    if (!file) return;

    const MAX = 512 * 1024;
    if (file.size > MAX) {
        importError.value = t('documents.create.file_too_large');
        return;
    }

    try {
        const text = await file.text();
        form.body = text;
        if (!form.name) {
            form.name = file.name.replace(/\.[^/.]+$/, '');
        }
        activeTab.value = 'write';
    } catch {
        importError.value = t('documents.create.read_error');
    } finally {
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }
}

function submit() {
    form.post('/documents/inline', {
        onSuccess: () => {
            open.value = false;
            form.reset();
            activeTab.value = 'write';
        },
    });
}

function handleClose() {
    open.value = false;
    form.reset();
    activeTab.value = 'write';
    importError.value = null;
}

// Public visibility is allowed only for Artifact documents; filter the option
// out for other types so it can't be chosen.
const availableVisibilityOptions = computed(() =>
    props.visibilityOptions.filter(
        (o) => o.value !== 'public' || form.type === 'artifact',
    ),
);

function visibilityIcon(value: string) {
    return value === 'public' ? Globe : value === 'organization' ? Users : Lock;
}

// Reset visibility to Private if it becomes invalid after a type change.
watch(
    () => form.type,
    (next) => {
        if (form.visibility === 'public' && next !== 'artifact') {
            form.visibility = 'private';
        }
    },
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ t('documents.create.title') }}</DialogTitle>
                <DialogDescription>
                    {{ t('documents.create.description') }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="doc-type">{{
                            t('documents.create.type')
                        }}</Label>
                        <Select id="doc-type" v-model="form.type">
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in props.inlineDocumentTypes"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-2">
                        <Label for="doc-name">{{
                            t('documents.create.name')
                        }}</Label>
                        <Input
                            id="doc-name"
                            v-model="form.name"
                            :placeholder="t('documents.create.name_placeholder')"
                        />
                    </div>
                </div>

                <Tabs v-model="activeTab" class="w-full">
                    <TabsList>
                        <TabsTrigger value="write">
                            <Pencil class="mr-1.5 h-3.5 w-3.5" />
                            {{ t('documents.create.write_tab') }}
                        </TabsTrigger>
                        <TabsTrigger value="import">
                            <FileUp class="mr-1.5 h-3.5 w-3.5" />
                            {{ t('documents.create.import_tab') }}
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="write" class="mt-3">
                        <textarea
                            v-model="form.body"
                            :placeholder="placeholderForType"
                            rows="14"
                            class="w-full rounded border bg-background p-3 font-mono text-sm"
                            spellcheck="false"
                        />
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ t('documents.create.body_hint') }}
                        </p>
                    </TabsContent>

                    <TabsContent value="import" class="mt-3">
                        <label
                            class="flex cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed border-muted-foreground/30 p-8 text-center hover:border-primary/50"
                        >
                            <input
                                ref="fileInput"
                                type="file"
                                class="hidden"
                                :accept="acceptForType"
                                @change="onFileSelected"
                            />
                            <FileUp class="mb-2 h-8 w-8 text-muted-foreground" />
                            <span class="text-sm">
                                {{ t('documents.create.import_cta') }}
                            </span>
                            <span
                                class="mt-1 text-xs text-muted-foreground"
                                >{{ acceptForType }}</span
                            >
                        </label>
                        <p
                            v-if="importError"
                            class="mt-2 text-xs text-destructive"
                        >
                            {{ importError }}
                        </p>
                    </TabsContent>
                </Tabs>

                <div class="space-y-2">
                    <Label for="doc-keywords">{{
                        t('documents.create.keywords')
                    }}</Label>
                    <KeywordsInput v-model="form.keywords" />
                </div>

                <div class="space-y-2">
                    <Label for="doc-visibility">{{
                        t('documents.create.visibility')
                    }}</Label>
                    <Select v-model="form.visibility">
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in availableVisibilityOptions"
                                :key="option.value"
                                :value="option.value"
                                :disabled="
                                    option.value === 'organization' &&
                                    !canShareWithOrg
                                "
                            >
                                <div class="flex items-center gap-2">
                                    <component
                                        :is="visibilityIcon(option.value)"
                                        class="h-4 w-4"
                                    />
                                    {{ option.label }}
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="
                            availableVisibilityOptions.find(
                                (o) => o.value === form.visibility,
                            )?.description
                        "
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            availableVisibilityOptions.find(
                                (o) => o.value === form.visibility,
                            )?.description
                        }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="handleClose">
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="
                            form.processing || !form.body || !form.name
                        "
                    >
                        {{ t('documents.create.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
