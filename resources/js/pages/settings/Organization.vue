<script setup lang="ts">
import SettingsCard from '@/components/admin/SettingsCard.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { Organization, User } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Building2, Mail, Users } from 'lucide-vue-next';
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
}

defineProps<Props>();

const inviteForm = useForm({
    email: '',
});

const { getInitials } = useInitials();

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
                            class="inline-flex items-center rounded-pill border border-medium bg-white/5 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
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
        </div>
    </SettingsLayout>
</template>
