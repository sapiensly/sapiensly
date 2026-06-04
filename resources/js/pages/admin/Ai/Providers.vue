<script setup lang="ts">
import AiTabs from '@/components/admin/AiTabs.vue';
import DriverChip from '@/components/admin/DriverChip.vue';
import OpenRouterModelsDialog from '@/components/admin/OpenRouterModelsDialog.vue';
import ProviderKeyDialog from '@/components/admin/ProviderKeyDialog.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/AdminLayout.vue';
import {
    Library,
    Loader2,
    Plug,
    Plus,
    RefreshCw,
    SlidersHorizontal,
} from '@/lib/admin/icons';
import type { AiProviderRow } from '@/lib/admin/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    providers: AiProviderRow[];
}

const props = defineProps<Props>();
const { t } = useI18n();

const directProviders = computed(() =>
    props.providers.filter((p) => p.kind === 'direct'),
);
const brokerProviders = computed(() =>
    props.providers.filter((p) => p.kind === 'broker'),
);

// ── Key dialog ────────────────────────────────────────────────────────────
const keyDialogOpen = ref(false);
const keyDialogMode = ref<'add' | 'rotate'>('add');
const keyTarget = ref<AiProviderRow | null>(null);

function openKeyDialog(provider: AiProviderRow) {
    keyTarget.value = provider;
    keyDialogMode.value = provider.configured ? 'rotate' : 'add';
    keyDialogOpen.value = true;
}

// ── OpenRouter models dialog ───────────────────────────────────────────────
const modelsDialogOpen = ref(false);

function openModelsDialog() {
    modelsDialogOpen.value = true;
}

// ── Direct provider live sync ───────────────────────────────────────────────
const syncing = reactive<Record<string, boolean>>({});

function syncModels(provider: AiProviderRow) {
    syncing[provider.driver] = true;
    router.post(
        '/admin/ai/providers/sync-models',
        { driver: provider.driver },
        {
            preserveScroll: true,
            only: ['providers', 'flash', 'errors'],
            onFinish: () => {
                syncing[provider.driver] = false;
            },
        },
    );
}

function formatRelative(iso: string | null): string {
    if (!iso) return '—';
    const then = new Date(iso).getTime();
    const hours = Math.round((Date.now() - then) / 1000 / 3600);
    if (hours < 1) return t('admin.ai.time.just_now');
    if (hours < 24) return `${hours}h`;
    return `${Math.round(hours / 24)}d`;
}
</script>

<template>
    <Head :title="t('admin.nav.ai')" />

    <AdminLayout :title="t('admin.nav.ai')">
        <div class="mx-auto max-w-5xl space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] leading-tight font-semibold text-ink">
                    {{ t('admin.ai.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin.ai.providers.description') }}
                </p>
            </header>

            <AiTabs current="providers" />

            <!-- Direct providers -->
            <SettingsCard
                :icon="Plug"
                :title="t('admin.ai.providers.direct_title')"
                :description="t('admin.ai.providers.direct_description')"
            >
                <div
                    v-for="p in directProviders"
                    :key="p.driver"
                    class="flex items-center gap-3 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5"
                >
                    <DriverChip :driver="p.driver" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-ink">{{ p.label }}</p>
                        <p
                            v-if="p.configured"
                            class="font-mono text-xs text-ink-muted"
                        >
                            {{ p.masked }}
                            <span class="text-ink-subtle">
                                ·
                                {{
                                    t('admin.ai.providers.models_enabled_count', {
                                        count: p.modelCount,
                                    })
                                }}
                            </span>
                        </p>
                        <p v-else class="text-xs text-ink-subtle">
                            {{ t('admin.ai.providers.not_configured') }}
                        </p>
                    </div>
                    <div
                        v-if="p.configured"
                        class="text-right text-[10px] text-ink-subtle"
                    >
                        <p>
                            {{ t('admin.ai.providers.rotated_prefix') }}
                            {{ formatRelative(p.lastRotatedAt) }}
                        </p>
                    </div>
                    <Button
                        v-if="p.configured && p.syncable"
                        variant="outline"
                        size="sm"
                        class="gap-1 border-medium bg-surface text-xs"
                        :disabled="syncing[p.driver]"
                        @click="syncModels(p)"
                    >
                        <component
                            :is="syncing[p.driver] ? Loader2 : RefreshCw"
                            class="size-3"
                            :class="syncing[p.driver] ? 'animate-spin' : ''"
                        />
                        {{ t('admin.ai.providers.sync_cta') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="gap-1 border-medium bg-surface text-xs"
                        @click="openKeyDialog(p)"
                    >
                        <component
                            :is="p.configured ? RefreshCw : Plus"
                            class="size-3"
                        />
                        {{
                            p.configured
                                ? t('admin.ai.providers.rotate_cta')
                                : t('admin.ai.providers.add_cta')
                        }}
                    </Button>
                </div>
            </SettingsCard>

            <!-- Brokers -->
            <SettingsCard
                :icon="Library"
                :title="t('admin.ai.providers.broker_title')"
                :description="t('admin.ai.providers.broker_description')"
                tint="var(--sp-spectrum-magenta)"
            >
                <div
                    v-for="p in brokerProviders"
                    :key="p.driver"
                    class="flex items-center gap-3 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5"
                >
                    <DriverChip :driver="p.driver" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-ink">{{ p.label }}</p>
                        <p
                            v-if="p.configured"
                            class="font-mono text-xs text-ink-muted"
                        >
                            {{ p.masked }}
                            <span class="text-ink-subtle">
                                ·
                                {{
                                    t('admin.ai.providers.models_enabled_count', {
                                        count: p.modelCount,
                                    })
                                }}
                            </span>
                        </p>
                        <p v-else class="text-xs text-ink-subtle">
                            {{ t('admin.ai.providers.not_configured') }}
                        </p>
                    </div>
                    <div
                        v-if="p.configured"
                        class="text-right text-[10px] text-ink-subtle"
                    >
                        <p>
                            {{ t('admin.ai.providers.rotated_prefix') }}
                            {{ formatRelative(p.lastRotatedAt) }}
                        </p>
                    </div>
                    <Button
                        v-if="p.configured && p.driver === 'openrouter'"
                        variant="outline"
                        size="sm"
                        class="gap-1 border-medium bg-surface text-xs"
                        @click="openModelsDialog"
                    >
                        <SlidersHorizontal class="size-3" />
                        {{ t('admin.ai.providers.models_cta') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="gap-1 border-medium bg-surface text-xs"
                        @click="openKeyDialog(p)"
                    >
                        <component
                            :is="p.configured ? RefreshCw : Plus"
                            class="size-3"
                        />
                        {{
                            p.configured
                                ? t('admin.ai.providers.rotate_cta')
                                : t('admin.ai.providers.add_cta')
                        }}
                    </Button>
                </div>
            </SettingsCard>
        </div>

        <ProviderKeyDialog
            v-model:open="keyDialogOpen"
            :driver="keyTarget?.driver ?? null"
            :label="keyTarget?.label ?? null"
            :credential-fields="keyTarget?.credentialFields ?? ['api_key']"
            :mode="keyDialogMode"
        />

        <OpenRouterModelsDialog v-model:open="modelsDialogOpen" />
    </AdminLayout>
</template>
