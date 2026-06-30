<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { DatabaseConfig, HttpConnectionOption } from '@/types/tools';
import { ExternalLink, Info, Plug } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        config: DatabaseConfig;
        errors: Record<string, string>;
        connections?: HttpConnectionOption[];
    }>(),
    { connections: () => [] },
);

const emit = defineEmits<{
    'update:config': [config: DatabaseConfig];
}>();

const updateField = <K extends keyof DatabaseConfig>(
    field: K,
    value: DatabaseConfig[K],
) => {
    emit('update:config', { ...props.config, [field]: value });
};

// A database tool either borrows its driver/host/credentials from a Connection
// (the encouraged path) or carries them inline (legacy).
const INLINE = '__inline__';
const isConnected = computed(() => !!props.config.integration_id);

const connectionValue = computed({
    get: () => props.config.integration_id || INLINE,
    set: (value: string) => {
        if (value === INLINE) {
            const next = { ...props.config };
            delete next.integration_id;
            next.driver = next.driver ?? 'pgsql';
            next.read_only = next.read_only ?? true;
            emit('update:config', next);
        } else {
            const next = { ...props.config, integration_id: value };
            delete next.driver;
            delete next.host;
            delete next.port;
            delete next.database;
            delete next.username;
            delete next.password;
            emit('update:config', next);
        }
    },
});

const driver = computed({
    get: () => props.config.driver ?? 'pgsql',
    set: (value: string) =>
        updateField('driver', value as DatabaseConfig['driver']),
});
const host = computed({
    get: () => props.config.host ?? '',
    set: (value: string) => updateField('host', value),
});
const port = computed({
    get: () => props.config.port ?? defaultPort.value,
    set: (value: number) => updateField('port', value),
});
const database = computed({
    get: () => props.config.database ?? '',
    set: (value: string) => updateField('database', value),
});
const username = computed({
    get: () => props.config.username ?? '',
    set: (value: string) => updateField('username', value),
});
const password = computed({
    get: () => props.config.password ?? '',
    set: (value: string) => updateField('password', value),
});
const queryTemplate = computed({
    get: () => props.config.query_template ?? '',
    set: (value: string) => updateField('query_template', value),
});
const readOnly = computed({
    get: () => props.config.read_only ?? true,
    set: (value: boolean) => updateField('read_only', value),
});

const driverOptions = [
    { value: 'pgsql', label: t('tools.config.database.postgresql'), defaultPort: 5432 },
    { value: 'mysql', label: t('tools.config.database.mysql'), defaultPort: 3306 },
    { value: 'sqlite', label: t('tools.config.database.sqlite'), defaultPort: null },
    { value: 'sqlsrv', label: 'SQL Server', defaultPort: 1433 },
];

const defaultPort = computed(() => {
    const option = driverOptions.find((o) => o.value === driver.value);
    return option?.defaultPort ?? 5432;
});

const requiresConnection = computed(() => driver.value !== 'sqlite');

const queryPlaceholder = `SELECT o.id, o.status, o.total, c.name as customer_name
FROM orders o
JOIN customers c ON o.customer_id = c.id
WHERE o.id = :order_id`;
</script>

<template>
    <div class="space-y-4">
        <p
            class="flex items-start gap-2 rounded-xs border border-soft bg-white/[0.02] p-2.5 text-[11px] leading-snug text-ink-muted"
        >
            <Info class="mt-px size-3.5 shrink-0 text-ink-subtle" />
            <span>{{ t('tools.config.database.guidance') }}</span>
        </p>

        <!-- Connection: the encouraged path. When set, driver/host/credentials
             come from the connection. -->
        <div class="grid gap-2">
            <Label for="db-connection">{{ t('tools.config.connection.label') }}</Label>
            <Select v-if="connections.length > 0" v-model="connectionValue">
                <SelectTrigger id="db-connection">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="c in connections" :key="c.id" :value="c.id">
                        {{ c.name }} — {{ c.base_url }}
                    </SelectItem>
                    <SelectItem :value="INLINE">
                        {{ t('tools.config.connection.inline') }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <div
                v-else
                class="flex items-center justify-between gap-3 rounded-xs border border-dashed border-soft p-3"
            >
                <p class="text-xs text-ink-muted">
                    {{ t('tools.config.connection.none') }}
                </p>
                <a
                    href="/system/integrations/create?kind=database"
                    class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-accent-blue hover:underline"
                >
                    <ExternalLink class="size-3.5" />
                    {{ t('tools.config.connection.create') }}
                </a>
            </div>
            <p class="text-xs text-ink-muted">{{ t('tools.config.connection.db_hint') }}</p>
            <InputError :message="errors['config.integration_id']" />
        </div>

        <div
            v-if="isConnected"
            class="flex items-start gap-2 rounded-xs border border-dashed border-soft p-3"
        >
            <Plug class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
            <p class="text-xs text-ink-muted">{{ t('tools.config.connection.db_inherits') }}</p>
        </div>

        <!-- Inline DSN (legacy / no connection). -->
        <template v-if="!isConnected">
            <div class="grid gap-2">
                <Label for="driver">{{ t('tools.config.database.driver') }}</Label>
                <Select v-model="driver">
                    <SelectTrigger id="driver">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in driverOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors['config.driver']" />
            </div>

            <div v-if="requiresConnection" class="grid grid-cols-3 gap-4">
                <div class="col-span-2 grid gap-2">
                    <Label for="host">{{ t('tools.config.database.host') }}</Label>
                    <Input id="host" v-model="host" placeholder="localhost" class="font-mono" />
                    <InputError :message="errors['config.host']" />
                </div>
                <div class="grid gap-2">
                    <Label for="port">{{ t('tools.config.database.port') }}</Label>
                    <Input id="port" v-model.number="port" type="number" :placeholder="String(defaultPort)" />
                    <InputError :message="errors['config.port']" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="database">
                    {{ driver === 'sqlite' ? t('tools.config.database.file_path') : t('tools.config.database.name') }}
                </Label>
                <Input
                    id="database"
                    v-model="database"
                    :placeholder="driver === 'sqlite' ? '/path/to/database.sqlite' : 'my_database'"
                    class="font-mono"
                />
                <InputError :message="errors['config.database']" />
            </div>

            <div v-if="requiresConnection" class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="username">{{ t('tools.config.database.username') }}</Label>
                    <Input id="username" v-model="username" placeholder="db_user" autocomplete="off" />
                    <InputError :message="errors['config.username']" />
                </div>
                <div class="grid gap-2">
                    <Label for="password">{{ t('tools.config.database.password') }}</Label>
                    <Input id="password" v-model="password" type="password" placeholder="********" autocomplete="new-password" />
                    <p class="text-xs text-ink-muted">{{ t('tools.config.database.encrypted') }}</p>
                    <InputError :message="errors['config.password']" />
                </div>
            </div>
        </template>

        <!-- The action: query template + safety, on every database tool. -->
        <div class="grid gap-2">
            <Label for="query-template">{{ t('tools.config.database.query') }}</Label>
            <Textarea
                id="query-template"
                v-model="queryTemplate"
                :placeholder="queryPlaceholder"
                class="min-h-[150px] font-mono text-sm"
            />
            <p class="text-xs text-ink-muted">
                {{ t('tools.config.database.query_hint_pre') }}
                <code class="rounded bg-white/[0.06] px-1">:param_name</code>
                {{ t('tools.config.database.query_hint_post') }}
            </p>
            <InputError :message="errors['config.query_template']" />
        </div>

        <div class="flex items-center space-x-2">
            <Checkbox id="read-only" v-model="readOnly" />
            <Label for="read-only" class="cursor-pointer font-normal">
                {{ t('tools.config.database.read_only') }}
            </Label>
        </div>
        <p class="text-xs text-ink-muted">{{ t('tools.config.database.read_only_hint') }}</p>
    </div>
</template>
