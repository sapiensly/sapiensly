<script setup lang="ts">
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Check, Key, Plug, Trash2 } from '@/lib/admin/icons';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface TokenRow {
    id: string;
    name: string;
    masked: string;
    abilities: string[];
    isPlatform: boolean;
    owner: string | null;
    organization: string | null;
    organizationSlug: string | null;
    lastUsedAt: string | null;
    createdAt: string | null;
}

const props = defineProps<{
    organizations: { id: string; name: string; slug: string }[];
    abilities: string[];
    platformAbility: string;
    tokens: TokenRow[];
    justCreatedToken: string | null;
    justCreatedUrl: string | null;
    justCreatedServerName: string | null;
}>();

const { t } = useI18n();

const form = useForm<{
    name: string;
    organization_id: string;
    abilities: string[];
}>({
    name: '',
    organization_id: props.organizations[0]?.id ?? '',
    // The reason to be on this screen — pre-selected, still a visible choice.
    abilities: [props.platformAbility],
});

function submit(): void {
    form.post('/admin/mcp', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('name');
            form.abilities = [props.platformAbility];
        },
    });
}

function revoke(token: TokenRow): void {
    router.delete(`/admin/mcp/${token.id}`, { preserveScroll: true });
}

// The server name comes from the server: a platform credential is added under
// its own name so it never lands on top of an existing tenant connection.
const connectCommand = computed(
    () =>
        `claude mcp add --transport http ${props.justCreatedServerName ?? 'sapiensly-sysadmin'} ${props.justCreatedUrl} --header "Authorization: Bearer ${props.justCreatedToken}"`,
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
        ? new Date(value).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : t('system.mcp.never');
}
</script>

<template>
    <Head :title="t('admin.nav.mcp')" />

    <AdminLayout :title="t('admin.nav.mcp')">
        <div class="mx-auto max-w-5xl space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] leading-tight font-semibold text-ink">
                    {{ t('admin.mcp.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin.mcp.description') }}
                </p>
            </header>

            <!-- The token, shown exactly once. -->
            <SettingsCard
                v-if="justCreatedToken"
                :icon="Check"
                :title="t('system.mcp.created_title')"
                :description="t('system.mcp.created_hint')"
                tint="var(--sp-success)"
            >
                <div class="space-y-3">
                    <div>
                        <Label class="text-xs text-ink-muted">
                            {{ t('system.mcp.your_token') }}
                        </Label>
                        <div class="mt-1 flex items-center gap-2">
                            <code
                                class="min-w-0 flex-1 truncate rounded-xs border border-soft bg-surface px-3 py-2 font-mono text-xs text-ink"
                            >
                                {{ justCreatedToken }}
                            </code>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="copy(justCreatedToken, 'token')"
                            >
                                <Copy class="size-3.5" />
                                {{
                                    copied === 'token'
                                        ? t('system.mcp.copied')
                                        : t('system.mcp.copy')
                                }}
                            </Button>
                        </div>
                    </div>
                    <div>
                        <Label class="text-xs text-ink-muted">
                            {{ t('system.mcp.connect_claude_code') }}
                        </Label>
                        <div class="mt-1 flex items-center gap-2">
                            <code
                                class="min-w-0 flex-1 truncate rounded-xs border border-soft bg-surface px-3 py-2 font-mono text-xs text-ink"
                            >
                                {{ connectCommand }}
                            </code>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="copy(connectCommand, 'command')"
                            >
                                <Copy class="size-3.5" />
                                {{
                                    copied === 'command'
                                        ? t('system.mcp.copied')
                                        : t('system.mcp.copy')
                                }}
                            </Button>
                        </div>
                    </div>
                </div>
            </SettingsCard>

            <!-- Issue a token. -->
            <SettingsCard
                :icon="Key"
                :title="t('admin.mcp.create.title')"
                :description="t('admin.mcp.create.description')"
            >
                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <Label for="token-name">
                            {{ t('system.mcp.name') }}
                        </Label>
                        <Input
                            id="token-name"
                            v-model="form.name"
                            class="mt-1"
                            :placeholder="t('system.mcp.name_placeholder')"
                        />
                        <p
                            v-if="form.errors.name"
                            class="mt-1 text-xs text-sp-danger"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div>
                        <Label for="token-org">
                            {{ t('admin.mcp.create.organization_label') }}
                        </Label>
                        <p class="text-xs text-ink-muted">
                            {{ t('admin.mcp.create.organization_hint') }}
                        </p>
                        <select
                            id="token-org"
                            v-model="form.organization_id"
                            class="mt-1 h-9 w-full rounded-xs border border-soft bg-surface px-3 text-[13px] text-ink"
                        >
                            <option
                                v-for="org in organizations"
                                :key="org.id"
                                :value="org.id"
                            >
                                {{ org.name }} — /mcp/{{ org.slug }}/v1
                            </option>
                        </select>
                        <p
                            v-if="organizations.length === 0"
                            class="mt-1 text-xs text-sp-warning"
                        >
                            {{ t('admin.mcp.create.no_organizations') }}
                        </p>
                        <p
                            v-if="form.errors.organization_id"
                            class="mt-1 text-xs text-sp-danger"
                        >
                            {{ form.errors.organization_id }}
                        </p>
                    </div>

                    <div>
                        <Label>{{ t('system.mcp.abilities_label') }}</Label>
                        <p class="text-xs text-ink-muted">
                            {{ t('admin.mcp.create.abilities_hint') }}
                        </p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="ability in abilities"
                                :key="ability"
                                :class="[
                                    'flex cursor-pointer items-center gap-2 rounded-xs border px-3 py-2 text-[13px] transition-colors',
                                    ability === platformAbility
                                        ? 'border-accent-blue/40 bg-accent-blue/5 text-ink'
                                        : 'border-soft text-ink-muted hover:bg-surface',
                                ]"
                            >
                                <input
                                    v-model="form.abilities"
                                    type="checkbox"
                                    :value="ability"
                                    class="size-3.5 accent-[var(--sp-accent-blue)]"
                                />
                                <span class="font-mono text-ink">
                                    {{ ability }}
                                </span>
                                <span class="truncate text-xs">
                                    {{ abilityLabel(ability) }}
                                </span>
                            </label>
                        </div>
                        <p
                            v-if="form.errors.abilities"
                            class="mt-1 text-xs text-sp-danger"
                        >
                            {{ form.errors.abilities }}
                        </p>
                    </div>

                    <Button
                        type="submit"
                        :disabled="
                            form.processing || organizations.length === 0
                        "
                    >
                        <Key class="size-3.5" />
                        {{ t('system.mcp.create') }}
                    </Button>
                </form>
            </SettingsCard>

            <!-- Every token on the platform. -->
            <SettingsCard
                :icon="Plug"
                :title="t('admin.mcp.existing.title')"
                :description="t('admin.mcp.existing.description')"
            >
                <p
                    v-if="tokens.length === 0"
                    class="py-4 text-sm text-ink-muted"
                >
                    {{ t('admin.mcp.existing.empty') }}
                </p>
                <div v-else class="divide-y divide-soft">
                    <div
                        v-for="token in tokens"
                        :key="token.id"
                        class="flex items-start gap-3 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="flex items-center gap-2 text-sm font-medium text-ink"
                            >
                                {{ token.name }}
                                <span
                                    v-if="token.isPlatform"
                                    class="rounded-pill bg-accent-blue/15 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-accent-blue uppercase"
                                >
                                    {{ t('admin.mcp.existing.platform_badge') }}
                                </span>
                            </p>
                            <p class="truncate text-xs text-ink-muted">
                                <span class="font-mono">{{
                                    token.masked
                                }}</span>
                                ·
                                {{ token.owner ?? '—' }}
                                ·
                                {{ token.organization ?? '—' }}
                            </p>
                            <p class="truncate text-xs text-ink-subtle">
                                {{
                                    token.abilities.length
                                        ? token.abilities.join(', ')
                                        : t('system.mcp.all_abilities')
                                }}
                                ·
                                {{ t('system.mcp.last_used') }}
                                {{ formatDate(token.lastUsedAt) }}
                            </p>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="shrink-0 text-ink-muted hover:text-sp-danger"
                            @click="revoke(token)"
                        >
                            <Trash2 class="size-3.5" />
                            {{ t('system.mcp.revoke') }}
                        </Button>
                    </div>
                </div>
            </SettingsCard>
        </div>
    </AdminLayout>
</template>
