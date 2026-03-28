<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const breadcrumbItems = computed<BreadcrumbItem[]>(() => [
    {
        title: t('settings.organization_create.breadcrumb'),
        href: '/settings/organization/create',
    },
]);

const form = useForm({
    name: '',
});

const submit = () => {
    form.post('/settings/organization');
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="t('settings.organization_create.breadcrumb')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    :title="t('settings.organization_create.title')"
                    :description="t('settings.organization_create.description')"
                />

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="space-y-2">
                        <Label for="org-name">{{
                            t('settings.organization_create.name_label')
                        }}</Label>
                        <Input
                            id="org-name"
                            v-model="form.name"
                            type="text"
                            :placeholder="
                                t(
                                    'settings.organization_create.name_placeholder',
                                )
                            "
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <Button type="submit" :disabled="form.processing">
                        {{ t('settings.organization_create.submit') }}
                    </Button>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
