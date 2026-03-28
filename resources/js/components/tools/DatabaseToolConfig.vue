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
import type { DatabaseConfig } from '@/types/tools';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: DatabaseConfig;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: DatabaseConfig];
}>();

const updateField = <K extends keyof DatabaseConfig>(
    field: K,
    value: DatabaseConfig[K],
) => {
    emit('update:config', {
        ...props.config,
        [field]: value,
    });
};

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
    {
        value: 'pgsql',
        label: t('tools.config.database.postgresql'),
        defaultPort: 5432,
    },
    {
        value: 'mysql',
        label: t('tools.config.database.mysql'),
        defaultPort: 3306,
    },
    {
        value: 'sqlite',
        label: t('tools.config.database.sqlite'),
        defaultPort: null,
    },
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
        <div class="grid gap-2">
            <Label for="driver">Database Driver</Label>
            <Select v-model="driver">
                <SelectTrigger id="driver">
                    <SelectValue placeholder="Select database" />
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

        <template v-if="requiresConnection">
            <div class="grid grid-cols-3 gap-4">
                <div class="col-span-2 grid gap-2">
                    <Label for="host">Host</Label>
                    <Input
                        id="host"
                        v-model="host"
                        placeholder="localhost"
                        class="font-mono"
                    />
                    <InputError :message="errors['config.host']" />
                </div>
                <div class="grid gap-2">
                    <Label for="port">Port</Label>
                    <Input
                        id="port"
                        v-model.number="port"
                        type="number"
                        :placeholder="String(defaultPort)"
                    />
                    <InputError :message="errors['config.port']" />
                </div>
            </div>
        </template>

        <div class="grid gap-2">
            <Label for="database">
                {{
                    driver === 'sqlite' ? 'Database File Path' : 'Database Name'
                }}
            </Label>
            <Input
                id="database"
                v-model="database"
                :placeholder="
                    driver === 'sqlite'
                        ? '/path/to/database.sqlite'
                        : 'my_database'
                "
                class="font-mono"
            />
            <InputError :message="errors['config.database']" />
        </div>

        <template v-if="requiresConnection">
            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="username">Username</Label>
                    <Input
                        id="username"
                        v-model="username"
                        placeholder="db_user"
                        autocomplete="off"
                    />
                    <InputError :message="errors['config.username']" />
                </div>
                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        v-model="password"
                        type="password"
                        placeholder="********"
                        autocomplete="new-password"
                    />
                    <p class="text-xs text-muted-foreground">
                        Encrypted at rest
                    </p>
                    <InputError :message="errors['config.password']" />
                </div>
            </div>
        </template>

        <div class="grid gap-2">
            <Label for="query-template">SQL Query Template</Label>
            <Textarea
                id="query-template"
                v-model="queryTemplate"
                :placeholder="queryPlaceholder"
                class="min-h-[150px] font-mono text-sm"
            />
            <p class="text-xs text-muted-foreground">
                Use named parameters like
                <code class="rounded bg-muted px-1">:param_name</code> for safe
                value injection
            </p>
            <InputError :message="errors['config.query_template']" />
        </div>

        <div class="flex items-center space-x-2">
            <Checkbox id="read-only" v-model:checked="readOnly" />
            <Label for="read-only" class="cursor-pointer font-normal">
                Read-only mode (recommended for safety)
            </Label>
        </div>
        <p class="text-xs text-muted-foreground">
            When enabled, only SELECT queries are allowed. Disable to allow
            INSERT, UPDATE, DELETE operations.
        </p>
    </div>
</template>
