<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

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

        <header class="space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ t('auth.forgot_password.title') }}
            </h1>
            <p class="text-xs text-ink-muted">
                {{ t('auth.forgot_password.description') }}
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
                <Label for="email">{{ t('auth.forgot_password.email') }}</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autofocus
                    autocomplete="username"
                    :placeholder="t('auth.forgot_password.email_placeholder')"
                />
                <InputError :message="form.errors.email" />
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
                {{ t('auth.forgot_password.submit') }}
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-ink-muted">
            <Link
                href="/login"
                class="font-medium text-accent-blue transition-colors hover:text-accent-blue-hover"
            >
                {{ t('auth.forgot_password.back_to_login') }}
            </Link>
        </p>
    </AuthLayout>
</template>
