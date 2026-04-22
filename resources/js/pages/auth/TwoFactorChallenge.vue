<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const useRecoveryCode = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

function submit(): void {
    form.post('/two-factor-challenge');
}

function toggleMode(): void {
    useRecoveryCode.value = !useRecoveryCode.value;
    form.code = '';
    form.recovery_code = '';
    form.clearErrors();
}
</script>

<template>
    <AuthLayout>
        <Head :title="t('auth.two_factor.title')" />

        <header class="space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ t('auth.two_factor.title') }}
            </h1>
            <p class="text-xs text-ink-muted">
                {{
                    useRecoveryCode
                        ? t('auth.two_factor.recovery_description')
                        : t('auth.two_factor.code_description')
                }}
            </p>
        </header>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div v-if="!useRecoveryCode" class="space-y-1.5">
                <Label for="code">{{ t('auth.two_factor.code') }}</Label>
                <Input
                    id="code"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    :placeholder="t('auth.two_factor.code_placeholder')"
                />
                <InputError :message="form.errors.code" />
            </div>

            <div v-else class="space-y-1.5">
                <Label for="recovery_code">{{
                    t('auth.two_factor.recovery_code')
                }}</Label>
                <Input
                    id="recovery_code"
                    v-model="form.recovery_code"
                    type="text"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    :placeholder="
                        t('auth.two_factor.recovery_code_placeholder')
                    "
                />
                <InputError :message="form.errors.recovery_code" />
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
                {{ t('auth.two_factor.submit') }}
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-ink-muted">
            <button
                type="button"
                class="font-medium text-accent-blue transition-colors hover:text-accent-blue-hover"
                @click="toggleMode"
            >
                {{
                    useRecoveryCode
                        ? t('auth.two_factor.use_code')
                        : t('auth.two_factor.use_recovery_code')
                }}
            </button>
        </p>
    </AuthLayout>
</template>
