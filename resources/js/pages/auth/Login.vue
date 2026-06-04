<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Building2, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    canResetPassword: boolean;
    canRegister: boolean;
    canLoginWithGoogle?: boolean;
    status?: string;
}

defineProps<Props>();

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
});

const showSso = ref(false);
const ssoSlug = ref('');

function submit(): void {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}

function continueWithSso(): void {
    const slug = ssoSlug.value.trim();
    if (slug !== '') {
        window.location.href = `/sso/${encodeURIComponent(slug)}`;
    }
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

        <div class="mt-4 text-center">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 text-[11px] text-ink-muted transition-colors hover:text-accent-blue"
                @click="showSso = !showSso"
            >
                <Building2 class="size-3.5" />
                {{ t('auth.login.sso') }}
            </button>
        </div>

        <div
            v-if="showSso"
            class="mt-3 space-y-1.5"
        >
            <Label for="sso-slug">{{ t('auth.login.sso_slug') }}</Label>
            <div class="flex gap-2">
                <Input
                    id="sso-slug"
                    v-model="ssoSlug"
                    type="text"
                    autocapitalize="none"
                    :placeholder="t('auth.login.sso_slug_placeholder')"
                    @keyup.enter="continueWithSso"
                />
                <button
                    type="button"
                    :disabled="ssoSlug.trim() === ''"
                    class="flex h-10 shrink-0 items-center rounded-pill bg-accent-blue px-4 text-sm font-medium text-white transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                    @click="continueWithSso"
                >
                    {{ t('auth.login.sso_continue') }}
                </button>
            </div>
        </div>

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
