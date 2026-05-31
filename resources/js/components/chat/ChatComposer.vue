<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    ChatAgentOption,
    ChatAttachmentDto,
    ChatModelOption,
    ChatToolOption,
} from '@/types/chatModule';
import axios from 'axios';
import {
    ArrowUp,
    Bot,
    ChevronDown,
    FileText,
    Globe,
    Loader2,
    Paperclip,
    Sparkles,
    Square,
    Wrench,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        models: ChatModelOption[];
        model: string | null;
        busy?: boolean;
        chatId?: string | null;
        autofocus?: boolean;
        tools?: ChatToolOption[];
        toolIds?: string[];
        agents?: ChatAgentOption[];
    }>(),
    { tools: () => [], toolIds: () => [], agents: () => [] },
);

const emit = defineEmits<{
    submit: [
        payload: {
            content: string;
            attachmentIds: string[];
            webSearch: boolean;
            toolIds: string[];
        },
    ];
    'update:model': [value: string];
    'update:toolIds': [value: string[]];
    stop: [];
    'requires-chat': [];
}>();

const text = ref('');
const webSearch = ref(false);
const textarea = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const attachments = ref<ChatAttachmentDto[]>([]);
const uploading = ref(false);
const uploadError = ref<string | null>(null);

const selectedAgent = computed(() =>
    props.model?.startsWith('agent:')
        ? props.agents.find((a) => `agent:${a.id}` === props.model)
        : undefined,
);
const selectedModel = computed(() =>
    props.models.find((m) => m.value === props.model),
);
const pickerLabel = computed(
    () =>
        selectedAgent.value?.name ??
        selectedModel.value?.label ??
        t('chat.model_picker.label'),
);

function toggleTool(id: string) {
    const next = props.toolIds.includes(id)
        ? props.toolIds.filter((t) => t !== id)
        : [...props.toolIds, id];
    emit('update:toolIds', next);
}

// Group models by provider for a tidy picker.
const grouped = computed(() => {
    const map = new Map<string, ChatModelOption[]>();
    for (const m of props.models) {
        if (!map.has(m.provider)) map.set(m.provider, []);
        map.get(m.provider)!.push(m);
    }
    return [...map.entries()];
});

const canSubmit = computed(
    () =>
        !props.busy &&
        !uploading.value &&
        (text.value.trim() !== '' || attachments.value.length > 0),
);

function autoGrow() {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 320) + 'px';
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        submit();
    }
}

function submit() {
    if (!canSubmit.value) return;
    // An agent brings its own tools + search config, so ignore the composer's
    // web-search / tool selections when one is active.
    emit('submit', {
        content: text.value.trim(),
        attachmentIds: attachments.value.map((a) => a.id),
        webSearch: selectedAgent.value ? false : webSearch.value,
        toolIds: selectedAgent.value ? [] : [...props.toolIds],
    });
    text.value = '';
    attachments.value = [];
    nextTick(autoGrow);
}

function pickFiles() {
    if (!props.chatId) {
        emit('requires-chat');
        return;
    }
    fileInput.value?.click();
}

async function onFilesChosen(e: Event) {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    if (!files.length || !props.chatId) return;

    uploading.value = true;
    uploadError.value = null;
    try {
        for (const file of files) {
            const form = new FormData();
            form.append('file', file);
            const { data } = await axios.post(
                `/chat/${props.chatId}/attachments`,
                form,
            );
            attachments.value.push(data as ChatAttachmentDto);
        }
    } catch (err) {
        uploadError.value =
            axios.isAxiosError(err) && err.response?.data?.message
                ? (err.response.data.message as string)
                : t('chat.attach_failed');
    } finally {
        uploading.value = false;
    }
}

function removeAttachment(id: string) {
    attachments.value = attachments.value.filter((a) => a.id !== id);
}

function focus() {
    textarea.value?.focus();
}

defineExpose({ focus });
</script>

<template>
    <div>
        <div
            class="rounded-[1.625rem] border border-medium bg-surface px-2 py-2 shadow-md transition-all focus-within:border-strong focus-within:shadow-lg"
        >
            <!-- Attachment chips. -->
            <div
                v-if="attachments.length"
                class="flex flex-wrap gap-2 px-2 pt-1.5 pb-1"
            >
                <span
                    v-for="a in attachments"
                    :key="a.id"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-medium bg-white/5 py-1 pr-1 pl-1.5 text-xs text-ink"
                >
                    <FileText class="size-3.5 text-ink-subtle" />
                    <span class="max-w-[160px] truncate">{{
                        a.original_name
                    }}</span>
                    <button
                        type="button"
                        class="rounded p-0.5 text-ink-subtle hover:bg-white/10 hover:text-ink"
                        @click="removeAttachment(a.id)"
                    >
                        <X class="size-3" />
                    </button>
                </span>
            </div>

            <textarea
                ref="textarea"
                v-model="text"
                :placeholder="t('chat.composer.placeholder')"
                rows="1"
                class="block max-h-80 w-full resize-none bg-transparent px-3 pt-2.5 pb-1 text-[15px] leading-6 text-ink placeholder:text-ink-subtle focus:outline-none"
                :autofocus="autofocus"
                @input="autoGrow"
                @keydown="onKeydown"
            />

            <p v-if="uploadError" class="px-3 pb-1 text-[11px] text-sp-danger">
                {{ uploadError }}
            </p>

            <div class="flex items-center justify-between gap-2 px-1 pt-0.5">
                <div class="flex items-center gap-0.5">
                    <button
                        type="button"
                        :title="t('chat.attach')"
                        class="inline-flex size-8 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-white/10 hover:text-ink disabled:opacity-40"
                        :disabled="uploading"
                        @click="pickFiles"
                    >
                        <Loader2
                            v-if="uploading"
                            class="size-[18px] animate-spin"
                        />
                        <Paperclip v-else class="size-[18px]" />
                    </button>
                    <input
                        ref="fileInput"
                        type="file"
                        multiple
                        class="hidden"
                        @change="onFilesChosen"
                    />

                    <!-- Model picker. -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[13px] font-medium text-ink-muted transition-colors hover:bg-white/10 hover:text-ink"
                            >
                                <Bot
                                    v-if="selectedAgent"
                                    class="size-3.5 text-accent-blue"
                                />
                                <Sparkles
                                    v-else
                                    class="size-3.5 text-accent-blue"
                                />
                                <span class="max-w-[200px] truncate">{{
                                    pickerLabel
                                }}</span>
                                <ChevronDown class="size-3.5 opacity-50" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            class="max-h-80 w-64 overflow-y-auto"
                        >
                            <DropdownMenuRadioGroup
                                :model-value="model ?? ''"
                                @update:model-value="
                                    (v) => emit('update:model', v as string)
                                "
                            >
                                <!-- Agents first: pick a configured agent (its model, prompt, KBs and tools). -->
                                <template v-if="agents.length">
                                    <DropdownMenuLabel
                                        class="text-[10px] tracking-wider text-ink-subtle uppercase"
                                    >
                                        {{ t('chat.agent_picker.label') }}
                                    </DropdownMenuLabel>
                                    <DropdownMenuRadioItem
                                        v-for="a in agents"
                                        :key="a.id"
                                        :value="`agent:${a.id}`"
                                    >
                                        <Bot
                                            class="mr-1.5 size-3.5 text-accent-blue"
                                        />
                                        {{ a.name }}
                                    </DropdownMenuRadioItem>
                                    <DropdownMenuSeparator />
                                </template>

                                <template
                                    v-for="[provider, items] in grouped"
                                    :key="provider"
                                >
                                    <DropdownMenuLabel
                                        class="text-[10px] tracking-wider text-ink-subtle uppercase"
                                    >
                                        {{ provider }}
                                    </DropdownMenuLabel>
                                    <DropdownMenuRadioItem
                                        v-for="m in items"
                                        :key="m.value"
                                        :value="m.value"
                                    >
                                        {{ m.label }}
                                    </DropdownMenuRadioItem>
                                    <DropdownMenuSeparator />
                                </template>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <button
                        type="button"
                        :disabled="!!selectedAgent"
                        :title="selectedAgent ? t('chat.agent_manages') : t('chat.web_search')"
                        :aria-pressed="webSearch && !selectedAgent"
                        :class="[
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[13px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent',
                            webSearch && !selectedAgent
                                ? 'bg-accent-blue/15 text-accent-blue'
                                : 'text-ink-muted hover:bg-white/10 hover:text-ink',
                        ]"
                        @click="webSearch = !webSearch"
                    >
                        <Globe class="size-3.5" />
                        <span class="hidden sm:inline">{{
                            t('chat.web_search')
                        }}</span>
                    </button>

                    <!-- Tools / connectors picker. -->
                    <DropdownMenu v-if="tools.length">
                        <DropdownMenuTrigger as-child :disabled="!!selectedAgent">
                            <button
                                type="button"
                                :disabled="!!selectedAgent"
                                :title="selectedAgent ? t('chat.agent_manages') : t('chat.tools.label')"
                                :class="[
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[13px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent',
                                    toolIds.length && !selectedAgent
                                        ? 'bg-accent-blue/15 text-accent-blue'
                                        : 'text-ink-muted hover:bg-white/10 hover:text-ink',
                                ]"
                            >
                                <Wrench class="size-3.5" />
                                <span class="hidden sm:inline">{{
                                    t('chat.tools.label')
                                }}</span>
                                <span
                                    v-if="toolIds.length && !selectedAgent"
                                    class="inline-flex min-w-4 items-center justify-center rounded-full bg-accent-blue px-1 text-[10px] font-semibold text-white"
                                    >{{ toolIds.length }}</span
                                >
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            class="max-h-80 w-64 overflow-y-auto"
                        >
                            <DropdownMenuLabel
                                class="text-[10px] tracking-wider text-ink-subtle uppercase"
                            >
                                {{ t('chat.tools.label') }}
                            </DropdownMenuLabel>
                            <DropdownMenuCheckboxItem
                                v-for="tool in tools"
                                :key="tool.id"
                                :model-value="toolIds.includes(tool.id)"
                                @update:model-value="toggleTool(tool.id)"
                                @select.prevent
                            >
                                <span class="truncate">{{ tool.name }}</span>
                                <span
                                    class="ml-auto text-[10px] text-ink-subtle uppercase"
                                    >{{ tool.type }}</span
                                >
                            </DropdownMenuCheckboxItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <button
                    v-if="busy"
                    type="button"
                    :title="t('chat.composer.stop')"
                    class="inline-flex size-8 items-center justify-center rounded-full bg-accent-blue text-white transition-colors hover:bg-accent-blue-hover"
                    @click="emit('stop')"
                >
                    <Square class="size-3 fill-current" />
                </button>
                <button
                    v-else
                    type="button"
                    :disabled="!canSubmit"
                    :title="t('chat.composer.send')"
                    class="inline-flex size-8 items-center justify-center rounded-full bg-accent-blue text-white transition-all hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:bg-medium disabled:text-ink-subtle"
                    @click="submit"
                >
                    <ArrowUp class="size-[18px]" :stroke-width="2.5" />
                </button>
            </div>
        </div>
        <p class="mt-2 text-center text-[11px] text-ink-subtle">
            {{ t('chat.disclaimer') }}
        </p>
    </div>
</template>
