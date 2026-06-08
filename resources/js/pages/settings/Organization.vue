<script setup lang="ts">
import OrganizationController from '@/actions/App/Http/Controllers/Settings/OrganizationController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { Organization, User } from '@/types';
import { Form, Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Building2, Mail, Trash2, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Member {
    id: string;
    user: User;
    role: string;
    status: string;
}

interface Props {
    organization: Organization;
    members: Member[];
    isAdmin: boolean;
    isOwner: boolean;
}

const props = defineProps<Props>();

const inviteForm = useForm({
    email: '',
});

const { getInitials } = useInitials();

const deleteConfirmation = ref('');
const canDelete = computed(() => deleteConfirmation.value === props.organization.name);

const submitInvite = () => {
    inviteForm.post('/settings/organization/invite', {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset();
        },
    });
};
</script>

<template>
    <Head :title="t('settings.organization.breadcrumb')" />

    <SettingsLayout>
        <div class="space-y-4">
            <!-- Organization identity. -->
            <SettingsCard
                :icon="Building2"
                :title="t('settings.organization.title')"
                :description="t('settings.organization.description')"
            >
                <div class="space-y-1">
                    <Label>{{ t('settings.organization.name') }}</Label>
                    <p class="text-sm text-ink">{{ organization.name }}</p>
                </div>
                <div v-if="organization.slug" class="space-y-1">
                    <Label>{{ t('settings.organization.slug') }}</Label>
                    <p class="font-mono text-xs text-ink-subtle">
                        {{ organization.slug }}
                    </p>
                </div>
            </SettingsCard>

            <!-- Members. -->
            <SettingsCard
                :icon="Users"
                :title="t('settings.organization.members')"
                :description="`${members.length} member${members.length !== 1 ? 's' : ''}`"
                tint="var(--sp-spectrum-magenta)"
            >
                <div class="space-y-1.5">
                    <div
                        v-for="member in members"
                        :key="member.id"
                        class="flex items-center justify-between gap-3 rounded-xs border border-soft bg-white/[0.03] p-3"
                    >
                        <div class="flex items-center gap-3">
                            <Avatar class="size-8">
                                <AvatarFallback
                                    class="bg-accent-blue/15 text-[11px] font-semibold text-accent-blue"
                                >
                                    {{ getInitials(member.user.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">
                                    {{ member.user.name }}
                                </p>
                                <p class="truncate text-[11px] text-ink-subtle">
                                    {{ member.user.email }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="inline-flex items-center rounded-pill border border-medium bg-surface px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                        >
                            {{ member.role }}
                        </span>
                    </div>
                </div>
            </SettingsCard>

            <!-- Invite. -->
            <SettingsCard
                v-if="isAdmin"
                :icon="Mail"
                :title="t('settings.organization.invite_title')"
                :description="t('settings.organization.invite_description')"
                tint="var(--sp-accent-cyan)"
            >
                <form class="flex items-end gap-3" @submit.prevent="submitInvite">
                    <div class="flex-1 space-y-1.5">
                        <Label for="invite-email">
                            {{ t('common.email_address') }}
                        </Label>
                        <Input
                            id="invite-email"
                            v-model="inviteForm.email"
                            type="email"
                            :placeholder="t('settings.organization.invite_placeholder')"
                            class="h-9"
                        />
                        <InputError :message="inviteForm.errors.email" />
                    </div>
                    <button
                        type="submit"
                        :disabled="inviteForm.processing"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('settings.organization.send_invitation') }}
                    </button>
                </form>
            </SettingsCard>

            <!-- Danger zone — delete organization (owner only). -->
            <SettingsCard
                v-if="isOwner"
                :icon="AlertTriangle"
                :title="t('settings.organization.delete.title')"
                :description="t('settings.organization.delete.description')"
                tint="var(--sp-danger)"
            >
                <div
                    class="flex items-start gap-2 rounded-xs border border-sp-danger/30 bg-sp-danger/10 p-3"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0 text-sp-danger" />
                    <div class="space-y-0.5">
                        <p class="text-sm font-medium text-sp-danger">
                            {{ t('settings.organization.delete.warning') }}
                        </p>
                        <p class="text-[11px] text-sp-danger/80">
                            {{ t('settings.organization.delete.warning_text') }}
                        </p>
                    </div>
                </div>

                <Dialog>
                    <DialogTrigger as-child>
                        <button
                            type="button"
                            data-test="delete-organization-button"
                            class="inline-flex items-center gap-1.5 self-start rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20"
                        >
                            <Trash2 class="size-3.5" />
                            {{ t('settings.organization.delete.button') }}
                        </button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="OrganizationController.destroy.form()"
                            :options="{ preserveScroll: true }"
                            class="space-y-6"
                            @success="deleteConfirmation = ''"
                            v-slot="{ errors, processing }"
                        >
                            <DialogHeader class="space-y-3">
                                <DialogTitle>
                                    {{ t('settings.organization.delete.confirm_title') }}
                                </DialogTitle>
                                <DialogDescription>
                                    {{ t('settings.organization.delete.confirm_description') }}
                                </DialogDescription>
                            </DialogHeader>

                            <div class="space-y-1.5">
                                <Label for="delete-organization-name">
                                    {{ t('settings.organization.delete.confirm_label', { name: organization.name }) }}
                                </Label>
                                <Input
                                    id="delete-organization-name"
                                    v-model="deleteConfirmation"
                                    name="name"
                                    autocomplete="off"
                                    :placeholder="t('settings.organization.delete.confirm_placeholder')"
                                    class="h-9"
                                />
                                <InputError :message="errors.name" />
                            </div>

                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                                        @click="deleteConfirmation = ''"
                                    >
                                        {{ t('common.cancel') }}
                                    </button>
                                </DialogClose>
                                <button
                                    type="submit"
                                    :disabled="processing || !canDelete"
                                    data-test="confirm-delete-organization-button"
                                    class="inline-flex items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20 disabled:opacity-50"
                                >
                                    <Trash2 class="size-3.5" />
                                    {{ t('settings.organization.delete.button') }}
                                </button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </SettingsCard>
        </div>
    </SettingsLayout>
</template>
