<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Form } from '@inertiajs/vue3';
import { AlertTriangle, Trash2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <SettingsCard
        :icon="AlertTriangle"
        :title="t('settings.delete_account.title')"
        :description="t('settings.delete_account.description')"
        tint="var(--sp-danger)"
    >
        <!-- Warning banner — admin-v2 danger pill treatment. -->
        <div
            class="flex items-start gap-2 rounded-xs border border-sp-danger/30 bg-sp-danger/10 p-3"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-sp-danger" />
            <div class="space-y-0.5">
                <p class="text-sm font-medium text-sp-danger">
                    {{ t('settings.delete_account.warning') }}
                </p>
                <p class="text-[11px] text-sp-danger/80">
                    {{ t('settings.delete_account.warning_text') }}
                </p>
            </div>
        </div>

        <Dialog>
            <DialogTrigger as-child>
                <button
                    type="button"
                    data-test="delete-user-button"
                    class="inline-flex items-center gap-1.5 self-start rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20"
                >
                    <Trash2 class="size-3.5" />
                    {{ t('settings.delete_account.title') }}
                </button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="ProfileController.destroy.form()"
                    reset-on-success
                    :options="{
                        preserveScroll: true,
                    }"
                    class="space-y-6"
                    v-slot="{ errors, processing, reset, clearErrors }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            {{ t('settings.delete_account.confirm_title') }}
                        </DialogTitle>
                        <DialogDescription>
                            {{ t('settings.delete_account.confirm_description') }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-1.5">
                        <Label for="delete-password">
                            {{ t('auth.confirm_password.password') }}
                        </Label>
                        <Input
                            id="delete-password"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            :placeholder="t('auth.confirm_password.password_placeholder')"
                            class="h-9"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                                @click="
                                    () => {
                                        clearErrors();
                                        reset();
                                    }
                                "
                            >
                                {{ t('common.cancel') }}
                            </button>
                        </DialogClose>
                        <button
                            type="submit"
                            :disabled="processing"
                            data-test="confirm-delete-user-button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20 disabled:opacity-50"
                        >
                            <Trash2 class="size-3.5" />
                            {{ t('settings.delete_account.title') }}
                        </button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </SettingsCard>
</template>
