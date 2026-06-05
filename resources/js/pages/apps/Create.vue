<script setup lang="ts">
import * as AppController from '@/actions/App/Http/Controllers/AppController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AppWindow, Shield } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const form = useForm({
    name: '',
    slug: '',
    description: '',
    visibility: 'private' as 'private' | 'organization',
});

function submit() {
    form.post(AppController.store().url);
}
</script>

<template>
    <Head :title="t('apps.create.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.apps')">
        <div class="mx-auto max-w-3xl space-y-6">
            <PageHeader
                :title="t('apps.create.heading')"
                :description="t('apps.create.description')"
            />

            <form class="space-y-4" @submit.prevent="submit">
                <SettingsCard
                    :icon="AppWindow"
                    :title="t('apps.create.heading')"
                    :description="t('apps.create.description')"
                >
                    <div class="space-y-1.5">
                        <Label for="name" class="text-xs text-ink-muted">
                            {{ t('apps.create.name_label') }}
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            maxlength="100"
                            class="h-9 border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <p class="text-[11px] text-ink-subtle">
                            {{ t('apps.create.name_help') }}
                        </p>
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="slug" class="text-xs text-ink-muted">
                            {{ t('apps.create.slug_label') }}
                        </Label>
                        <Input
                            id="slug"
                            v-model="form.slug"
                            required
                            placeholder="mini_crm"
                            class="h-9 border-medium bg-surface font-mono text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <p class="text-[11px] text-ink-subtle">
                            {{ t('apps.create.slug_help') }}
                        </p>
                        <InputError :message="form.errors.slug" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="description" class="text-xs text-ink-muted">
                            {{ t('apps.create.description_label') }}
                        </Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            maxlength="500"
                            class="border-medium bg-surface text-sm text-ink placeholder:text-ink-subtle"
                        />
                        <p class="text-[11px] text-ink-subtle">
                            {{ t('apps.create.description_help') }}
                        </p>
                        <InputError :message="form.errors.description" />
                    </div>
                </SettingsCard>

                <SettingsCard
                    :icon="Shield"
                    :title="t('apps.create.visibility_label')"
                    :description="t('apps.create.visibility_label')"
                    tint="var(--sp-spectrum-magenta)"
                >
                    <div class="space-y-1.5">
                        <Label for="visibility" class="text-xs text-ink-muted">
                            {{ t('apps.create.visibility_label') }}
                        </Label>
                        <Select v-model="form.visibility">
                            <SelectTrigger
                                id="visibility"
                                class="h-9 border-medium bg-surface text-sm text-ink"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="private">
                                    {{ t('apps.create.visibility_private') }}
                                </SelectItem>
                                <SelectItem value="organization">
                                    {{ t('apps.create.visibility_organization') }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.visibility" />
                    </div>
                </SettingsCard>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <Link :href="AppController.index().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                        >
                            {{ t('apps.create.cancel') }}
                        </button>
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('apps.create.submit') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
