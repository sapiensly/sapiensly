<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

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

        <header class="space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ t('auth.login.title') }}
            </h1>
            <p class="text-xs text-ink-muted">
                {{ t('auth.login.description') }}
            </p>
        </header>

        <div
            v-if="status"
            class="mt-5 rounded-xs border border-sp-success/40 bg-sp-success/10 px-3 py-2 text-xs text-sp-success"
        >
            {{ status }}
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div class="space-y-1.5">
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

            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <Label for="password">{{ t('auth.login.password') }}</Label>
                    <Link
                        v-if="canResetPassword"
                        href="/forgot-password"
                        class="text-[11px] text-ink-muted transition-colors hover:text-accent-blue"
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

            <button
                type="submit"
                :disabled="form.processing"
                class="flex h-10 w-full items-center justify-center gap-2 rounded-pill bg-accent-blue text-sm font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
            >
                <LoaderCircle
                    v-if="form.processing"
                    class="size-4 animate-spin"
                />
                {{ t('auth.login.submit') }}
            </button>
        </form>

        <p
            v-if="canRegister"
            class="mt-6 text-center text-xs text-ink-muted"
        >
            {{ t('auth.login.no_account') }}
            <Link
                href="/register"
                class="font-medium text-accent-blue transition-colors hover:text-accent-blue-hover"
            >
                {{ t('auth.login.register') }}
            </Link>
        </p>
    </AuthLayout>
</template>
