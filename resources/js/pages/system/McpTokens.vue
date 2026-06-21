<script setup lang="ts">
import McpTokenController from '@/actions/App/Http/Controllers/System/McpTokenController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ExternalLink,
    Globe,
    KeyRound,
    Plug,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface TokenRow {
    id: string;
    name: string;
    masked: string;
    abilities: string[] | null;
    created_by: string | null;
    last_used_at: string | null;
    created_at: string;
}

const props = defineProps<{
    tokens: TokenRow[];
    abilities: string[];
    serverUrl: string;
    justCreatedToken: string | null;
}>();

const { t } = useI18n();

const form = useForm<{ name: string; abilities: string[] }>({
    name: '',
    abilities: [],
});

function submit(): void {
    form.submit(McpTokenController.store(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function revoke(token: TokenRow): void {
    if (
        !window.confirm(t('system.mcp.revoke_confirm', { name: token.name }))
    ) {
        return;
    }
    router.delete(McpTokenController.destroy(token.id).url, {
        preserveScroll: true,
    });
}

const connectCommand = computed(
    () =>
        `claude mcp add --transport http sapiensly ${props.serverUrl} --header "Authorization: Bearer ${props.justCreatedToken}"`,
);

const copied = ref<string | null>(null);
function copy(value: string, key: string): void {
    navigator.clipboard?.writeText(value);
    copied.value = key;
    window.setTimeout(() => (copied.value = null), 1500);
}

function abilityLabel(ability: string): string {
    return t(`system.mcp.abilities.${ability.replace(':', '_')}`);
}

function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString()
        : t('system.mcp.never');
}
</script>

<template>
    <Head :title="t('system.mcp.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.mcp')">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader
                :title="t('system.mcp.title')"
                :description="t('system.mcp.page_description')"
            />

            <!-- Claude web connects over OAuth — no token, just the org URL. -->
            <SettingsCard
                :icon="Globe"
                :title="t('system.mcp.web_title')"
                :description="t('system.mcp.web_hint')"
                tint="var(--sp-spectrum-indigo)"
            >
                <div class="space-y-3">
                    <div class="space-y-1.5">
                        <Label>{{ t('system.mcp.server_url') }}</Label>
                        <div class="flex gap-2">
                            <Input
                                :model-value="serverUrl"
                                class="h-9 font-mono"
                                readonly
                            />
                            <button
                                type="button"
                                class="h-9 shrink-0 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                @click="copy(serverUrl, 'url')"
                            >
                                {{
                                    copied === 'url'
                                        ? t('system.mcp.copied')
                                        : t('system.mcp.copy')
                                }}
                            </button>
                        </div>
                    </div>

                    <p class="text-xs text-ink-muted">
                        {{ t('system.mcp.web_steps') }}
                    </p>

                    <a
                        href="https://claude.ai/settings/connectors"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex h-9 items-center gap-2 rounded-xs border border-soft px-3 text-[13px] font-medium text-ink transition-colors hover:bg-surface"
                    >
                        <ExternalLink class="size-4" />
                        {{ t('system.mcp.web_open_connectors') }}
                    </a>
                </div>
            </SettingsCard>

            <!-- One-time reveal of a freshly created token. -->
            <SettingsCard
                v-if="justCreatedToken"
                :icon="CheckCircle2"
                :title="t('system.mcp.created_title')"
                :description="t('system.mcp.created_hint')"
                tint="var(--sp-spectrum-cyan)"
            >
                <div class="space-y-3">
                    <div class="space-y-1.5">
                        <Label>{{ t('system.mcp.your_token') }}</Label>
                        <div class="flex gap-2">
                            <Input
                                :model-value="justCreatedToken"
                                class="h-9 min-w-0 flex-1 font-mono"
                                readonly
                            />
                            <button
                                type="button"
                                class="h-9 shrink-0 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                @click="copy(justCreatedToken, 'token')"
                            >
                                {{
                                    copied === 'token'
                                        ? t('system.mcp.copied')
                                        : t('system.mcp.copy')
                                }}
                            </button>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label>{{ t('system.mcp.connect_claude_code') }}</Label>
                        <div class="flex gap-2">
                            <code
                                class="flex h-auto min-w-0 flex-1 items-center overflow-x-auto rounded-xs border border-soft bg-navy px-3 py-2 font-mono text-[11px] whitespace-nowrap text-ink-muted"
                            >
                                {{ connectCommand }}
                            </code>
                            <button
                                type="button"
                                class="h-9 shrink-0 self-center rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                @click="copy(connectCommand, 'cmd')"
                            >
                                {{
                                    copied === 'cmd'
                                        ? t('system.mcp.copied')
                                        : t('system.mcp.copy')
                                }}
                            </button>
                        </div>
                    </div>
                </div>
            </SettingsCard>

            <!-- Create a token (Claude Code). -->
            <SettingsCard
                :icon="KeyRound"
                :title="t('system.mcp.title_create')"
                :description="t('system.mcp.description')"
            >
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-1.5">
                        <Label for="name">{{ t('system.mcp.name') }}</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            class="h-9"
                            :placeholder="t('system.mcp.name_placeholder')"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-2">
                        <Label>{{ t('system.mcp.abilities_label') }}</Label>
                        <p class="text-xs text-ink-muted">
                            {{ t('system.mcp.abilities_hint') }}
                        </p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="ability in abilities"
                                :key="ability"
                                class="flex cursor-pointer items-center gap-2 rounded-xs border border-soft px-3 py-2 text-[13px] text-ink-muted transition-colors hover:bg-surface"
                            >
                                <input
                                    v-model="form.abilities"
                                    type="checkbox"
                                    :value="ability"
                                    class="size-3.5 accent-[var(--sp-accent-blue)]"
                                />
                                <span class="font-mono text-ink">{{
                                    ability
                                }}</span>
                                <span class="truncate text-xs">{{
                                    abilityLabel(ability)
                                }}</span>
                            </label>
                        </div>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-9 items-center gap-2 rounded-xs bg-accent-blue px-4 text-[13px] font-medium text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        <Plug class="size-4" />
                        {{ t('system.mcp.create') }}
                    </button>
                </form>
            </SettingsCard>

            <!-- Existing tokens. -->
            <SettingsCard
                :icon="KeyRound"
                :title="t('system.mcp.existing_title')"
                :description="t('system.mcp.existing_hint')"
                tint="var(--sp-accent-cyan)"
            >
                <p
                    v-if="tokens.length === 0"
                    class="text-xs text-ink-muted"
                >
                    {{ t('system.mcp.empty') }}
                </p>

                <ul v-else class="divide-y divide-soft/60">
                    <li
                        v-for="token in tokens"
                        :key="token.id"
                        class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="truncate text-[13px] font-medium text-ink"
                                    >{{ token.name }}</span
                                >
                                <span class="font-mono text-xs text-ink-muted">{{
                                    token.masked
                                }}</span>
                            </div>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-ink-muted"
                            >
                                <span>{{
                                    token.abilities && token.abilities.length
                                        ? token.abilities.join(', ')
                                        : t('system.mcp.all_abilities')
                                }}</span>
                                <span v-if="token.created_by"
                                    >{{ t('system.mcp.created_by') }}:
                                    {{ token.created_by }}</span
                                >
                                <span
                                    >{{ t('system.mcp.last_used') }}:
                                    {{ formatDate(token.last_used_at) }}</span
                                >
                            </div>
                        </div>
                        <button
                            type="button"
                            class="inline-flex size-8 shrink-0 items-center justify-center rounded-xs border border-soft text-ink-muted transition-colors hover:border-accent-red/40 hover:bg-accent-red/10 hover:text-accent-red"
                            :title="t('system.mcp.revoke')"
                            @click="revoke(token)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </li>
                </ul>
            </SettingsCard>
        </div>
    </AppLayoutV2>
</template>
