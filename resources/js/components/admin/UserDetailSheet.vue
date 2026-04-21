<script setup lang="ts">
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Ban,
    Check,
    Key,
    Mail,
    Plug,
    Shield,
    Trash2,
} from '@/lib/admin/icons';
import * as ImpersonateController from '@/actions/App/Http/Controllers/Admin/ImpersonateController';
import { AlertCircle } from 'lucide-vue-next';
import type { AdminUser } from '@/lib/admin/types';
import { router, useForm } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    user: AdminUser | null;
}

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });

const { t } = useI18n();

const deleteOpen = ref(false);
const deleteForm = useForm({ email_confirmation: '' });

watch(
    () => props.user,
    () => {
        deleteOpen.value = false;
        deleteForm.reset();
        deleteForm.clearErrors();
    },
);

function post(url: string) {
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}

function impersonate() {
    if (!props.user) return;
    router.post(ImpersonateController.start(props.user.id).url);
}

function blockOrUnblock() {
    if (!props.user) return;
    const action = props.user.status === 'blocked' ? 'unblock' : 'block';
    post(`/admin/users/${props.user.id}/${action}`);
}

function resendVerification() {
    if (!props.user) return;
    post(`/admin/users/${props.user.id}/resend-verification`);
}

function resetTwoFactor() {
    if (!props.user) return;
    post(`/admin/users/${props.user.id}/reset-2fa`);
}

function submitDelete() {
    if (!props.user) return;
    deleteForm.delete(`/admin/users/${props.user.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
            open.value = false;
        },
    });
}

const roleLabel = computed(() =>
    props.user ? t(`admin.users.role.${props.user.role}`) : '',
);
const statusLabel = computed(() =>
    props.user ? t(`admin.users.status.${props.user.status}`) : '',
);

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

const avatarPalette = [
    'var(--sp-spectrum-magenta)',
    'var(--sp-spectrum-cyan)',
    'var(--sp-spectrum-indigo)',
    'var(--sp-warning)',
    'var(--sp-success)',
    'var(--sp-accent-blue)',
];

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((n) => n[0]?.toUpperCase() ?? '')
            .join('') || '·'
    );
}

function avatarTint(name: string): string {
    let h = 0;
    for (let i = 0; i < name.length; i++) {
        h = (h * 31 + name.charCodeAt(i)) | 0;
    }
    return avatarPalette[Math.abs(h) % avatarPalette.length];
}

function roleTint(role: AdminUser['role']): string {
    switch (role) {
        case 'owner':
            return 'var(--sp-spectrum-magenta)';
        case 'admin':
            return 'var(--sp-accent-cyan)';
        case 'sysadmin':
            return 'var(--sp-accent-blue)';
        case 'member':
        default:
            return 'var(--sp-spectrum-indigo)';
    }
}

interface StatusStyle {
    icon: Component;
    tint: string;
}

const statusStyles: Record<AdminUser['status'], StatusStyle> = {
    active: { icon: Check, tint: 'var(--sp-success)' },
    unverified: { icon: AlertCircle, tint: 'var(--sp-warning)' },
    blocked: { icon: AlertCircle, tint: 'var(--sp-danger)' },
};
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 border-l border-soft bg-navy p-0 text-ink sm:max-w-md"
        >
            <template v-if="user">
                <SheetHeader class="gap-3 border-b border-soft p-5">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-11 shrink-0 items-center justify-center rounded-pill text-[13px] font-semibold text-white"
                            :style="{ backgroundColor: avatarTint(user.name) }"
                        >
                            {{ initials(user.name) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <SheetTitle class="truncate text-base font-semibold text-ink">
                                {{ user.name }}
                            </SheetTitle>
                            <p class="truncate text-xs text-ink-muted">
                                {{ user.email }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <span
                            class="inline-flex items-center rounded-pill border px-2.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                            :style="{
                                color: roleTint(user.role),
                                borderColor: `color-mix(in oklab, ${roleTint(user.role)} 50%, transparent)`,
                            }"
                        >
                            {{ roleLabel }}
                        </span>
                        <span
                            class="inline-flex items-center gap-1 rounded-pill border px-2.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                            :style="{
                                color: statusStyles[user.status].tint,
                                borderColor: `color-mix(in oklab, ${statusStyles[user.status].tint} 45%, transparent)`,
                                backgroundColor: `color-mix(in oklab, ${statusStyles[user.status].tint} 10%, transparent)`,
                            }"
                        >
                            <component
                                :is="statusStyles[user.status].icon"
                                class="size-3"
                            />
                            {{ statusLabel }}
                        </span>
                        <span
                            v-if="user.twoFactorEnabled"
                            class="inline-flex items-center gap-1 rounded-pill border border-sp-success/45 bg-sp-success/10 px-2.5 py-0.5 text-[10px] font-semibold tracking-wider text-sp-success uppercase"
                        >
                            <Shield class="size-3" />
                            {{ t('admin.users.detail.two_factor_on') }}
                        </span>
                    </div>
                </SheetHeader>

                <div class="flex-1 overflow-y-auto px-5 py-5">
                    <!-- Metadata grid -->
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-4">
                        <div>
                            <dt
                                class="text-[10px] font-semibold tracking-[0.18em] text-ink-faint uppercase"
                            >
                                {{ t('admin.users.detail.org') }}
                            </dt>
                            <dd class="mt-1 text-[13px] text-ink">
                                {{ user.org?.name ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-[10px] font-semibold tracking-[0.18em] text-ink-faint uppercase"
                            >
                                {{ t('admin.users.detail.verified') }}
                            </dt>
                            <dd class="mt-1 text-[13px] text-ink">
                                {{ formatDate(user.emailVerifiedAt) }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-[10px] font-semibold tracking-[0.18em] text-ink-faint uppercase"
                            >
                                {{ t('admin.users.detail.last_seen') }}
                            </dt>
                            <dd class="mt-1 text-[13px] text-ink">
                                {{ formatDate(user.lastSeenAt) }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-[10px] font-semibold tracking-[0.18em] text-ink-faint uppercase"
                            >
                                {{ t('admin.users.detail.created') }}
                            </dt>
                            <dd class="mt-1 text-[13px] text-ink">
                                {{ formatDate(user.createdAt) }}
                            </dd>
                        </div>
                    </dl>

                    <!-- Actions -->
                    <section class="mt-6 space-y-2">
                        <h3
                            class="mb-2 text-[10px] font-semibold tracking-[0.18em] text-ink-faint uppercase"
                        >
                            {{ t('admin.users.detail.actions') }}
                        </h3>

                        <button
                            v-if="user.status === 'unverified' || !user.emailVerifiedAt"
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5 text-left text-sm text-ink transition-colors hover:bg-white/[0.04]"
                            @click="resendVerification"
                        >
                            <Mail class="size-4 shrink-0 text-ink-muted" />
                            {{ t('admin.users.actions.resend_verification') }}
                        </button>

                        <button
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5 text-left text-sm text-ink transition-colors hover:bg-white/[0.04]"
                            @click="resetTwoFactor"
                        >
                            <Key class="size-4 shrink-0 text-ink-muted" />
                            {{ t('admin.users.actions.reset_2fa') }}
                        </button>

                        <button
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5 text-left text-sm text-ink transition-colors hover:bg-white/[0.04]"
                            @click="impersonate"
                        >
                            <Plug class="size-4 shrink-0 text-ink-muted" />
                            {{ t('admin.users.actions.impersonate') }}
                        </button>

                        <button
                            v-if="user.status === 'blocked'"
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5 text-left text-sm text-ink transition-colors hover:bg-white/[0.04]"
                            @click="blockOrUnblock"
                        >
                            <Check class="size-4 shrink-0 text-sp-success" />
                            {{ t('admin.users.actions.unblock') }}
                        </button>
                        <button
                            v-else
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-xs border border-soft bg-white/[0.02] px-3 py-2.5 text-left text-sm text-ink transition-colors hover:bg-white/[0.04]"
                            @click="blockOrUnblock"
                        >
                            <Ban class="size-4 shrink-0 text-sp-warning" />
                            {{ t('admin.users.actions.block') }}
                        </button>
                    </section>

                    <section class="mt-6 space-y-2 border-t border-soft pt-5">
                        <h3
                            class="mb-2 text-[10px] font-semibold tracking-[0.18em] text-sp-danger uppercase"
                        >
                            {{ t('admin.users.detail.danger') }}
                        </h3>
                        <button
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-xs border border-sp-danger/30 bg-sp-danger/5 px-3 py-2.5 text-left text-sm text-sp-danger transition-colors hover:bg-sp-danger/10"
                            @click="deleteOpen = true"
                        >
                            <Trash2 class="size-4 shrink-0" />
                            {{ t('admin.users.actions.delete') }}
                        </button>
                    </section>
                </div>

                <!-- Delete confirm -->
                <AlertDialog v-model:open="deleteOpen">
                    <AlertDialogContent
                        class="sp-admin-dialog rounded-sp-sm border-sp-danger/30 bg-navy"
                    >
                        <AlertDialogHeader>
                            <AlertDialogTitle class="text-ink">
                                <Shield
                                    class="mr-1 inline size-4 text-sp-danger"
                                />
                                {{ t('admin.users.delete.title') }}
                            </AlertDialogTitle>
                            <AlertDialogDescription class="text-ink-muted">
                                {{ t('admin.users.delete.description') }}
                            </AlertDialogDescription>
                        </AlertDialogHeader>

                        <div class="space-y-2 py-2">
                            <p class="text-xs text-ink-muted">
                                {{ t('admin.users.delete.plan') }}
                                <span class="text-ink">{{
                                    user.org
                                        ? t(
                                              'admin.users.delete.plan_transfer',
                                              { org: user.org.name },
                                          )
                                        : t('admin.users.delete.plan_cascade')
                                }}</span>
                            </p>
                            <Label for="delete-email-confirm" class="text-xs">
                                {{
                                    t('admin.users.delete.confirm_label', {
                                        email: user.email,
                                    })
                                }}
                            </Label>
                            <Input
                                id="delete-email-confirm"
                                v-model="deleteForm.email_confirmation"
                                autocomplete="off"
                                :placeholder="user.email"
                            />
                            <p
                                v-if="deleteForm.errors.email_confirmation"
                                class="text-xs text-sp-danger"
                            >
                                {{ deleteForm.errors.email_confirmation }}
                            </p>
                        </div>

                        <AlertDialogFooter>
                            <AlertDialogCancel>
                                {{ t('common.cancel') }}
                            </AlertDialogCancel>
                            <AlertDialogAction
                                class="bg-sp-danger text-white hover:bg-sp-danger/90"
                                :disabled="
                                    deleteForm.processing ||
                                    deleteForm.email_confirmation !== user.email
                                "
                                @click="submitDelete"
                            >
                                {{ t('admin.users.delete.submit') }}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </template>
        </SheetContent>
    </Sheet>
</template>
