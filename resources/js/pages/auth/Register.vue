<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

const { t } = useI18n();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthLayout>
        <Head :title="t('auth.register.title')" />

        <div class="flex flex-col space-y-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ t('auth.register.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('auth.register.description') }}
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="name">{{ t('auth.register.name') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        :placeholder="t('auth.register.name_placeholder')"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.register.email') }}</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="username"
                        :placeholder="t('auth.register.email_placeholder')"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{
                        t('auth.register.password')
                    }}</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="t('auth.register.password_placeholder')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{
                        t('auth.register.password_confirmation')
                    }}</Label>
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="
                            t('auth.register.password_confirmation_placeholder')
                        "
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    {{ t('auth.register.submit') }}
                </Button>

                <p class="text-center text-sm text-muted-foreground">
                    {{ t('auth.register.has_account') }}
                    <Link
                        href="/login"
                        class="underline underline-offset-4 hover:text-foreground"
                    >
                        {{ t('auth.register.login') }}
                    </Link>
                </p>
            </form>
        </div>
    </AuthLayout>
</template>
