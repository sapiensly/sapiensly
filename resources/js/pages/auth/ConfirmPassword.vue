<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

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

        <header class="space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ t('auth.confirm_password.title') }}
            </h1>
            <p class="text-xs text-ink-muted">
                {{ t('auth.confirm_password.description') }}
            </p>
        </header>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div class="space-y-1.5">
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
                    :placeholder="t('auth.confirm_password.password_placeholder')"
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
                {{ t('auth.confirm_password.submit') }}
            </button>
        </form>
    </AuthLayout>
</template>
