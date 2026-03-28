<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';

interface Props {
    status?: string;
}

defineProps<Props>();

const { t } = useI18n();

const form = useForm({});

function submit(): void {
    form.post('/email/verification-notification');
}
</script>

<template>
    <AuthLayout>
        <Head :title="t('auth.verify_email.title')" />

        <div class="flex flex-col space-y-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ t('auth.verify_email.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('auth.verify_email.description') }}
                </p>
            </div>

            <div
                v-if="status === 'verification-link-sent'"
                class="rounded-md bg-green-50 p-3 text-center text-sm font-medium text-green-600 dark:bg-green-900/20 dark:text-green-400"
            >
                {{ t('auth.verify_email.link_sent') }}
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    {{ t('auth.verify_email.resend') }}
                </Button>

                <p class="text-center text-sm text-muted-foreground">
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="underline underline-offset-4 hover:text-foreground"
                    >
                        {{ t('auth.verify_email.logout') }}
                    </Link>
                </p>
            </form>
        </div>
    </AuthLayout>
</template>
