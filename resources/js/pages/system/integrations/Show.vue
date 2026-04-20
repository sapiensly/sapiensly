<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ChevronRight,
    Pencil,
    Plug,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface Variable {
    id: string;
    key: string;
    value: string;
    is_secret: boolean;
    description: string | null;
}

interface Environment {
    id: string;
    name: string;
    sort_order: number;
    variables: Variable[];
}

interface RequestRow {
    id: string;
    name: string;
    folder: string | null;
    method: string;
    path: string;
    sort_order: number;
}

interface Integration {
    id: string;
    name: string;
    description: string | null;
    base_url: string;
    auth_type: string;
    visibility: string;
    status: string;
    color: string | null;
    icon: string | null;
    last_tested_at: string | null;
    last_test_status: string | null;
    last_test_message: string | null;
    allow_insecure_tls: boolean;
    active_environment_id: string | null;
    environments: Environment[];
    requests: RequestRow[];
    request_count: number;
}

const props = defineProps<{ integration: Integration }>();

const { t } = useI18n();

const activeTab = ref<'requests' | 'environments' | 'settings'>('requests');

// ============== Requests ==============
const newRequest = useForm({
    name: '',
    method: 'GET',
    path: '/',
});

function createRequest(): void {
    newRequest.post(`/system/integrations/${props.integration.id}/requests`, {
        preserveScroll: true,
        onSuccess: () => newRequest.reset(),
    });
}

function deleteRequest(requestId: string): void {
    if (!confirm(t('common.confirm_delete'))) return;
    router.delete(`/system/integrations/requests/${requestId}`, { preserveScroll: true });
}

// ============== Environments ==============
const newEnvironment = useForm({
    name: '',
});

function createEnvironment(): void {
    newEnvironment.post(`/system/integrations/${props.integration.id}/environments`, {
        preserveScroll: true,
        onSuccess: () => newEnvironment.reset(),
    });
}

function activateEnvironment(environmentId: string): void {
    router.post(
        `/system/integrations/environments/${environmentId}/activate`,
        {},
        { preserveScroll: true },
    );
}

function deleteEnvironment(environmentId: string): void {
    if (!confirm(t('common.confirm_delete'))) return;
    router.delete(`/system/integrations/environments/${environmentId}`, { preserveScroll: true });
}

// ============== Variables (per-environment) ==============
const newVar = ref<Record<string, { key: string; value: string; is_secret: boolean; description: string }>>({});

function blankVariable() {
    return { key: '', value: '', is_secret: false, description: '' };
}

function variableFor(envId: string) {
    if (!newVar.value[envId]) {
        newVar.value[envId] = blankVariable();
    }
    return newVar.value[envId];
}

function addVariable(envId: string): void {
    const draft = newVar.value[envId];
    if (!draft || !draft.key) return;
    router.post(
        `/system/integrations/environments/${envId}/variables`,
        draft,
        {
            preserveScroll: true,
            onSuccess: () => {
                newVar.value[envId] = blankVariable();
            },
        },
    );
}

function deleteVariable(variableId: string): void {
    router.delete(`/system/integrations/variables/${variableId}`, { preserveScroll: true });
}

// ============== Danger zone ==============
function destroyIntegration(): void {
    if (!confirm(t('system.integrations.delete_confirm'))) return;
    router.delete(`/system/integrations/${props.integration.id}`);
}
</script>

<template>
    <Head :title="integration.name" />

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="mx-auto max-w-5xl space-y-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue">
                            <Plug class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <PageHeader
                                    :title="integration.name"
                                    :description="integration.base_url"
                                />
                            </div>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <Badge variant="secondary" class="text-xs">
                                    {{ integration.auth_type }}
                                </Badge>
                                <Badge
                                    v-if="integration.last_test_status === 'success'"
                                    class="text-xs gap-1"
                                >
                                    <CheckCircle2 class="h-3 w-3" />
                                    {{ t('system.integrations.test_status.success') }}
                                </Badge>
                                <Badge
                                    v-else-if="integration.last_test_status === 'failure'"
                                    variant="destructive"
                                    class="text-xs gap-1"
                                >
                                    <XCircle class="h-3 w-3" />
                                    {{ t('system.integrations.test_status.failure') }}
                                </Badge>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="`/system/integrations/${integration.id}/edit`">
                                <Pencil class="mr-2 h-4 w-4" />
                                {{ t('common.edit') }}
                            </Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="`/system/integrations/${integration.id}/executions`">
                                {{ t('system.integrations.tabs.executions') }}
                            </Link>
                        </Button>
                    </div>
                </div>

                <Tabs v-model="activeTab">
                    <TabsList>
                        <TabsTrigger value="requests">
                            {{ t('system.integrations.tabs.requests') }}
                        </TabsTrigger>
                        <TabsTrigger value="environments">
                            {{ t('system.integrations.tabs.environments') }}
                        </TabsTrigger>
                        <TabsTrigger value="settings">
                            {{ t('system.integrations.tabs.settings') }}
                        </TabsTrigger>
                    </TabsList>

                    <!-- Requests tab -->
                    <TabsContent value="requests" class="mt-4">
                        <Card>
                            <CardContent class="space-y-4 pt-6">
                                <form
                                    class="flex items-center gap-2"
                                    @submit.prevent="createRequest"
                                >
                                    <select
                                        v-model="newRequest.method"
                                        class="h-9 rounded-md border bg-background px-2 text-sm"
                                    >
                                        <option v-for="m in ['GET','POST','PUT','PATCH','DELETE']" :key="m">
                                            {{ m }}
                                        </option>
                                    </select>
                                    <Input
                                        v-model="newRequest.name"
                                        :placeholder="t('system.integrations.show.add_request')"
                                        class="flex-1"
                                    />
                                    <Input
                                        v-model="newRequest.path"
                                        placeholder="/users/{{id}}"
                                        class="flex-1"
                                    />
                                    <Button
                                        type="submit"
                                        :disabled="newRequest.processing || !newRequest.name"
                                    >
                                        <Plus class="mr-2 h-4 w-4" />
                                        {{ t('system.integrations.show.add_request') }}
                                    </Button>
                                </form>

                                <p
                                    v-if="integration.requests.length === 0"
                                    class="py-6 text-center text-sm text-muted-foreground"
                                >
                                    {{ t('system.integrations.show.no_requests') }}
                                </p>

                                <div v-else class="divide-y rounded-md border">
                                    <div
                                        v-for="req in integration.requests"
                                        :key="req.id"
                                        class="flex items-center gap-3 px-4 py-3"
                                    >
                                        <Badge variant="outline" class="w-16 justify-center text-xs">
                                            {{ req.method }}
                                        </Badge>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium">{{ req.name }}</p>
                                            <p class="truncate text-xs text-muted-foreground">
                                                {{ req.path }}
                                            </p>
                                        </div>
                                        <Button variant="ghost" size="icon" as-child>
                                            <Link :href="`/system/integrations/requests/${req.id}`">
                                                <ChevronRight class="h-4 w-4" />
                                            </Link>
                                        </Button>
                                        <Button variant="ghost" size="icon" @click="deleteRequest(req.id)">
                                            <Trash2 class="h-4 w-4 text-destructive" />
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <!-- Environments tab -->
                    <TabsContent value="environments" class="mt-4">
                        <Card>
                            <CardContent class="space-y-4 pt-6">
                                <form
                                    class="flex items-center gap-2"
                                    @submit.prevent="createEnvironment"
                                >
                                    <Input
                                        v-model="newEnvironment.name"
                                        :placeholder="t('system.integrations.show.new_environment')"
                                        class="flex-1"
                                    />
                                    <Button
                                        type="submit"
                                        :disabled="newEnvironment.processing || !newEnvironment.name"
                                    >
                                        <Plus class="mr-2 h-4 w-4" />
                                        {{ t('system.integrations.show.new_environment') }}
                                    </Button>
                                </form>

                                <div class="space-y-4">
                                    <div
                                        v-for="env in integration.environments"
                                        :key="env.id"
                                        class="space-y-3 rounded-md border p-4"
                                    >
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-semibold">{{ env.name }}</p>
                                                <Badge
                                                    v-if="env.id === integration.active_environment_id"
                                                    class="text-xs"
                                                >
                                                    {{ t('system.integrations.show.active_environment') }}
                                                </Badge>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <Button
                                                    v-if="env.id !== integration.active_environment_id"
                                                    variant="outline"
                                                    size="sm"
                                                    @click="activateEnvironment(env.id)"
                                                >
                                                    {{ t('system.integrations.show.activate') }}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    @click="deleteEnvironment(env.id)"
                                                >
                                                    <Trash2 class="h-4 w-4 text-destructive" />
                                                </Button>
                                            </div>
                                        </div>

                                        <div
                                            v-if="env.variables.length > 0"
                                            class="divide-y rounded-md border bg-background"
                                        >
                                            <div
                                                v-for="variable in env.variables"
                                                :key="variable.id"
                                                class="flex items-center gap-2 px-3 py-2 text-xs"
                                            >
                                                <code class="font-mono font-medium">{{ variable.key }}</code>
                                                <span class="flex-1 truncate text-muted-foreground">
                                                    {{ variable.value }}
                                                </span>
                                                <Badge v-if="variable.is_secret" variant="outline" class="text-[10px]">
                                                    {{ t('system.integrations.show.variable_secret') }}
                                                </Badge>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    @click="deleteVariable(variable.id)"
                                                >
                                                    <Trash2 class="h-3 w-3 text-destructive" />
                                                </Button>
                                            </div>
                                        </div>

                                        <form
                                            class="grid grid-cols-[1fr_1fr_auto_auto] items-end gap-2"
                                            @submit.prevent="addVariable(env.id)"
                                        >
                                            <Input
                                                :model-value="variableFor(env.id).key"
                                                :placeholder="t('system.integrations.show.variable_key')"
                                                @update:model-value="variableFor(env.id).key = String($event)"
                                            />
                                            <Input
                                                :model-value="variableFor(env.id).value"
                                                :placeholder="t('system.integrations.show.variable_value')"
                                                @update:model-value="variableFor(env.id).value = String($event)"
                                            />
                                            <div class="flex items-center gap-1">
                                                <Checkbox
                                                    :model-value="variableFor(env.id).is_secret"
                                                    @update:model-value="variableFor(env.id).is_secret = $event === true"
                                                />
                                                <Label class="text-xs">
                                                    {{ t('system.integrations.show.variable_secret') }}
                                                </Label>
                                            </div>
                                            <Button type="submit" size="sm">
                                                {{ t('system.integrations.show.add_variable') }}
                                            </Button>
                                        </form>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <!-- Settings tab -->
                    <TabsContent value="settings" class="mt-4">
                        <Card>
                            <CardContent class="space-y-4 pt-6">
                                <div>
                                    <h4 class="mb-1 text-sm font-semibold text-destructive">
                                        {{ t('common.delete') }}
                                    </h4>
                                    <p class="mb-3 text-xs text-muted-foreground">
                                        {{ t('system.integrations.delete_confirm') }}
                                    </p>
                                    <Button variant="destructive" @click="destroyIntegration">
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        {{ t('common.delete') }}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
        </div>
    </AppLayoutV2>
</template>
