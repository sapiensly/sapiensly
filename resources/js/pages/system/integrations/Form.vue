<script setup lang="ts">
import AuthConfigField from '@/components/integrations/AuthConfigField.vue';
import HeaderEditor from '@/components/integrations/HeaderEditor.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import axios from 'axios';
import {
    CheckCircle2,
    ChevronDown,
    Loader2,
    Plug,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface AuthTypeOption {
    value: string;
    label: string;
}

interface Props {
    mode: 'create' | 'edit';
    integration?: {
        id: string;
        name: string;
        description: string | null;
        base_url: string;
        auth_type: string;
        visibility: string;
        status: string;
        color: string | null;
        icon: string | null;
        allow_insecure_tls: boolean;
        default_headers: Array<{ key: string; value: string }> | null;
        masked_auth_config: Record<string, unknown>;
    };
    authTypes: AuthTypeOption[];
    visibilities: AuthTypeOption[];
}

const props = defineProps<Props>();

const { t } = useI18n();

const form = useForm({
    name: props.integration?.name ?? '',
    description: props.integration?.description ?? '',
    base_url: props.integration?.base_url ?? '',
    auth_type: props.integration?.auth_type ?? 'none',
    auth_config: {} as Record<string, unknown>,
    default_headers: props.integration?.default_headers ?? [],
    visibility: props.integration?.visibility ?? 'private',
    status: props.integration?.status ?? 'active',
    allow_insecure_tls: props.integration?.allow_insecure_tls ?? false,
});

const openBasics = ref(true);
const openAuth = ref(true);
const openHeaders = ref(false);
const openVisibility = ref(false);

type TestState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; message: string }
    | { status: 'error'; message: string };

const testState = ref<TestState>({ status: 'idle' });

async function testConnection(): Promise<void> {
    testState.value = { status: 'loading' };
    try {
        const { data } = await axios.post('/system/integrations/test-connection', {
            base_url: form.base_url,
            auth_type: form.auth_type,
            auth_config: form.auth_config,
            allow_insecure_tls: form.allow_insecure_tls,
        });

        testState.value = data.success
            ? { status: 'success', message: data.message || t('system.integrations.test_success') }
            : { status: 'error', message: data.detail || data.message || t('system.integrations.test_failed') };
    } catch {
        testState.value = { status: 'error', message: t('system.integrations.test_failed') };
    }
}

function submit(): void {
    if (props.mode === 'create') {
        form.post('/system/integrations');
    } else if (props.integration) {
        form.put(`/system/integrations/${props.integration.id}`);
    }
}
</script>

<template>
    <Head :title="t('system.integrations.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="mx-auto max-w-3xl space-y-6">
            <PageHeader
                :title="mode === 'create' ? t('system.integrations.new') : (integration?.name ?? '')"
                :description="t('system.integrations.description')"
            />

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <!-- Basics -->
                    <Collapsible v-model:open="openBasics">
                        <Card>
                            <CollapsibleTrigger class="flex w-full items-center justify-between px-6 py-4 text-left">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 items-center justify-center rounded-md bg-primary/10 text-primary">
                                        <Plug class="h-4 w-4" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold">
                                            {{ t('system.integrations.form.basics') }}
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ t('system.integrations.form.basics_hint') }}
                                        </p>
                                    </div>
                                </div>
                                <ChevronDown
                                    :class="[openBasics ? 'rotate-180' : '', 'h-4 w-4 transition-transform']"
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <CardContent class="space-y-4 border-t pt-4">
                                    <div class="grid gap-2">
                                        <Label for="name">{{ t('system.integrations.form.name') }}</Label>
                                        <Input
                                            id="name"
                                            v-model="form.name"
                                            :placeholder="t('system.integrations.form.name_placeholder')"
                                        />
                                        <InputError :message="form.errors.name" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="description">{{ t('system.integrations.form.description') }}</Label>
                                        <Textarea id="description" v-model="form.description" rows="2" />
                                        <InputError :message="form.errors.description" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="base_url">{{ t('system.integrations.form.base_url') }}</Label>
                                        <Input
                                            id="base_url"
                                            v-model="form.base_url"
                                            :placeholder="t('system.integrations.form.base_url_placeholder')"
                                        />
                                        <InputError :message="form.errors.base_url" />
                                    </div>
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>

                    <!-- Authentication -->
                    <Collapsible v-model:open="openAuth">
                        <Card>
                            <CollapsibleTrigger class="flex w-full items-center justify-between px-6 py-4 text-left">
                                <div>
                                    <p class="text-sm font-semibold">
                                        {{ t('system.integrations.form.authentication') }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ t('system.integrations.form.authentication_hint') }}
                                    </p>
                                </div>
                                <ChevronDown
                                    :class="[openAuth ? 'rotate-180' : '', 'h-4 w-4 transition-transform']"
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <CardContent class="space-y-4 border-t pt-4">
                                    <div class="grid gap-2">
                                        <Label>{{ t('system.integrations.form.auth_method') }}</Label>
                                        <Select v-model="form.auth_type">
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="option in authTypes"
                                                    :key="option.value"
                                                    :value="option.value"
                                                >
                                                    {{ option.label }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <AuthConfigField
                                        :auth-type="form.auth_type"
                                        :model-value="form.auth_config"
                                        :masked-values="integration?.masked_auth_config"
                                        @update:model-value="form.auth_config = $event"
                                    />

                                    <div class="flex flex-col gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="self-start"
                                            :disabled="testState.status === 'loading' || !form.base_url"
                                            @click="testConnection"
                                        >
                                            <Loader2
                                                v-if="testState.status === 'loading'"
                                                class="mr-2 h-4 w-4 animate-spin"
                                            />
                                            {{ testState.status === 'loading' ? t('system.integrations.testing') : t('system.integrations.test_now') }}
                                        </Button>
                                        <div
                                            v-if="testState.status === 'success'"
                                            class="flex items-start gap-2 rounded-md border border-green-200 bg-green-50 p-2 text-xs text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
                                        >
                                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>{{ testState.message }}</span>
                                        </div>
                                        <div
                                            v-else-if="testState.status === 'error'"
                                            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                                        >
                                            <XCircle class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>{{ testState.message }}</span>
                                        </div>
                                    </div>
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>

                    <!-- Default headers -->
                    <Collapsible v-model:open="openHeaders">
                        <Card>
                            <CollapsibleTrigger class="flex w-full items-center justify-between px-6 py-4 text-left">
                                <div>
                                    <p class="text-sm font-semibold">
                                        {{ t('system.integrations.form.default_headers') }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ t('system.integrations.form.default_headers_hint') }}
                                    </p>
                                </div>
                                <ChevronDown
                                    :class="[openHeaders ? 'rotate-180' : '', 'h-4 w-4 transition-transform']"
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <CardContent class="border-t pt-4">
                                    <HeaderEditor v-model="form.default_headers" />
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>

                    <!-- Visibility + advanced -->
                    <Collapsible v-model:open="openVisibility">
                        <Card>
                            <CollapsibleTrigger class="flex w-full items-center justify-between px-6 py-4 text-left">
                                <div>
                                    <p class="text-sm font-semibold">
                                        {{ t('system.integrations.form.visibility') }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ t('system.integrations.form.visibility_hint') }}
                                    </p>
                                </div>
                                <ChevronDown
                                    :class="[openVisibility ? 'rotate-180' : '', 'h-4 w-4 transition-transform']"
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <CardContent class="space-y-4 border-t pt-4">
                                    <div class="grid gap-2">
                                        <Select v-model="form.visibility">
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="option in visibilities"
                                                    :key="option.value"
                                                    :value="option.value"
                                                >
                                                    {{ option.label }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="flex items-start gap-2">
                                        <Checkbox
                                            id="allow_insecure_tls"
                                            :model-value="form.allow_insecure_tls"
                                            @update:model-value="form.allow_insecure_tls = $event === true"
                                        />
                                        <div>
                                            <Label for="allow_insecure_tls" class="cursor-pointer">
                                                {{ t('system.integrations.form.allow_insecure_tls') }}
                                            </Label>
                                            <p class="text-xs text-muted-foreground">
                                                {{ t('system.integrations.form.allow_insecure_tls_hint') }}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>

                    <div class="flex justify-end gap-2">
                        <Button variant="outline" as-child>
                            <Link href="/system/integrations">
                                {{ t('system.integrations.form.cancel') }}
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing || !form.name || !form.base_url">
                            {{ t('system.integrations.form.save') }}
                        </Button>
                    </div>
                </form>
        </div>
    </AppLayoutV2>
</template>
