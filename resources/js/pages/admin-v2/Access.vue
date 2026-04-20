<script setup lang="ts">
import ChipsInput from '@/components/admin-v2/ChipsInput.vue';
import PostureRow from '@/components/admin-v2/PostureRow.vue';
import SettingsCard from '@/components/admin-v2/SettingsCard.vue';
import ToggleRow from '@/components/admin-v2/ToggleRow.vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminV2Layout from '@/layouts/AdminV2Layout.vue';
import {
    Key,
    NavAccess as ShieldIcon,
    Plug,
    Shield,
    Zap,
} from '@/lib/admin/icons';
import type { AccessProps } from '@/lib/admin/types';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<AccessProps>();

const { t } = useI18n();

// Local optimistic copies — reset back to props on server error.
const form = ref({ ...props.settings });

function sendPatch(payload: Record<string, unknown>, rollback?: () => void) {
    router.patch('/admin2/access', payload, {
        preserveScroll: true,
        preserveState: true,
        only: ['settings', 'posture'],
        onSuccess: () => {
            // Server is source of truth — reconcile local with what came back.
            form.value = { ...props.settings };
        },
        onError: () => {
            if (rollback) rollback();
        },
    });
}

// ── Toggles ─────────────────────────────────────────────────────────────
function toggleRegistration(next: boolean) {
    const prev = form.value.registrationOpen;
    form.value.registrationOpen = next;
    sendPatch({ registrationOpen: next }, () => {
        form.value.registrationOpen = prev;
    });
}

function toggleEmailVerification(next: boolean) {
    const prev = form.value.emailVerificationRequired;
    form.value.emailVerificationRequired = next;
    sendPatch({ emailVerificationRequired: next }, () => {
        form.value.emailVerificationRequired = prev;
    });
}

// 2FA required — opens a warning modal listing affected users when flipped on.
const twoFactorWarningOpen = ref(false);
const twoFactorWarningLoading = ref(false);
const usersWithoutTwoFactor = ref<
    { id: number; name: string; email: string }[]
>([]);
const usersWithoutTwoFactorCount = ref(0);

async function toggleTwoFactor(next: boolean) {
    if (next && !form.value.twoFactorRequired) {
        twoFactorWarningLoading.value = true;
        twoFactorWarningOpen.value = true;
        try {
            const response = await fetch('/admin2/access/users-without-2fa', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = await response.json();
            usersWithoutTwoFactor.value = json.users ?? [];
            usersWithoutTwoFactorCount.value = json.count ?? 0;
        } finally {
            twoFactorWarningLoading.value = false;
        }
        return;
    }

    form.value.twoFactorRequired = next;
    sendPatch({ twoFactorRequired: next }, () => {
        form.value.twoFactorRequired = !next;
    });
}

function confirmTwoFactor() {
    form.value.twoFactorRequired = true;
    sendPatch({ twoFactorRequired: true }, () => {
        form.value.twoFactorRequired = false;
    });
    twoFactorWarningOpen.value = false;
}

function cancelTwoFactor() {
    twoFactorWarningOpen.value = false;
}

// ── Chips ───────────────────────────────────────────────────────────────
const DOMAIN_REGEX = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i;

function updateDomainAllowlist(next: string[]) {
    const prev = form.value.domainAllowlist;
    form.value.domainAllowlist = next;
    sendPatch({ domainAllowlist: next }, () => {
        form.value.domainAllowlist = prev;
    });
}

function updateIpAllowlist(next: string[]) {
    const prev = form.value.ipAllowlist;
    form.value.ipAllowlist = next;
    sendPatch({ ipAllowlist: next }, () => {
        form.value.ipAllowlist = prev;
    });
}

function toggleIpAllowlistEnabled(next: boolean) {
    const prev = form.value.ipAllowlistEnabled;
    form.value.ipAllowlistEnabled = next;
    sendPatch({ ipAllowlistEnabled: next }, () => {
        form.value.ipAllowlistEnabled = prev;
    });
}

// ── Inputs ──────────────────────────────────────────────────────────────
const sessionLifetimeDraft = ref(form.value.sessionLifetimeMinutes);
function commitSessionLifetime() {
    const raw = Number(sessionLifetimeDraft.value);
    if (!Number.isFinite(raw)) {
        sessionLifetimeDraft.value = form.value.sessionLifetimeMinutes;
        return;
    }
    const clamped = Math.min(10080, Math.max(15, Math.round(raw)));
    sessionLifetimeDraft.value = clamped;
    if (clamped === form.value.sessionLifetimeMinutes) return;
    const prev = form.value.sessionLifetimeMinutes;
    form.value.sessionLifetimeMinutes = clamped;
    sendPatch({ sessionLifetimeMinutes: clamped }, () => {
        form.value.sessionLifetimeMinutes = prev;
        sessionLifetimeDraft.value = prev;
    });
}
</script>

<template>
    <Head :title="t('admin_v2.nav.access')" />

    <AdminV2Layout :title="t('admin_v2.nav.access')">
        <div class="mx-auto max-w-5xl space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] font-semibold leading-tight text-ink">
                    {{ t('admin_v2.access.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin_v2.access.description') }}
                </p>
            </header>

            <!-- Registration + verification -->
            <SettingsCard
                :icon="Plug"
                :title="t('admin_v2.access.registration.title')"
                :description="t('admin_v2.access.registration.description')"
            >
                <ToggleRow
                    :model-value="form.registrationOpen"
                    :label="t('admin_v2.access.registration.open_label')"
                    :description="t('admin_v2.access.registration.open_description')"
                    @update:model-value="toggleRegistration"
                />
                <ToggleRow
                    :model-value="form.emailVerificationRequired"
                    :label="t('admin_v2.access.registration.verify_label')"
                    :description="t('admin_v2.access.registration.verify_description')"
                    @update:model-value="toggleEmailVerification"
                />
            </SettingsCard>

            <!-- Authentication hardening -->
            <SettingsCard
                :icon="Key"
                :title="t('admin_v2.access.auth.title')"
                :description="t('admin_v2.access.auth.description')"
                tint="var(--sp-accent-cyan)"
            >
                <ToggleRow
                    :model-value="form.twoFactorRequired"
                    :label="t('admin_v2.access.auth.two_factor_label')"
                    :description="t('admin_v2.access.auth.two_factor_description')"
                    @update:model-value="toggleTwoFactor"
                />

                <div class="space-y-1.5">
                    <Label
                        for="session-lifetime"
                        class="text-xs text-ink-muted"
                    >
                        {{ t('admin_v2.access.auth.session_lifetime_label') }}
                    </Label>
                    <Input
                        id="session-lifetime"
                        v-model="sessionLifetimeDraft"
                        type="number"
                        min="15"
                        max="10080"
                        class="h-9 w-36 border-medium bg-white/5 text-sm"
                        @blur="commitSessionLifetime"
                        @keyup.enter="commitSessionLifetime"
                    />
                    <p class="text-xs text-ink-subtle">
                        {{ t('admin_v2.access.auth.session_lifetime_hint') }}
                    </p>
                </div>
            </SettingsCard>

            <!-- Domain allowlist -->
            <SettingsCard
                :icon="ShieldIcon"
                :title="t('admin_v2.access.domains.title')"
                :description="t('admin_v2.access.domains.description')"
            >
                <ChipsInput
                    :model-value="form.domainAllowlist"
                    :placeholder="t('admin_v2.access.domains.placeholder')"
                    :validate-pattern="DOMAIN_REGEX"
                    :lowercase="true"
                    @update:model-value="updateDomainAllowlist"
                />
                <p class="text-xs text-ink-subtle">
                    {{
                        form.domainAllowlist.length
                            ? t('admin_v2.access.domains.gated')
                            : t('admin_v2.access.domains.open')
                    }}
                </p>
            </SettingsCard>

            <!-- IP allowlist -->
            <SettingsCard
                :icon="Zap"
                :title="t('admin_v2.access.ips.title')"
                :description="t('admin_v2.access.ips.description')"
            >
                <ToggleRow
                    :model-value="form.ipAllowlistEnabled"
                    :label="t('admin_v2.access.ips.enable_label')"
                    :description="t('admin_v2.access.ips.enable_description')"
                    @update:model-value="toggleIpAllowlistEnabled"
                />
                <ChipsInput
                    v-if="form.ipAllowlistEnabled"
                    :model-value="form.ipAllowlist"
                    :placeholder="t('admin_v2.access.ips.placeholder')"
                    @update:model-value="updateIpAllowlist"
                />
            </SettingsCard>

            <!-- Posture -->
            <SettingsCard
                :icon="Shield"
                :title="t('admin_v2.access.posture.title')"
                :description="t('admin_v2.access.posture.description')"
            >
                <PostureRow
                    v-for="item in posture"
                    :key="item.id"
                    :ok="item.ok"
                    :label="item.label"
                    :hint="item.hint"
                    :fix-route="item.fixRoute"
                />
            </SettingsCard>
        </div>

        <!-- Warning modal for 2FA-required toggle -->
        <AlertDialog v-model:open="twoFactorWarningOpen">
            <AlertDialogContent class="rounded-sp-sm border-sp-warning/30 bg-navy">
                <AlertDialogHeader>
                    <AlertDialogTitle class="text-ink">
                        {{ t('admin_v2.access.auth.two_factor_warning_title') }}
                    </AlertDialogTitle>
                    <AlertDialogDescription class="text-ink-muted">
                        {{
                            t('admin_v2.access.auth.two_factor_warning_body', {
                                count: usersWithoutTwoFactorCount,
                            })
                        }}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <div
                    v-if="!twoFactorWarningLoading"
                    class="max-h-64 space-y-1 overflow-auto rounded-xs border border-soft bg-white/[0.02] p-2"
                >
                    <p
                        v-if="usersWithoutTwoFactor.length === 0"
                        class="py-3 text-center text-xs text-ink-muted"
                    >
                        {{ t('admin_v2.access.auth.two_factor_warning_empty') }}
                    </p>
                    <div
                        v-for="user in usersWithoutTwoFactor"
                        :key="user.id"
                        class="flex items-center justify-between px-2 py-1 text-xs"
                    >
                        <span class="text-ink">{{ user.name }}</span>
                        <span class="text-ink-muted">{{ user.email }}</span>
                    </div>
                </div>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="cancelTwoFactor">
                        {{ t('common.cancel') }}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        class="bg-sp-warning text-white hover:bg-sp-warning/90"
                        @click="confirmTwoFactor"
                    >
                        {{ t('admin_v2.access.auth.two_factor_warning_confirm') }}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AdminV2Layout>
</template>
