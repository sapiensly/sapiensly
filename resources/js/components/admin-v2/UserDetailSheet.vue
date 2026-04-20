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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
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
import type { AdminUser } from '@/lib/admin/types';
import { router, useForm } from '@inertiajs/vue3';
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
    router.post(`/admin/users/${props.user.id}/impersonate`);
}

function blockOrUnblock() {
    if (!props.user) return;
    const action = props.user.status === 'blocked' ? 'unblock' : 'block';
    post(`/admin2/users/${props.user.id}/${action}`);
}

function resendVerification() {
    if (!props.user) return;
    post(`/admin2/users/${props.user.id}/resend-verification`);
}

function resetTwoFactor() {
    if (!props.user) return;
    post(`/admin2/users/${props.user.id}/reset-2fa`);
}

function submitDelete() {
    if (!props.user) return;
    deleteForm.delete(`/admin2/users/${props.user.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
            open.value = false;
        },
    });
}

const roleLabel = computed(() =>
    props.user ? t(`admin_v2.users.role.${props.user.role}`) : '',
);
const statusLabel = computed(() =>
    props.user ? t(`admin_v2.users.status.${props.user.status}`) : '',
);

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col border-soft bg-navy text-ink sm:max-w-md"
        >
            <template v-if="user">
                <SheetHeader class="border-b border-soft pb-4">
                    <SheetTitle class="text-ink">{{ user.name }}</SheetTitle>
                    <SheetDescription class="text-ink-muted">
                        {{ user.email }}
                    </SheetDescription>
                    <div class="flex flex-wrap gap-2 pt-2">
                        <Badge
                            variant="outline"
                            class="border-medium bg-white/5 font-normal text-ink-muted capitalize"
                        >
                            {{ roleLabel }}
                        </Badge>
                        <Badge
                            variant="outline"
                            class="border-medium bg-white/5 font-normal text-ink-muted capitalize"
                        >
                            {{ statusLabel }}
                        </Badge>
                        <Badge
                            v-if="user.twoFactorEnabled"
                            variant="outline"
                            class="border-sp-success/40 bg-sp-success/10 font-normal text-sp-success"
                        >
                            {{ t('admin_v2.users.detail.two_factor_on') }}
                        </Badge>
                    </div>
                </SheetHeader>

                <!-- Metadata grid -->
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 pt-4 text-xs">
                    <div>
                        <dt class="text-ink-subtle">
                            {{ t('admin_v2.users.detail.org') }}
                        </dt>
                        <dd class="mt-0.5 text-ink">
                            {{ user.org?.name ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-ink-subtle">
                            {{ t('admin_v2.users.detail.verified') }}
                        </dt>
                        <dd class="mt-0.5 text-ink">
                            {{ formatDate(user.emailVerifiedAt) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-ink-subtle">
                            {{ t('admin_v2.users.detail.last_seen') }}
                        </dt>
                        <dd class="mt-0.5 text-ink">
                            {{ formatDate(user.lastSeenAt) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-ink-subtle">
                            {{ t('admin_v2.users.detail.created') }}
                        </dt>
                        <dd class="mt-0.5 text-ink">
                            {{ formatDate(user.createdAt) }}
                        </dd>
                    </div>
                </dl>

                <Separator class="my-4 bg-soft" />

                <!-- Actions -->
                <section class="space-y-2">
                    <h3
                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('admin_v2.users.detail.actions') }}
                    </h3>

                    <Button
                        v-if="user.status === 'unverified' || !user.emailVerifiedAt"
                        variant="outline"
                        class="w-full justify-start gap-2 border-soft bg-white/5 text-ink"
                        @click="resendVerification"
                    >
                        <Mail class="size-4 text-ink-muted" />
                        {{ t('admin_v2.users.actions.resend_verification') }}
                    </Button>

                    <Button
                        variant="outline"
                        class="w-full justify-start gap-2 border-soft bg-white/5 text-ink"
                        @click="resetTwoFactor"
                    >
                        <Key class="size-4 text-ink-muted" />
                        {{ t('admin_v2.users.actions.reset_2fa') }}
                    </Button>

                    <Button
                        variant="outline"
                        class="w-full justify-start gap-2 border-soft bg-white/5 text-ink"
                        @click="impersonate"
                    >
                        <Plug class="size-4 text-ink-muted" />
                        {{ t('admin_v2.users.actions.impersonate') }}
                    </Button>

                    <Button
                        v-if="user.status === 'blocked'"
                        variant="outline"
                        class="w-full justify-start gap-2 border-soft bg-white/5 text-ink"
                        @click="blockOrUnblock"
                    >
                        <Check class="size-4 text-sp-success" />
                        {{ t('admin_v2.users.actions.unblock') }}
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        class="w-full justify-start gap-2 border-soft bg-white/5 text-ink"
                        @click="blockOrUnblock"
                    >
                        <Ban class="size-4 text-sp-warning" />
                        {{ t('admin_v2.users.actions.block') }}
                    </Button>
                </section>

                <Separator class="my-4 bg-soft" />

                <section class="space-y-2">
                    <h3
                        class="text-[10px] font-semibold tracking-wider text-sp-danger uppercase"
                    >
                        {{ t('admin_v2.users.detail.danger') }}
                    </h3>
                    <Button
                        variant="outline"
                        class="w-full justify-start gap-2 border-sp-danger/30 bg-sp-danger/5 text-sp-danger hover:bg-sp-danger/10"
                        @click="deleteOpen = true"
                    >
                        <Trash2 class="size-4" />
                        {{ t('admin_v2.users.actions.delete') }}
                    </Button>
                </section>

                <!-- Delete confirm -->
                <AlertDialog v-model:open="deleteOpen">
                    <AlertDialogContent
                        class="rounded-sp-sm border-sp-danger/30 bg-navy"
                    >
                        <AlertDialogHeader>
                            <AlertDialogTitle class="text-ink">
                                <Shield
                                    class="mr-1 inline size-4 text-sp-danger"
                                />
                                {{ t('admin_v2.users.delete.title') }}
                            </AlertDialogTitle>
                            <AlertDialogDescription class="text-ink-muted">
                                {{ t('admin_v2.users.delete.description') }}
                            </AlertDialogDescription>
                        </AlertDialogHeader>

                        <div class="space-y-2 py-2">
                            <p class="text-xs text-ink-muted">
                                {{ t('admin_v2.users.delete.plan') }}
                                <span class="text-ink">{{
                                    user.org
                                        ? t(
                                              'admin_v2.users.delete.plan_transfer',
                                              { org: user.org.name },
                                          )
                                        : t('admin_v2.users.delete.plan_cascade')
                                }}</span>
                            </p>
                            <Label for="delete-email-confirm" class="text-xs">
                                {{
                                    t('admin_v2.users.delete.confirm_label', {
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
                                {{ t('admin_v2.users.delete.submit') }}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </template>
        </SheetContent>
    </Sheet>
</template>
