<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import * as AiProviderController from '@/actions/App/Http/Controllers/AiProviderController';
import * as ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import * as CloudProviderController from '@/actions/App/Http/Controllers/CloudProviderController';
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import * as FlowController from '@/actions/App/Http/Controllers/FlowController';
import * as IntegrationController from '@/actions/App/Http/Controllers/IntegrationController';
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { dashboard } from '@/routes';
import { router } from '@inertiajs/vue3';
import {
    Bot,
    BrainCircuit,
    Cloud,
    Database,
    FileText,
    GitBranch,
    LayoutGrid,
    MessageCircle,
    MessageSquare,
    Plug,
    Users,
    Wrench,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

export interface PaletteCommand {
    id: string;
    group: string;
    label: string;
    icon?: Component;
    perform: () => void;
    keywords?: string[];
}

const { t } = useI18n();
const open = ref(false);

const pageCommands = ref<PaletteCommand[]>([]);

function registerCommand(cmd: PaletteCommand) {
    pageCommands.value = [
        ...pageCommands.value.filter((c) => c.id !== cmd.id),
        cmd,
    ];
    return () => {
        pageCommands.value = pageCommands.value.filter(
            (c) => c.id !== cmd.id,
        );
    };
}

defineExpose({ open, registerCommand });

const navCommands = computed<PaletteCommand[]>(() => [
    {
        id: 'nav-dashboard',
        group: t('app_v2.palette.navigation'),
        label: t('app_v2.nav.dashboard'),
        icon: LayoutGrid,
        perform: () => router.visit(dashboard()),
    },
    {
        id: 'nav-flows',
        group: t('app_v2.palette.navigation'),
        label: t('app_v2.nav.flows'),
        icon: GitBranch,
        perform: () => router.visit(FlowController.globalIndex()),
    },
    {
        id: 'nav-chatbots',
        group: t('app_v2.palette.navigation'),
        label: t('app_v2.nav.chatbots'),
        icon: MessageSquare,
        perform: () => router.visit(ChatbotController.index()),
    },
    {
        id: 'nav-agents',
        group: t('app_v2.palette.navigation'),
        label: t('app_v2.nav.agents'),
        icon: Bot,
        perform: () => router.visit(AgentController.index()),
    },
    {
        id: 'nav-agent-teams',
        group: t('app_v2.palette.navigation'),
        label: t('app_v2.nav.agent_teams'),
        icon: Users,
        perform: () => router.visit(AgentTeamController.index()),
    },
    {
        id: 'nav-tools',
        group: t('app_v2.palette.capabilities'),
        label: t('app_v2.nav.tools'),
        icon: Wrench,
        perform: () => router.visit(ToolController.index()),
    },
    {
        id: 'nav-documents',
        group: t('app_v2.palette.capabilities'),
        label: t('app_v2.nav.documents'),
        icon: FileText,
        perform: () => router.visit(DocumentController.index()),
    },
    {
        id: 'nav-knowledge-bases',
        group: t('app_v2.palette.capabilities'),
        label: t('app_v2.nav.knowledge_base'),
        icon: Database,
        perform: () => router.visit(KnowledgeBaseController.index()),
    },
    {
        id: 'nav-ai-providers',
        group: t('app_v2.palette.system'),
        label: t('app_v2.nav.ai_providers'),
        icon: BrainCircuit,
        perform: () => router.visit(AiProviderController.index()),
    },
    {
        id: 'nav-cloud-providers',
        group: t('app_v2.palette.system'),
        label: t('app_v2.nav.cloud_providers'),
        icon: Cloud,
        perform: () => router.visit(CloudProviderController.index()),
    },
    {
        id: 'nav-integrations',
        group: t('app_v2.palette.system'),
        label: t('app_v2.nav.integrations'),
        icon: Plug,
        perform: () => router.visit(IntegrationController.index()),
    },
    {
        id: 'nav-whatsapp',
        group: t('app_v2.palette.system'),
        label: t('app_v2.nav.whatsapp'),
        icon: MessageCircle,
        perform: () => router.visit('/system/whatsapp'),
    },
]);

const groupedCommands = computed(() => {
    const all = [...navCommands.value, ...pageCommands.value];
    const groups = new Map<string, PaletteCommand[]>();
    for (const cmd of all) {
        if (!groups.has(cmd.group)) groups.set(cmd.group, []);
        groups.get(cmd.group)!.push(cmd);
    }
    return Array.from(groups.entries()).map(([group, items]) => ({
        group,
        items,
    }));
});

function run(cmd: PaletteCommand) {
    open.value = false;
    cmd.perform();
}

function onKeydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        open.value = !open.value;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <CommandDialog
        v-model:open="open"
        content-class="sp-admin-menu sp-admin-palette rounded-sp-sm"
    >
        <CommandInput :placeholder="t('app_v2.palette.search_placeholder')" />
        <CommandList>
            <CommandEmpty>{{ t('app_v2.palette.empty') }}</CommandEmpty>
            <template
                v-for="(group, idx) in groupedCommands"
                :key="group.group"
            >
                <CommandSeparator v-if="idx > 0" />
                <CommandGroup :heading="group.group">
                    <CommandItem
                        v-for="cmd in group.items"
                        :key="cmd.id"
                        :value="`${cmd.group}|${cmd.label}|${(cmd.keywords ?? []).join(' ')}`"
                        @select="run(cmd)"
                    >
                        <component
                            :is="cmd.icon"
                            v-if="cmd.icon"
                            class="mr-2 size-4 text-ink-muted"
                        />
                        {{ cmd.label }}
                    </CommandItem>
                </CommandGroup>
            </template>
        </CommandList>
    </CommandDialog>
</template>
