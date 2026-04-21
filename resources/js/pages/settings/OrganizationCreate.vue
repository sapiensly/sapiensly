<script setup lang="ts">
import SettingsCard from '@/components/admin/SettingsCard.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Building2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const form = useForm({
    name: '',
});

const submit = () => {
    form.post('/settings/organization');
};
</script>

<template>
    <Head :title="t('settings.organization_create.breadcrumb')" />

    <SettingsLayout>
        <form class="space-y-4" @submit.prevent="submit">
            <SettingsCard
                :icon="Building2"
                :title="t('settings.organization_create.title')"
                :description="t('settings.organization_create.description')"
            >
                <div class="space-y-1.5">
                    <Label for="org-name">
                        {{ t('settings.organization_create.name_label') }}
                    </Label>
                    <Input
                        id="org-name"
                        v-model="form.name"
                        type="text"
                        :placeholder="t('settings.organization_create.name_placeholder')"
                        required
                        class="h-9"
                    />
                    <InputError :message="form.errors.name" />
                </div>
            </SettingsCard>

            <div class="flex items-center justify-end pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                >
                    {{ t('settings.organization_create.submit') }}
                </button>
            </div>
        </form>
    </SettingsLayout>
</template>
