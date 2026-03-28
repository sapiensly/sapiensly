<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

const { t } = useI18n();

const form = useForm({
    password: '',
});

function submit(): void {
    form.post('/user/confirm-password', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthLayout>
        <Head :title="t('auth.confirm_password.title')" />

        <div class="flex flex-col space-y-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ t('auth.confirm_password.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('auth.confirm_password.description') }}
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="password">{{
                        t('auth.confirm_password.password')
                    }}</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autofocus
                        autocomplete="current-password"
                        :placeholder="
                            t('auth.confirm_password.password_placeholder')
                        "
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    {{ t('auth.confirm_password.submit') }}
                </Button>
            </form>
        </div>
    </AuthLayout>
</template>
