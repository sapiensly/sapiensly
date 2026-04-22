<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

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

        <header class="space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ t('auth.verify_email.title') }}
            </h1>
            <p class="text-xs text-ink-muted">
                {{ t('auth.verify_email.description') }}
            </p>
        </header>

        <div
            v-if="status === 'verification-link-sent'"
            class="mt-5 rounded-xs border border-sp-success/40 bg-sp-success/10 px-3 py-2 text-xs text-sp-success"
        >
            {{ t('auth.verify_email.link_sent') }}
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <button
                type="submit"
                :disabled="form.processing"
                class="flex h-10 w-full items-center justify-center gap-2 rounded-pill bg-accent-blue text-sm font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
            >
                <LoaderCircle
                    v-if="form.processing"
                    class="size-4 animate-spin"
                />
                {{ t('auth.verify_email.resend') }}
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-ink-muted">
            <Link
                href="/logout"
                method="post"
                as="button"
                class="font-medium text-accent-blue transition-colors hover:text-accent-blue-hover"
            >
                {{ t('auth.verify_email.logout') }}
            </Link>
        </p>
    </AuthLayout>
</template>
