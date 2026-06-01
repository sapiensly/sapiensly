<script setup lang="ts">
import SsoConnectionController from '@/actions/App/Http/Controllers/Settings/SsoConnectionController';
import ChipsInput from '@/components/admin/ChipsInput.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import ToggleRow from '@/components/admin/ToggleRow.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Key, Lock, Plug } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface Endpoints {
    authorize_url: string | null;
    token_url: string | null;
    userinfo_url: string | null;
}

interface Connection {
    enabled: boolean;
    auto_provision: boolean;
    issuer: string | null;
    client_id: string | null;
    has_secret: boolean;
    allowed_domains: string[];
    endpoints: Endpoints | null;
}

const props = defineProps<{
    connection: Connection;
    ssoUrl: string;
}>();

const { t } = useI18n();

const form = useForm({
    enabled: props.connection.enabled,
    auto_provision: props.connection.auto_provision,
    issuer: props.connection.issuer ?? '',
    client_id: props.connection.client_id ?? '',
    client_secret: '',
    allowed_domains: [...props.connection.allowed_domains],
});

const verifying = ref(false);
const verified = ref(false);
const verifyError = ref<string | null>(null);

function submit(): void {
    form.submit(SsoConnectionController.update());
}

function verify(): void {
    verifying.value = true;
    verified.value = false;
    verifyError.value = null;

    router.post(
        SsoConnectionController.discover().url,
        { issuer: form.issuer },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                verified.value = true;
            },
            onError: (errors) => {
                verifyError.value = errors.issuer ?? t('settings.sso.verify_failed');
            },
            onFinish: () => {
                verifying.value = false;
            },
        },
    );
}

function copySsoUrl(): void {
    navigator.clipboard?.writeText(props.ssoUrl);
}
</script>

<template>
    <Head :title="t('settings.sso.breadcrumb')" />

    <SettingsLayout>
        <form
            class="space-y-4"
            @submit.prevent="submit"
        >
            <!-- Enablement. -->
            <SettingsCard
                :icon="Lock"
                :title="t('settings.sso.title')"
                :description="t('settings.sso.description')"
            >
                <ToggleRow
                    v-model="form.enabled"
                    :label="t('settings.sso.enable')"
                    :description="t('settings.sso.enable_hint')"
                />
                <ToggleRow
                    v-model="form.auto_provision"
                    :label="t('settings.sso.auto_provision')"
                    :description="t('settings.sso.auto_provision_hint')"
                />

                <div class="space-y-1.5">
                    <Label>{{ t('settings.sso.login_url') }}</Label>
                    <div class="flex gap-2">
                        <Input
                            :model-value="ssoUrl"
                            class="h-9"
                            readonly
                        />
                        <button
                            type="button"
                            class="h-9 shrink-0 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                            @click="copySsoUrl"
                        >
                            {{ t('settings.sso.copy') }}
                        </button>
                    </div>
                    <p class="text-xs text-ink-muted">
                        {{ t('settings.sso.login_url_hint') }}
                    </p>
                </div>
            </SettingsCard>

            <!-- IdP credentials. -->
            <SettingsCard
                :icon="Key"
                :title="t('settings.sso.idp')"
                :description="t('settings.sso.idp_hint')"
                tint="var(--sp-accent-cyan)"
            >
                <div class="space-y-1.5">
                    <Label for="issuer">{{ t('settings.sso.issuer') }}</Label>
                    <div class="flex gap-2">
                        <Input
                            id="issuer"
                            v-model="form.issuer"
                            class="h-9"
                            placeholder="https://idp.example.com"
                            autocapitalize="none"
                        />
                        <button
                            type="button"
                            :disabled="verifying || form.issuer.trim() === ''"
                            class="h-9 shrink-0 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink disabled:opacity-50"
                            @click="verify"
                        >
                            {{ t('settings.sso.verify') }}
                        </button>
                    </div>
                    <InputError :message="form.errors.issuer || verifyError || undefined" />
                    <p
                        v-if="verified"
                        class="flex items-center gap-1.5 text-[11px] text-sp-success"
                    >
                        <CheckCircle2 class="size-3.5" />
                        {{ t('settings.sso.verified') }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <Label for="client_id">{{ t('settings.sso.client_id') }}</Label>
                    <Input
                        id="client_id"
                        v-model="form.client_id"
                        class="h-9"
                        autocapitalize="none"
                    />
                    <InputError :message="form.errors.client_id" />
                </div>

                <div class="space-y-1.5">
                    <Label for="client_secret">{{ t('settings.sso.client_secret') }}</Label>
                    <Input
                        id="client_secret"
                        v-model="form.client_secret"
                        type="password"
                        class="h-9"
                        autocomplete="off"
                        :placeholder="connection.has_secret ? '••••••••••••' : ''"
                    />
                    <InputError :message="form.errors.client_secret" />
                    <p
                        v-if="connection.has_secret"
                        class="text-xs text-ink-muted"
                    >
                        {{ t('settings.sso.client_secret_hint') }}
                    </p>
                </div>
            </SettingsCard>

            <!-- Access policy. -->
            <SettingsCard
                :icon="Plug"
                :title="t('settings.sso.access')"
                :description="t('settings.sso.access_hint')"
                tint="var(--sp-spectrum-indigo)"
            >
                <div class="space-y-1.5">
                    <Label>{{ t('settings.sso.allowed_domains') }}</Label>
                    <ChipsInput
                        v-model="form.allowed_domains"
                        lowercase
                        :placeholder="t('settings.sso.allowed_domains_placeholder')"
                    />
                    <p class="text-xs text-ink-muted">
                        {{ t('settings.sso.allowed_domains_hint') }}
                    </p>
                </div>

                <div
                    v-if="connection.endpoints?.authorize_url"
                    class="space-y-1 rounded-xs border border-soft bg-white/[0.02] p-3 text-xs text-ink-muted"
                >
                    <p class="font-medium text-ink">
                        {{ t('settings.sso.resolved_endpoints') }}
                    </p>
                    <p class="truncate">authorize: {{ connection.endpoints.authorize_url }}</p>
                    <p class="truncate">token: {{ connection.endpoints.token_url }}</p>
                    <p class="truncate">userinfo: {{ connection.endpoints.userinfo_url }}</p>
                </div>
            </SettingsCard>

            <!-- Footer actions. -->
            <div class="flex items-center justify-end gap-3 pt-2">
                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-show="form.recentlySuccessful"
                        class="text-[11px] text-sp-success"
                    >
                        {{ t('settings.sso.saved') }}
                    </p>
                </Transition>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                >
                    {{ t('settings.sso.save') }}
                </button>
            </div>
        </form>
    </SettingsLayout>
</template>
