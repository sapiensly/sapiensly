<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

defineProps<{
    canLoginWithGoogle?: boolean;
}>();

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

        <header class="space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ t('auth.register.title') }}
            </h1>
            <p class="text-xs text-ink-muted">
                {{ t('auth.register.description') }}
            </p>
        </header>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div class="space-y-1.5">
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

            <div class="space-y-1.5">
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

            <div class="space-y-1.5">
                <Label for="password">{{ t('auth.register.password') }}</Label>
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

            <div class="space-y-1.5">
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

            <button
                type="submit"
                :disabled="form.processing"
                class="flex h-10 w-full items-center justify-center gap-2 rounded-pill bg-accent-blue text-sm font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
            >
                <LoaderCircle
                    v-if="form.processing"
                    class="size-4 animate-spin"
                />
                {{ t('auth.register.submit') }}
            </button>
        </form>

        <div
            v-if="canLoginWithGoogle"
            class="mt-6 flex items-center gap-3"
        >
            <span class="h-px flex-1 bg-soft" />
            <span class="text-[11px] uppercase tracking-wide text-ink-muted">
                {{ t('auth.login.or') }}
            </span>
            <span class="h-px flex-1 bg-soft" />
        </div>

        <a
            v-if="canLoginWithGoogle"
            href="/auth/google/redirect"
            class="mt-5 flex h-10 w-full items-center justify-center gap-2.5 rounded-pill border border-soft bg-surface text-sm font-medium text-ink transition-colors hover:bg-navy"
        >
            <svg
                class="size-4"
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path
                    fill="#EA4335"
                    d="M12 10.2v3.9h5.5c-.24 1.4-.96 2.6-2.05 3.4v2.8h3.3c1.94-1.8 3.05-4.4 3.05-7.5 0-.7-.06-1.4-.18-2z"
                />
                <path
                    fill="#34A853"
                    d="M12 22c2.76 0 5.07-.92 6.76-2.5l-3.3-2.8c-.92.6-2.1.95-3.46.95-2.66 0-4.91-1.8-5.72-4.2H2.86v2.9C4.54 19.6 8 22 12 22z"
                />
                <path
                    fill="#4A90D9"
                    d="M6.28 13.45A6 6 0 0 1 5.96 12c0-.5.09-1 .24-1.45V7.65H2.86A10 10 0 0 0 2 12c0 1.6.38 3.1 1.06 4.45z"
                />
                <path
                    fill="#FBBC05"
                    d="M12 6.35c1.5 0 2.85.52 3.91 1.53l2.92-2.92C17.07 3.3 14.76 2.4 12 2.4 8 2.4 4.54 4.8 2.86 8.25l3.42 2.9C7.09 8.15 9.34 6.35 12 6.35z"
                />
            </svg>
            {{ t('auth.login.google') }}
        </a>

        <p class="mt-6 text-center text-xs text-ink-muted">
            {{ t('auth.register.has_account') }}
            <Link
                href="/login"
                class="font-medium text-accent-blue transition-colors hover:text-accent-blue-hover"
            >
                {{ t('auth.register.login') }}
            </Link>
        </p>
    </AuthLayout>
</template>
