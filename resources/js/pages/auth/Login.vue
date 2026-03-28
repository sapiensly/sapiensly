<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

interface Props {
    canResetPassword: boolean;
    canRegister: boolean;
    status?: string;
}

defineProps<Props>();

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
});

function submit(): void {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthLayout>
        <Head :title="t('auth.login.title')" />

        <div class="flex flex-col space-y-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ t('auth.login.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('auth.login.description') }}
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
                    <Label for="email">{{ t('auth.login.email') }}</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        autocomplete="username"
                        :placeholder="t('auth.login.email_placeholder')"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">{{
                            t('auth.login.password')
                        }}</Label>
                        <Link
                            v-if="canResetPassword"
                            href="/forgot-password"
                            class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            {{ t('auth.login.forgot_password') }}
                        </Link>
                    </div>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        :placeholder="t('auth.login.password_placeholder')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    {{ t('auth.login.submit') }}
                </Button>

                <p
                    v-if="canRegister"
                    class="text-center text-sm text-muted-foreground"
                >
                    {{ t('auth.login.no_account') }}
                    <Link
                        href="/register"
                        class="underline underline-offset-4 hover:text-foreground"
                    >
                        {{ t('auth.login.register') }}
                    </Link>
                </p>
            </form>
        </div>
    </AuthLayout>
</template>
