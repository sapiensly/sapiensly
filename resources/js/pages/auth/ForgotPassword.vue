<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

interface Props {
    status?: string;
}

defineProps<Props>();

const { t } = useI18n();

const form = useForm({
    email: '',
});

function submit(): void {
    form.post('/forgot-password');
}
</script>

<template>
    <AuthLayout>
        <Head :title="t('auth.forgot_password.title')" />

        <div class="flex flex-col space-y-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ t('auth.forgot_password.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('auth.forgot_password.description') }}
                </p>
            </div>

            <div
                v-if="status"
                class="rounded-md bg-green-50 p-3 text-center text-sm font-medium text-green-600 dark:bg-green-900/20 dark:text-green-400"
            >
                {{ status }}
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="email">{{
                        t('auth.forgot_password.email')
                    }}</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        autocomplete="username"
                        :placeholder="
                            t('auth.forgot_password.email_placeholder')
                        "
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    {{ t('auth.forgot_password.submit') }}
                </Button>

                <p class="text-center text-sm text-muted-foreground">
                    <Link
                        href="/login"
                        class="underline underline-offset-4 hover:text-foreground"
                    >
                        {{ t('auth.forgot_password.back_to_login') }}
                    </Link>
                </p>
            </form>
        </div>
    </AuthLayout>
</template>
