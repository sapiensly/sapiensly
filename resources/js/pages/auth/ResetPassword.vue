<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

interface Props {
    email: string;
    token: string;
}

const props = defineProps<Props>();

const { t } = useI18n();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthLayout>
        <Head :title="t('auth.reset_password.title')" />

        <header class="space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ t('auth.reset_password.title') }}
            </h1>
            <p class="text-xs text-ink-muted">
                {{ t('auth.reset_password.description') }}
            </p>
        </header>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div class="space-y-1.5">
                <Label for="email">{{ t('auth.reset_password.email') }}</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="username"
                    disabled
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="space-y-1.5">
                <Label for="password">{{
                    t('auth.reset_password.password')
                }}</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                    autofocus
                    autocomplete="new-password"
                    :placeholder="t('auth.reset_password.password_placeholder')"
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="space-y-1.5">
                <Label for="password_confirmation">{{
                    t('auth.reset_password.password_confirmation')
                }}</Label>
                <Input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="
                        t(
                            'auth.reset_password.password_confirmation_placeholder',
                        )
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
                {{ t('auth.reset_password.submit') }}
            </button>
        </form>
    </AuthLayout>
</template>
