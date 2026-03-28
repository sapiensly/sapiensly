<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

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

        <div class="flex flex-col space-y-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ t('auth.two_factor.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        useRecoveryCode
                            ? t('auth.two_factor.recovery_description')
                            : t('auth.two_factor.code_description')
                    }}
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div v-if="!useRecoveryCode" class="grid gap-2">
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

                <div v-else class="grid gap-2">
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

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    {{ t('auth.two_factor.submit') }}
                </Button>

                <p class="text-center text-sm text-muted-foreground">
                    <button
                        type="button"
                        class="underline underline-offset-4 hover:text-foreground"
                        @click="toggleMode"
                    >
                        {{
                            useRecoveryCode
                                ? t('auth.two_factor.use_code')
                                : t('auth.two_factor.use_recovery_code')
                        }}
                    </button>
                </p>
            </form>
        </div>
    </AuthLayout>
</template>
