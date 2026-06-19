<script setup lang="ts">
import McpTokenController from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, KeyRound, Plug, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface TokenRow {
    id: string;
    name: string;
    masked: string;
    abilities: string[] | null;
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
        !window.confirm(t('settings.mcp.revoke_confirm', { name: token.name }))
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
    return t(`settings.mcp.abilities.${ability.replace(':', '_')}`);
}

function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString()
        : t('settings.mcp.never');
}
</script>

<template>
    <Head :title="t('settings.mcp.breadcrumb')" />

    <SettingsLayout>
        <div class="space-y-4">
            <!-- One-time reveal of a freshly created token. -->
            <SettingsCard
                v-if="justCreatedToken"
                :icon="CheckCircle2"
                :title="t('settings.mcp.created_title')"
                :description="t('settings.mcp.created_hint')"
                tint="var(--sp-accent-green)"
            >
                <div class="space-y-3">
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.mcp.your_token') }}</Label>
                        <div class="flex gap-2">
                            <Input
                                :model-value="justCreatedToken"
                                class="h-9 font-mono"
                                readonly
                            />
                            <button
                                type="button"
                                class="h-9 shrink-0 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                                @click="copy(justCreatedToken, 'token')"
                            >
                                {{
                                    copied === 'token'
                                        ? t('settings.mcp.copied')
                                        : t('settings.mcp.copy')
                                }}
                            </button>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label>{{
                            t('settings.mcp.connect_claude_code')
                        }}</Label>
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
                                        ? t('settings.mcp.copied')
                                        : t('settings.mcp.copy')
                                }}
                            </button>
                        </div>
                    </div>
                </div>
            </SettingsCard>

            <!-- Create a new token. -->
            <SettingsCard
                :icon="KeyRound"
                :title="t('settings.mcp.title')"
                :description="t('settings.mcp.description')"
            >
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-1.5">
                        <Label for="name">{{ t('settings.mcp.name') }}</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            class="h-9"
                            :placeholder="t('settings.mcp.name_placeholder')"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-2">
                        <Label>{{ t('settings.mcp.abilities_label') }}</Label>
                        <p class="text-xs text-ink-muted">
                            {{ t('settings.mcp.abilities_hint') }}
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
                        {{ t('settings.mcp.create') }}
                    </button>
                </form>
            </SettingsCard>

            <!-- Existing tokens. -->
            <SettingsCard
                :icon="KeyRound"
                :title="t('settings.mcp.existing_title')"
                :description="t('settings.mcp.existing_hint')"
                tint="var(--sp-accent-cyan)"
            >
                <p v-if="tokens.length === 0" class="text-xs text-ink-muted">
                    {{ t('settings.mcp.empty') }}
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
                                <span
                                    class="font-mono text-xs text-ink-muted"
                                    >{{ token.masked }}</span
                                >
                            </div>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-ink-muted"
                            >
                                <span>{{
                                    token.abilities && token.abilities.length
                                        ? token.abilities.join(', ')
                                        : t('settings.mcp.all_abilities')
                                }}</span>
                                <span
                                    >{{ t('settings.mcp.last_used') }}:
                                    {{ formatDate(token.last_used_at) }}</span
                                >
                            </div>
                        </div>
                        <button
                            type="button"
                            class="hover:border-accent-red/40 hover:bg-accent-red/10 hover:text-accent-red inline-flex size-8 shrink-0 items-center justify-center rounded-xs border border-soft text-ink-muted transition-colors"
                            :title="t('settings.mcp.revoke')"
                            @click="revoke(token)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </li>
                </ul>
            </SettingsCard>
        </div>
    </SettingsLayout>
</template>
