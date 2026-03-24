<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem, Organization, User } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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

const props = defineProps<Props>();

const breadcrumbItems = computed<BreadcrumbItem[]>(() => [
    {
        title: t('settings.organization.breadcrumb'),
        href: '/settings/organization',
    },
]);

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
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="t('settings.organization.breadcrumb')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    :title="t('settings.organization.title')"
                    :description="t('settings.organization.description')"
                />

                <div class="space-y-4">
                    <div>
                        <Label>{{ t('settings.organization.name') }}</Label>
                        <p class="mt-1 text-sm">{{ organization.name }}</p>
                    </div>
                    <div v-if="organization.slug">
                        <Label>{{ t('settings.organization.slug') }}</Label>
                        <p class="mt-1 text-sm text-muted-foreground">{{ organization.slug }}</p>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <HeadingSmall
                    :title="t('settings.organization.members')"
                    :description="`${members.length} member${members.length !== 1 ? 's' : ''}`"
                />

                <div class="divide-y rounded-md border">
                    <div
                        v-for="member in members"
                        :key="member.id"
                        class="flex items-center justify-between p-4"
                    >
                        <div class="flex items-center gap-3">
                            <Avatar class="h-8 w-8">
                                <AvatarFallback class="text-xs">
                                    {{ getInitials(member.user.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <div>
                                <p class="text-sm font-medium">{{ member.user.name }}</p>
                                <p class="text-xs text-muted-foreground">{{ member.user.email }}</p>
                            </div>
                        </div>
                        <Badge variant="secondary">{{ member.role }}</Badge>
                    </div>
                </div>
            </div>

            <div v-if="isAdmin" class="space-y-6">
                <HeadingSmall
                    :title="t('settings.organization.invite_title')"
                    :description="t('settings.organization.invite_description')"
                />

                <form @submit.prevent="submitInvite" class="flex items-end gap-3">
                    <div class="flex-1 space-y-2">
                        <Label for="invite-email">{{ t('common.email_address') }}</Label>
                        <Input
                            id="invite-email"
                            v-model="inviteForm.email"
                            type="email"
                            :placeholder="t('settings.organization.invite_placeholder')"
                        />
                        <InputError :message="inviteForm.errors.email" />
                    </div>
                    <Button type="submit" :disabled="inviteForm.processing">
                        {{ t('settings.organization.send_invitation') }}
                    </Button>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
