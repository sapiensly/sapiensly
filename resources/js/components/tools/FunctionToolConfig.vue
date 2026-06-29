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
import type { FunctionConfig } from '@/types/tools';
import { Braces, Info, Plus, SlidersHorizontal, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    config: FunctionConfig;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:config': [config: FunctionConfig];
}>();

interface ParamRow {
    name: string;
    type: string;
    description: string;
    required: boolean;
}

interface JsonSchema {
    type: string;
    properties?: Record<string, { type?: string; description?: string }>;
    required?: string[];
}

const paramTypes = ['string', 'number', 'integer', 'boolean', 'array', 'object'];

function rowsFromSchema(schema: unknown): ParamRow[] {
    const s = (schema ?? {}) as JsonSchema;
    const properties = s.properties ?? {};
    const required = Array.isArray(s.required) ? s.required : [];
    return Object.entries(properties).map(([name, def]) => ({
        name,
        type: def?.type ?? 'string',
        description: def?.description ?? '',
        required: required.includes(name),
    }));
}

function schemaFromRows(rows: ParamRow[]): JsonSchema {
    const properties: NonNullable<JsonSchema['properties']> = {};
    const required: string[] = [];
    for (const row of rows) {
        const name = row.name.trim();
        if (!name) continue;
        properties[name] = {
            type: row.type,
            ...(row.description ? { description: row.description } : {}),
        };
        if (row.required) required.push(name);
    }
    return { type: 'object', properties, ...(required.length ? { required } : {}) };
}

// The builder is the source of truth while it's open; we seed it once from the
// incoming schema and write the schema back on every edit (no re-derive, so no
// feedback loop with the parent's config object).
const rows = ref<ParamRow[]>(rowsFromSchema(props.config.parameters));

// A schema the builder can't fully represent (nested objects, array items, …)
// opens straight into JSON mode so nothing is silently flattened.
function schemaIsAdvanced(schema: unknown): boolean {
    const s = (schema ?? {}) as JsonSchema;
    if (!s.properties) return false;
    return Object.values(s.properties).some((def) =>
        Object.keys(def ?? {}).some((k) => k !== 'type' && k !== 'description'),
    );
}

const mode = ref<'builder' | 'json'>(
    schemaIsAdvanced(props.config.parameters) ? 'json' : 'builder',
);

const functionName = computed({
    get: () => props.config.name ?? '',
    set: (value: string) => emit('update:config', { ...props.config, name: value }),
});

const functionDescription = computed({
    get: () => props.config.description ?? '',
    set: (value: string) =>
        emit('update:config', { ...props.config, description: value }),
});

function applyRows(): void {
    emit('update:config', {
        ...props.config,
        parameters: schemaFromRows(rows.value),
    });
}

watch(rows, applyRows, { deep: true });

function addParam(): void {
    rows.value.push({ name: '', type: 'string', description: '', required: false });
}

function removeParam(index: number): void {
    rows.value.splice(index, 1);
}

const parametersJson = computed({
    get: () => {
        const params = props.config.parameters;
        return params ? JSON.stringify(params, null, 2) : '';
    },
    set: (value: string) => {
        try {
            emit('update:config', {
                ...props.config,
                parameters: JSON.parse(value),
            });
        } catch {
            // Invalid JSON — keep the last good value.
        }
    },
});

function switchTo(next: 'builder' | 'json'): void {
    // Re-seed the builder from the current schema so JSON edits carry over.
    if (next === 'builder') {
        rows.value = rowsFromSchema(props.config.parameters);
    }
    mode.value = next;
}
</script>

<template>
    <div class="space-y-4">
        <p
            class="flex items-start gap-2 rounded-xs border border-soft bg-white/[0.02] p-2.5 text-[11px] leading-snug text-ink-muted"
        >
            <Info class="mt-px size-3.5 shrink-0 text-ink-subtle" />
            <span>{{ t('tools.config.function.guidance') }}</span>
        </p>

        <div class="grid gap-2">
            <Label for="function-name">{{
                t('tools.config.function.name')
            }}</Label>
            <Input
                id="function-name"
                v-model="functionName"
                :placeholder="t('tools.config.function.name_placeholder')"
                class="font-mono"
            />
            <p class="text-xs text-ink-muted">
                {{ t('tools.config.function.name_description') }}
            </p>
            <InputError :message="errors['config.name']" />
        </div>

        <div class="grid gap-2">
            <Label for="function-description">{{
                t('tools.config.function.description')
            }}</Label>
            <Textarea
                id="function-description"
                v-model="functionDescription"
                :placeholder="t('tools.config.function.description_example')"
                rows="2"
            />
            <p class="text-xs text-ink-muted">
                {{ t('tools.config.function.description_hint') }}
            </p>
            <InputError :message="errors['config.description']" />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between gap-2">
                <Label>{{ t('tools.config.function.parameters') }}</Label>
                <!-- Builder ⇄ JSON: the builder covers flat params; JSON is the
                     escape hatch for nested schemas. -->
                <div
                    class="inline-flex items-center gap-0.5 rounded-pill border border-soft bg-white/[0.03] p-0.5"
                >
                    <button
                        type="button"
                        :class="[
                            'inline-flex items-center gap-1 rounded-pill px-2.5 py-1 text-[11px] font-medium transition-colors',
                            mode === 'builder'
                                ? 'bg-accent-blue text-white shadow-btn-primary'
                                : 'text-ink-muted hover:text-ink',
                        ]"
                        @click="switchTo('builder')"
                    >
                        <SlidersHorizontal class="size-3" />
                        {{ t('tools.config.function.mode_builder') }}
                    </button>
                    <button
                        type="button"
                        :class="[
                            'inline-flex items-center gap-1 rounded-pill px-2.5 py-1 text-[11px] font-medium transition-colors',
                            mode === 'json'
                                ? 'bg-accent-blue text-white shadow-btn-primary'
                                : 'text-ink-muted hover:text-ink',
                        ]"
                        @click="switchTo('json')"
                    >
                        <Braces class="size-3" />
                        {{ t('tools.config.function.mode_json') }}
                    </button>
                </div>
            </div>

            <!-- Visual builder. -->
            <template v-if="mode === 'builder'">
                <div
                    v-if="rows.length === 0"
                    class="rounded-xs border border-dashed border-soft bg-white/[0.02] px-4 py-6 text-center"
                >
                    <p class="text-xs text-ink-muted">
                        {{ t('tools.config.function.no_params') }}
                    </p>
                </div>

                <div v-else class="space-y-2">
                    <!-- Column headers. -->
                    <div
                        class="grid grid-cols-[1fr_120px_1.4fr_auto_auto] items-center gap-2 px-1 text-[10px] font-semibold uppercase tracking-wider text-ink-faint"
                    >
                        <span>{{ t('tools.config.function.param_name') }}</span>
                        <span>{{ t('tools.config.function.param_type') }}</span>
                        <span>{{ t('tools.config.function.param_description') }}</span>
                        <span>{{ t('tools.config.function.param_required') }}</span>
                        <span class="sr-only">{{ t('common.remove') }}</span>
                    </div>

                    <div
                        v-for="(row, index) in rows"
                        :key="index"
                        class="grid grid-cols-[1fr_120px_1.4fr_auto_auto] items-center gap-2"
                    >
                        <Input
                            v-model="row.name"
                            :placeholder="t('tools.config.function.param_name_placeholder')"
                            class="h-9 font-mono text-xs"
                        />
                        <Select v-model="row.type">
                            <SelectTrigger class="h-9">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="type in paramTypes"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ type }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Input
                            v-model="row.description"
                            :placeholder="t('tools.config.function.param_description_placeholder')"
                            class="h-9 text-xs"
                        />
                        <label
                            class="inline-flex items-center justify-center"
                            :title="t('tools.config.function.param_required')"
                        >
                            <Checkbox
                                :model-value="row.required"
                                @update:model-value="row.required = $event === true"
                            />
                        </label>
                        <button
                            type="button"
                            :title="t('common.remove')"
                            class="inline-flex size-9 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-sp-danger/10 hover:text-sp-danger"
                            @click="removeParam(index)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>

                <button
                    type="button"
                    class="inline-flex w-fit items-center gap-1.5 rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                    @click="addParam"
                >
                    <Plus class="size-3.5" />
                    {{ t('tools.config.function.add_param') }}
                </button>
                <InputError :message="errors['config.parameters']" />
            </template>

            <!-- JSON escape hatch. -->
            <template v-else>
                <Textarea
                    v-model="parametersJson"
                    rows="10"
                    class="font-mono text-sm"
                    placeholder='{
  "type": "object",
  "properties": {
    "location": { "type": "string", "description": "The city and state" }
  },
  "required": ["location"]
}'
                />
                <p class="text-xs text-ink-muted">
                    {{ t('tools.config.function.json_hint') }}
                </p>
                <InputError :message="errors['config.parameters']" />
            </template>
        </div>
    </div>
</template>
