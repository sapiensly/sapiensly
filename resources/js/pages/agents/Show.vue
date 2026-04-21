<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { AgentTeam } from '@/types/agents';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bot,
    Brain,
    MessageSquare,
    Pencil,
    Trash2,
    Zap,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    team: AgentTeam;
}

const props = defineProps<Props>();

const statusTint: Record<string, string> = {
    active: 'var(--sp-success)',
    inactive: 'var(--sp-text-secondary)',
    draft: 'var(--sp-accent-blue)',
};

function tintFor(status: string) {
    return statusTint[status] ?? 'var(--sp-text-secondary)';
}

const agentTypeTint: Record<string, string> = {
    triage: 'var(--sp-accent-blue)',
    knowledge: 'var(--sp-spectrum-magenta)',
    action: 'var(--sp-warning)',
};

function tintForType(type: string) {
    return agentTypeTint[type] ?? 'var(--sp-accent-blue)';
}

function agentIcon(type: string): Component {
    switch (type) {
        case 'triage':
            return Bot;
        case 'knowledge':
            return Brain;
        case 'action':
            return Zap;
        default:
            return Bot;
    }
}

function deleteTeam() {
    router.delete(
        AgentTeamController.destroy({ agent_team: props.team.id }).url,
    );
}
</script>

<template>
    <Head :title="team.name" />

    <AppLayoutV2 :title="t('app_v2.nav.agent_teams')">
        <div class="mx-auto max-w-5xl space-y-6">
            <!-- Header: name + status + actions. -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 space-y-1">
                    <div class="flex items-center gap-3">
                        <h1 class="text-[22px] font-semibold leading-tight text-ink">
                            {{ team.name }}
                        </h1>
                        <span
                            class="inline-flex items-center rounded-pill border px-2.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                            :style="{
                                color: tintFor(team.status),
                                borderColor: `color-mix(in oklab, ${tintFor(team.status)} 45%, transparent)`,
                            }"
                        >
                            {{ team.status }}
                        </span>
                    </div>
                    <p v-if="team.description" class="text-xs text-ink-muted">
                        {{ team.description }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <Link
                        :href="AgentTeamController.chat({ agentTeam: team.id }).url"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <MessageSquare class="size-3.5" />
                            {{ t('agent_teams.show.test_team') }}
                        </button>
                    </Link>
                    <Link
                        :href="AgentTeamController.edit({ agent_team: team.id }).url"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        >
                            <Pencil class="size-3.5" />
                            {{ t('common.edit') }}
                        </button>
                    </Link>
                    <Dialog>
                        <DialogTrigger as-child>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20"
                            >
                                <Trash2 class="size-3.5" />
                                {{ t('common.delete') }}
                            </button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    {{ t('agent_teams.show.delete_team') }}
                                </DialogTitle>
                                <DialogDescription>
                                    {{ t('common.confirm_delete') }} "{{ team.name }}"?
                                    {{ t('common.action_irreversible') }}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                                    >
                                        {{ t('common.cancel') }}
                                    </button>
                                </DialogClose>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20"
                                    @click="deleteTeam"
                                >
                                    {{ t('common.delete') }}
                                </button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- Agents section. -->
            <section class="space-y-3">
                <div>
                    <h2
                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('agent_teams.show.agents') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-ink-muted">
                        {{ t('agent_teams.show.agents_description') }}
                    </p>
                </div>

                <div class="grid gap-3">
                    <div
                        v-for="agent in team.agents"
                        :key="agent.id"
                        class="rounded-sp-sm border border-soft bg-navy p-5"
                    >
                        <header class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex size-9 shrink-0 items-center justify-center rounded-xs"
                                    :style="{
                                        backgroundColor: `color-mix(in oklab, ${tintForType(agent.type)} 15%, transparent)`,
                                        color: tintForType(agent.type),
                                    }"
                                >
                                    <component
                                        :is="agentIcon(agent.type)"
                                        class="size-4"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-ink">
                                        {{ agent.name }}
                                    </h3>
                                    <p
                                        v-if="agent.description"
                                        class="mt-0.5 text-xs text-ink-muted"
                                    >
                                        {{ agent.description }}
                                    </p>
                                </div>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                :style="{
                                    color: tintFor(agent.status),
                                    borderColor: `color-mix(in oklab, ${tintFor(agent.status)} 45%, transparent)`,
                                }"
                            >
                                {{ agent.status }}
                            </span>
                        </header>

                        <dl class="mt-4 space-y-2 text-xs">
                            <div class="flex gap-2">
                                <dt class="text-ink-subtle">
                                    {{ t('agent_teams.show.type') }}:
                                </dt>
                                <dd class="capitalize text-ink">
                                    {{ agent.type }}
                                </dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-ink-subtle">
                                    {{ t('common.model') }}:
                                </dt>
                                <dd class="text-ink">{{ agent.model }}</dd>
                            </div>
                            <div v-if="agent.prompt_template" class="space-y-1">
                                <dt class="text-ink-subtle">Prompt Template:</dt>
                                <dd
                                    class="rounded-xs border border-soft bg-white/[0.03] p-3 font-mono text-[11px] whitespace-pre-wrap text-ink-muted"
                                >
                                    {{ agent.prompt_template }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>
        </div>
    </AppLayoutV2>
</template>
