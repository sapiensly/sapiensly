<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import axios from 'axios';
import { AlertTriangle, CheckCircle2, Database, Loader2, XCircle } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface InspectResponse {
    configured: boolean;
    reachable?: boolean;
    driver?: string;
    has_extension?: boolean;
    has_schema?: boolean;
    chunk_count?: number;
    error?: string;
    message?: string;
}

interface InstallResponse {
    success: boolean;
    message?: string;
    detail?: string;
    instructions?: string;
    schema_error?: string;
    state?: {
        reachable: boolean;
        has_extension: boolean;
        has_schema: boolean;
        driver: string;
    };
}

interface Props {
    inspectUrl: string;
    installUrl: string;
    /**
     * Key namespace for i18n strings. Must be either 'admin.global_cloud' or
     * 'system.cloud_providers' — the component reads `{namespace}.vector.*`.
     */
    i18nNamespace: 'admin.global_cloud' | 'system.cloud_providers';
    /**
     * Bumps when the parent saves a new provider so the widget re-inspects.
     */
    refreshToken?: number;
}

const props = withDefaults(defineProps<Props>(), {
    refreshToken: 0,
});

const { t } = useI18n();

const tk = (key: string, params?: Record<string, unknown>): string =>
    t(`${props.i18nNamespace}.vector.${key}`, params ?? {});

type InspectState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'unconfigured' }
    | {
          status: 'ready';
          reachable: boolean;
          hasExtension: boolean;
          hasSchema: boolean;
          chunkCount: number;
          error?: string;
      };

type InstallState =
    | { status: 'idle' }
    | { status: 'loading' }
    | {
          status: 'success';
          message: string;
      }
    | {
          status: 'error';
          message: string;
          detail?: string;
          instructions?: string;
      };

const inspectState = ref<InspectState>({ status: 'idle' });
const installState = ref<InstallState>({ status: 'idle' });

async function inspect(): Promise<void> {
    inspectState.value = { status: 'loading' };
    try {
        const { data } = await axios.post<InspectResponse>(props.inspectUrl);

        if (!data.configured) {
            inspectState.value = { status: 'unconfigured' };
            return;
        }

        inspectState.value = {
            status: 'ready',
            reachable: data.reachable ?? false,
            hasExtension: data.has_extension ?? false,
            hasSchema: data.has_schema ?? false,
            chunkCount: data.chunk_count ?? 0,
            error: data.error,
        };
    } catch {
        inspectState.value = {
            status: 'ready',
            reachable: false,
            hasExtension: false,
            hasSchema: false,
            chunkCount: 0,
        };
    }
}

async function install(): Promise<void> {
    installState.value = { status: 'loading' };
    try {
        const { data } = await axios.post<InstallResponse>(props.installUrl);

        if (data.success) {
            installState.value = {
                status: 'success',
                message: data.message || tk('install_success'),
            };
            // Refresh inspection after install so badges update.
            await inspect();
            return;
        }

        installState.value = {
            status: 'error',
            message: data.message || tk('install_failed'),
            detail: data.detail,
            instructions: data.instructions || tk('manual_instructions'),
        };

        if (data.state) {
            inspectState.value = {
                status: 'ready',
                reachable: data.state.reachable,
                hasExtension: data.state.has_extension,
                hasSchema: data.state.has_schema,
                chunkCount: (data.state as { chunk_count?: number }).chunk_count ?? 0,
            };
        }
    } catch (e: unknown) {
        const err = e as {
            response?: { data?: { message?: string; detail?: string } };
        };
        installState.value = {
            status: 'error',
            message: err.response?.data?.message || tk('install_failed'),
            detail: err.response?.data?.detail,
            instructions: tk('manual_instructions'),
        };
    }
}

onMounted(() => {
    inspect();
});

watch(
    () => props.refreshToken,
    () => {
        inspect();
    },
);
</script>

<template>
    <section class="rounded-lg border p-5">
        <div class="mb-4 flex items-start gap-3">
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
            >
                <Database class="h-5 w-5" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-base font-semibold">{{ tk('heading') }}</h3>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="inspectState.status === 'loading'"
                        @click="inspect"
                    >
                        <Loader2
                            v-if="inspectState.status === 'loading'"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        {{
                            inspectState.status === 'loading'
                                ? tk('checking')
                                : tk('check')
                        }}
                    </Button>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ tk('description') }}
                </p>
            </div>
        </div>

        <div
            v-if="inspectState.status === 'ready' && !inspectState.reachable"
            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
        >
            <XCircle class="mt-0.5 h-4 w-4 shrink-0" />
            <div class="min-w-0 flex-1">
                <p>{{ tk('unreachable') }}</p>
                <p v-if="inspectState.error" class="mt-1 break-words opacity-80">
                    {{ inspectState.error }}
                </p>
            </div>
        </div>

        <div
            v-else-if="inspectState.status === 'ready'"
            class="space-y-3"
        >
            <div class="flex flex-wrap gap-2">
                <Badge
                    :variant="inspectState.hasExtension ? 'default' : 'destructive'"
                    class="gap-1"
                >
                    <CheckCircle2
                        v-if="inspectState.hasExtension"
                        class="h-3 w-3"
                    />
                    <XCircle v-else class="h-3 w-3" />
                    {{
                        inspectState.hasExtension
                            ? tk('extension_installed')
                            : tk('extension_missing')
                    }}
                </Badge>

                <Badge
                    :variant="inspectState.hasSchema ? 'default' : 'secondary'"
                    class="gap-1"
                >
                    <CheckCircle2
                        v-if="inspectState.hasSchema"
                        class="h-3 w-3"
                    />
                    <AlertTriangle v-else class="h-3 w-3" />
                    {{
                        inspectState.hasSchema
                            ? tk('schema_ready')
                            : tk('schema_missing')
                    }}
                </Badge>

                <Badge
                    v-if="inspectState.hasSchema"
                    variant="secondary"
                    class="gap-1"
                >
                    {{
                        tk('chunk_count_label', {
                            count: inspectState.chunkCount.toLocaleString(),
                        })
                    }}
                </Badge>
            </div>

            <div
                v-if="!inspectState.hasExtension || !inspectState.hasSchema"
                class="flex flex-col gap-2"
            >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    :disabled="installState.status === 'loading'"
                    @click="install"
                >
                    <Loader2
                        v-if="installState.status === 'loading'"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    {{
                        installState.status === 'loading'
                            ? tk('installing')
                            : tk('install_button')
                    }}
                </Button>

                <div
                    v-if="installState.status === 'success'"
                    class="flex items-start gap-2 rounded-md border border-green-200 bg-green-50 p-2 text-xs text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
                >
                    <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ installState.message }}</span>
                </div>

                <div
                    v-else-if="installState.status === 'error'"
                    class="flex flex-col gap-1 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
                >
                    <div class="flex items-start gap-2">
                        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span class="font-medium">{{ installState.message }}</span>
                    </div>
                    <p
                        v-if="installState.detail"
                        class="break-words pl-6 opacity-80"
                    >
                        {{ installState.detail }}
                    </p>
                    <p
                        v-if="installState.instructions"
                        class="rounded bg-amber-100/50 px-2 py-1 font-mono text-[11px] dark:bg-amber-950/50"
                    >
                        {{ installState.instructions }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
