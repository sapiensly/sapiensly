<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import DeleteUser from '@/components/DeleteUser.vue';
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
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Globe, User } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    status?: string;
}

defineProps<Props>();

const page = usePage();
const user = page.props.auth.user;

// Mirror the user's stored locale so the shadcn Select can bind v-model while
// still POSTing via the hidden `<input name="locale">`.
const locale = ref<string>(user.locale ?? 'en');
</script>

<template>
    <Head :title="t('settings.profile.breadcrumb')" />

    <SettingsLayout>
        <div class="space-y-4">
            <Form
                v-bind="ProfileController.update.form()"
                class="space-y-4"
                v-slot="{ errors, processing, recentlySuccessful }"
            >
                <!-- Identity. -->
                <SettingsCard
                    :icon="User"
                    :title="t('settings.profile.title')"
                    :description="t('settings.profile.description')"
                >
                    <div class="space-y-1.5">
                        <Label for="name">
                            {{ t('settings.profile.name') }}
                        </Label>
                        <Input
                            id="name"
                            class="h-9"
                            name="name"
                            :default-value="user.name"
                            required
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="email">
                            {{ t('settings.profile.email') }}
                        </Label>
                        <Input
                            id="email"
                            type="email"
                            class="h-9"
                            name="email"
                            :default-value="user.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                            disabled
                        />
                        <InputError :message="errors.email" />
                    </div>
                </SettingsCard>

                <!-- Language. -->
                <SettingsCard
                    :icon="Globe"
                    :title="t('settings.profile.language')"
                    description="Interface language for this account"
                    tint="var(--sp-accent-cyan)"
                >
                    <div class="space-y-1.5">
                        <Label for="locale">
                            {{ t('settings.profile.language') }}
                        </Label>
                        <!-- Hidden input carries the value in the native form POST. -->
                        <input type="hidden" name="locale" :value="locale" />
                        <Select v-model="locale">
                            <SelectTrigger id="locale" class="h-9">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="en">English</SelectItem>
                                <SelectItem value="es">Español</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </SettingsCard>

                <!-- Footer actions — primary save + saved flash. -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="recentlySuccessful"
                            class="text-[11px] text-sp-success"
                        >
                            {{ t('settings.profile.saved') }}
                        </p>
                    </Transition>
                    <button
                        type="submit"
                        :disabled="processing"
                        data-test="update-profile-button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        {{ t('settings.profile.save') }}
                    </button>
                </div>
            </Form>

            <!-- Danger zone — component owns its own styling. -->
            <DeleteUser />
        </div>
    </SettingsLayout>
</template>
