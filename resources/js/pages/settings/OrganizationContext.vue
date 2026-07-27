<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DraftConflicts from '@/components/admin/DraftConflicts.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    BookOpen,
    Building2,
    Eye,
    Globe,
    Link2,
    Loader2,
    MessageSquare,
    Package,
    Plus,
    ShieldAlert,
    Wand2,
    X,
} from '@lucide/vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';

interface Pair {
    [key: string]: string;
}

interface Context {
    descriptor: string | null;
    industry: string | null;
    size: string | null;
    website: string | null;
    audience: string | null;
    geographies: string[];
    timezone: string | null;
    currency: string | null;
    units: string | null;
    language: string | null;
    formality: string | null;
    tone_notes: string | null;
    glossary: Pair[];
    offerings: Pair[];
    never: string[];
    escalation: string | null;
    disclaimer: string | null;
    links: Pair[];
}

const props = defineProps<{
    context: Context;
    enabled: boolean;
    preview: string;
    tokens: number;
    maxTokens: number;
    formalityOptions: string[];
    unitOptions: string[];
    updatedAt: string | null;
}>();

const { t } = useI18n();

const form = useForm({
    descriptor: props.context.descriptor ?? '',
    industry: props.context.industry ?? '',
    size: props.context.size ?? '',
    website: props.context.website ?? '',
    audience: props.context.audience ?? '',
    geographies: [...props.context.geographies],
    timezone: props.context.timezone ?? '',
    currency: props.context.currency ?? '',
    units: props.context.units ?? '',
    language: props.context.language ?? '',
    formality: props.context.formality ?? '',
    tone_notes: props.context.tone_notes ?? '',
    glossary: props.context.glossary.map((e) => ({ ...e })),
    offerings: props.context.offerings.map((e) => ({ ...e })),
    never: [...props.context.never],
    escalation: props.context.escalation ?? '',
    disclaimer: props.context.disclaimer ?? '',
    links: props.context.links.map((e) => ({ ...e })),
    enabled: props.enabled,
});

/** Common IANA zones first; the field stays free-text for anything else. */
const TIMEZONE_SUGGESTIONS = [
    'UTC',
    'America/Mexico_City',
    'America/Bogota',
    'America/Santiago',
    'America/Buenos_Aires',
    'America/New_York',
    'America/Los_Angeles',
    'Europe/Madrid',
    'Europe/London',
    'Europe/Berlin',
    'Asia/Tokyo',
    'Australia/Sydney',
];

function addRow(list: Pair[], keys: string[]): void {
    list.push(Object.fromEntries(keys.map((k) => [k, ''])));
}

function removeAt<T>(list: T[], index: number): void {
    list.splice(index, 1);
}

/**
 * The shape the server expects: blanks become null, and half-filled rows are
 * dropped rather than persisted as noise.
 */
function payload(): Record<string, unknown> {
    const blank = (v: string) => (v.trim() === '' ? null : v.trim());
    const rows = (list: Pair[], required: string) =>
        list.filter((row) => (row[required] ?? '').trim() !== '');

    return {
        descriptor: blank(form.descriptor),
        industry: blank(form.industry),
        size: blank(form.size),
        website: blank(form.website),
        audience: blank(form.audience),
        geographies: form.geographies.filter((g) => g.trim() !== ''),
        timezone: blank(form.timezone),
        currency: blank(form.currency),
        units: blank(form.units),
        language: blank(form.language),
        formality: blank(form.formality),
        tone_notes: blank(form.tone_notes),
        glossary: rows(form.glossary, 'term'),
        offerings: rows(form.offerings, 'name'),
        never: form.never.filter((n) => n.trim() !== ''),
        escalation: blank(form.escalation),
        disclaimer: blank(form.disclaimer),
        links: rows(form.links, 'url'),
        enabled: form.enabled,
    };
}

// Live preview of the exact block the models will read, rendered server-side so
// there is one renderer, not two that can drift.
const preview = ref(props.preview);
const tokens = ref(props.tokens);
const previewing = ref(false);
let previewTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => payload(),
    () => {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(async () => {
            previewing.value = true;
            try {
                const { data } = await axios.post(
                    '/settings/organization/context/preview',
                    payload(),
                );
                preview.value = data.preview;
                tokens.value = data.tokens;
            } catch {
                // Keep the last good preview on a transient failure.
            } finally {
                previewing.value = false;
            }
        }, 400);
    },
    { deep: true },
);

const overBudget = computed(() => tokens.value > props.maxTokens);

// Draft from what the organization already has. The server labels every field
// it wants to write: `new` means the user left it empty (safe to fill), and
// `conflict` means they already wrote something different — those are never
// applied here, they go to the review below and wait for a decision.
interface DiffEntry {
    field: string;
    status: string;
    current: unknown;
    proposed: unknown;
}

const draftBrief = ref('');
const drafting = ref(false);
const conflicts = ref<DiffEntry[]>([]);

const CONFLICT_LABELS = computed<Record<string, string>>(() => ({
    descriptor: t('settings.context.descriptor'),
    industry: t('settings.context.industry'),
    size: t('settings.context.size'),
    website: t('settings.context.website'),
    audience: t('settings.context.audience'),
    geographies: t('settings.context.geographies'),
    currency: t('settings.context.currency'),
    language: t('settings.context.language'),
    formality: t('settings.context.formality'),
    glossary: t('settings.context.glossary'),
    offerings: t('settings.context.offerings'),
    links: t('settings.context.links'),
}));

/** Write one drafted field onto the form, whatever its shape. */
function applyField(field: string, value: unknown): void {
    if (Array.isArray(value)) {
        (form as Record<string, unknown>)[field] = value.map((entry) =>
            typeof entry === 'object' && entry !== null ? { ...entry } : entry,
        );
        return;
    }
    if (typeof value === 'string') {
        (form as Record<string, unknown>)[field] = value;
    }
}

async function deriveDraft(): Promise<void> {
    drafting.value = true;
    conflicts.value = [];
    try {
        const { data } = await axios.post(
            '/settings/organization/context/draft',
            { website: form.website || null, brief: draftBrief.value || null },
        );

        if (!data.generated) {
            toast.info(t('settings.context.draft_empty'));
            return;
        }

        const diff = (data.diff ?? []) as DiffEntry[];

        // Empty fields fill straight away; anything that would replace what the
        // user wrote waits for them.
        diff.filter((e) => e.status === 'new').forEach((e) =>
            applyField(e.field, e.proposed),
        );
        conflicts.value = diff.filter((e) => e.status === 'conflict');

        toast.success(
            conflicts.value.length
                ? t('settings.context.draft_conflicts', {
                      count: conflicts.value.length,
                  })
                : t('settings.context.draft_applied'),
        );
    } catch {
        toast.error(t('settings.context.draft_failed'));
    } finally {
        drafting.value = false;
    }
}

function acceptConflict(field: string, value: unknown): void {
    applyField(field, value);
    conflicts.value = conflicts.value.filter((e) => e.field !== field);
}

function dismissConflict(field: string): void {
    conflicts.value = conflicts.value.filter((e) => e.field !== field);
}

function submit(): void {
    form.transform(() => payload()).put('/settings/organization/context', {
        preserveScroll: true,
        onSuccess: () => toast.success(t('settings.context.saved')),
    });
}
</script>

<template>
    <Head :title="t('settings.context.title')" />

    <SettingsLayout>
        <form class="space-y-4" @submit.prevent="submit">
            <SettingsCard
                :icon="Building2"
                :title="t('settings.context.title')"
                :description="t('settings.context.description')"
            >
                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.context.descriptor') }}</Label>
                        <textarea
                            v-model="form.descriptor"
                            rows="2"
                            maxlength="240"
                            :placeholder="
                                t('settings.context.descriptor_placeholder')
                            "
                            class="w-full rounded-sp-sm border border-medium bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent-blue focus:outline-none"
                        />
                        <InputError :message="form.errors.descriptor" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-1.5">
                            <Label>{{ t('settings.context.industry') }}</Label>
                            <Input v-model="form.industry" maxlength="80" />
                            <InputError :message="form.errors.industry" />
                        </div>
                        <div class="space-y-1.5">
                            <Label>{{ t('settings.context.size') }}</Label>
                            <Input
                                v-model="form.size"
                                maxlength="40"
                                :placeholder="
                                    t('settings.context.size_placeholder')
                                "
                            />
                            <InputError :message="form.errors.size" />
                        </div>
                        <div class="space-y-1.5">
                            <Label>{{ t('settings.context.website') }}</Label>
                            <Input
                                v-model="form.website"
                                type="url"
                                placeholder="https://"
                            />
                            <InputError :message="form.errors.website" />
                        </div>
                    </div>

                    <!-- Never a blank page: draft from what already exists. -->
                    <div
                        class="space-y-2 rounded-sp-sm border border-dashed border-soft p-3"
                    >
                        <p class="text-xs text-ink-muted">
                            {{ t('settings.context.draft_hint') }}
                        </p>
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="draftBrief"
                                maxlength="2000"
                                :placeholder="
                                    t(
                                        'settings.context.draft_brief_placeholder',
                                    )
                                "
                            />
                            <button
                                type="button"
                                :disabled="drafting"
                                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink disabled:opacity-50"
                                @click="deriveDraft"
                            >
                                <Loader2
                                    v-if="drafting"
                                    class="size-3.5 animate-spin"
                                />
                                <Wand2 v-else class="size-3.5" />
                                {{ t('settings.context.draft_button') }}
                            </button>
                        </div>

                        <DraftConflicts
                            :entries="conflicts"
                            :labels="CONFLICT_LABELS"
                            @accept="acceptConflict"
                            @dismiss="dismissConflict"
                        />
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard
                :icon="Globe"
                :title="t('settings.context.market')"
                :description="t('settings.context.market_hint')"
                tint="var(--sp-accent-cyan)"
            >
                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.context.audience') }}</Label>
                        <textarea
                            v-model="form.audience"
                            rows="2"
                            maxlength="400"
                            :placeholder="
                                t('settings.context.audience_placeholder')
                            "
                            class="w-full rounded-sp-sm border border-medium bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent-blue focus:outline-none"
                        />
                        <InputError :message="form.errors.audience" />
                    </div>

                    <div class="space-y-1.5">
                        <Label>{{ t('settings.context.geographies') }}</Label>
                        <div
                            v-for="(_, i) in form.geographies"
                            :key="`geo-${i}`"
                            class="flex items-center gap-2"
                        >
                            <Input
                                v-model="form.geographies[i]"
                                maxlength="60"
                            />
                            <button
                                type="button"
                                class="shrink-0 rounded-xs border border-soft p-1.5 text-ink-muted hover:text-ink"
                                :aria-label="t('settings.context.remove')"
                                @click="removeAt(form.geographies, i)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </div>
                        <button
                            v-if="form.geographies.length < 10"
                            type="button"
                            class="inline-flex items-center gap-1 text-xs text-ink-muted hover:text-ink"
                            @click="form.geographies.push('')"
                        >
                            <Plus class="size-3.5" />
                            {{ t('settings.context.geographies_placeholder') }}
                        </button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-1.5">
                            <Label>{{ t('settings.context.timezone') }}</Label>
                            <Input
                                v-model="form.timezone"
                                list="sp-timezones"
                                placeholder="UTC"
                            />
                            <datalist id="sp-timezones">
                                <option
                                    v-for="zone in TIMEZONE_SUGGESTIONS"
                                    :key="zone"
                                    :value="zone"
                                />
                            </datalist>
                            <p class="text-xs text-ink-muted">
                                {{ t('settings.context.timezone_hint') }}
                            </p>
                            <InputError :message="form.errors.timezone" />
                        </div>
                        <div class="space-y-1.5">
                            <Label>{{ t('settings.context.currency') }}</Label>
                            <Input
                                v-model="form.currency"
                                maxlength="3"
                                placeholder="USD"
                            />
                            <InputError :message="form.errors.currency" />
                        </div>
                        <div class="space-y-1.5">
                            <Label>{{ t('settings.context.units') }}</Label>
                            <select
                                v-model="form.units"
                                class="h-9 w-full rounded-sp-sm border border-medium bg-surface px-2 text-sm text-ink focus:border-accent-blue focus:outline-none"
                            >
                                <option value="">
                                    {{ t('settings.context.unset') }}
                                </option>
                                <option
                                    v-for="unit in unitOptions"
                                    :key="unit"
                                    :value="unit"
                                >
                                    {{ unit }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard
                :icon="MessageSquare"
                :title="t('settings.context.voice')"
                :description="t('settings.context.voice_hint')"
                tint="var(--sp-accent-violet)"
            >
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.context.language') }}</Label>
                        <Input
                            v-model="form.language"
                            maxlength="20"
                            placeholder="es-MX"
                        />
                        <p class="text-xs text-ink-muted">
                            <button
                                type="button"
                                class="underline underline-offset-2"
                                @click="form.language = 'auto'"
                            >
                                {{ t('settings.context.language_auto') }}
                            </button>
                        </p>
                        <InputError :message="form.errors.language" />
                    </div>
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.context.formality') }}</Label>
                        <select
                            v-model="form.formality"
                            class="h-9 w-full rounded-sp-sm border border-medium bg-surface px-2 text-sm text-ink focus:border-accent-blue focus:outline-none"
                        >
                            <option value="">
                                {{ t('settings.context.unset') }}
                            </option>
                            <option
                                v-for="option in formalityOptions"
                                :key="option"
                                :value="option"
                            >
                                {{ option }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.context.tone_notes') }}</Label>
                        <Input
                            v-model="form.tone_notes"
                            maxlength="240"
                            :placeholder="
                                t('settings.context.tone_notes_placeholder')
                            "
                        />
                        <InputError :message="form.errors.tone_notes" />
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard
                :icon="Package"
                :title="t('settings.context.offerings')"
                :description="t('settings.context.offerings_hint')"
                tint="var(--sp-accent-green)"
            >
                <div class="space-y-2">
                    <div
                        v-for="(offering, i) in form.offerings"
                        :key="`offering-${i}`"
                        class="flex items-start gap-2"
                    >
                        <Input
                            v-model="offering.name"
                            maxlength="60"
                            :placeholder="t('settings.context.offering_name')"
                            class="sm:max-w-[220px]"
                        />
                        <Input
                            v-model="offering.description"
                            maxlength="160"
                            :placeholder="
                                t('settings.context.offering_description')
                            "
                        />
                        <button
                            type="button"
                            class="mt-1 shrink-0 rounded-xs border border-soft p-1.5 text-ink-muted hover:text-ink"
                            :aria-label="t('settings.context.remove')"
                            @click="removeAt(form.offerings, i)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>
                    <button
                        v-if="form.offerings.length < 10"
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-ink-muted hover:text-ink"
                        @click="addRow(form.offerings, ['name', 'description'])"
                    >
                        <Plus class="size-3.5" />
                        {{ t('settings.context.add') }}
                    </button>
                </div>
            </SettingsCard>

            <SettingsCard
                :icon="BookOpen"
                :title="t('settings.context.glossary')"
                :description="t('settings.context.glossary_hint')"
                tint="var(--sp-accent-amber)"
            >
                <div class="space-y-2">
                    <div
                        v-for="(entry, i) in form.glossary"
                        :key="`glossary-${i}`"
                        class="flex items-start gap-2"
                    >
                        <Input
                            v-model="entry.term"
                            maxlength="40"
                            :placeholder="t('settings.context.glossary_term')"
                            class="sm:max-w-[180px]"
                        />
                        <Input
                            v-model="entry.meaning"
                            maxlength="160"
                            :placeholder="
                                t('settings.context.glossary_meaning')
                            "
                        />
                        <button
                            type="button"
                            class="mt-1 shrink-0 rounded-xs border border-soft p-1.5 text-ink-muted hover:text-ink"
                            :aria-label="t('settings.context.remove')"
                            @click="removeAt(form.glossary, i)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>
                    <button
                        v-if="form.glossary.length < 20"
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-ink-muted hover:text-ink"
                        @click="addRow(form.glossary, ['term', 'meaning'])"
                    >
                        <Plus class="size-3.5" />
                        {{ t('settings.context.add') }}
                    </button>
                </div>
            </SettingsCard>

            <SettingsCard
                :icon="ShieldAlert"
                :title="t('settings.context.boundaries')"
                :description="t('settings.context.boundaries_hint')"
                tint="var(--sp-accent-red)"
            >
                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <Label>{{ t('settings.context.never') }}</Label>
                        <div
                            v-for="(_, i) in form.never"
                            :key="`never-${i}`"
                            class="flex items-center gap-2"
                        >
                            <Input
                                v-model="form.never[i]"
                                maxlength="160"
                                :placeholder="
                                    t('settings.context.never_placeholder')
                                "
                            />
                            <button
                                type="button"
                                class="shrink-0 rounded-xs border border-soft p-1.5 text-ink-muted hover:text-ink"
                                :aria-label="t('settings.context.remove')"
                                @click="removeAt(form.never, i)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </div>
                        <button
                            v-if="form.never.length < 10"
                            type="button"
                            class="inline-flex items-center gap-1 text-xs text-ink-muted hover:text-ink"
                            @click="form.never.push('')"
                        >
                            <Plus class="size-3.5" />
                            {{ t('settings.context.add') }}
                        </button>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label>{{
                                t('settings.context.escalation')
                            }}</Label>
                            <Input
                                v-model="form.escalation"
                                maxlength="240"
                                :placeholder="
                                    t('settings.context.escalation_placeholder')
                                "
                            />
                            <InputError :message="form.errors.escalation" />
                        </div>
                        <div class="space-y-1.5">
                            <Label>{{
                                t('settings.context.disclaimer')
                            }}</Label>
                            <Input v-model="form.disclaimer" maxlength="240" />
                            <InputError :message="form.errors.disclaimer" />
                        </div>
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard
                :icon="Link2"
                :title="t('settings.context.links')"
                :description="t('settings.context.links_hint')"
            >
                <div class="space-y-2">
                    <div
                        v-for="(link, i) in form.links"
                        :key="`link-${i}`"
                        class="flex items-start gap-2"
                    >
                        <Input
                            v-model="link.label"
                            maxlength="60"
                            :placeholder="t('settings.context.link_label')"
                            class="sm:max-w-[180px]"
                        />
                        <Input
                            v-model="link.url"
                            type="url"
                            :placeholder="t('settings.context.link_url')"
                        />
                        <button
                            type="button"
                            class="mt-1 shrink-0 rounded-xs border border-soft p-1.5 text-ink-muted hover:text-ink"
                            :aria-label="t('settings.context.remove')"
                            @click="removeAt(form.links, i)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>
                    <button
                        v-if="form.links.length < 8"
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-ink-muted hover:text-ink"
                        @click="addRow(form.links, ['label', 'url'])"
                    >
                        <Plus class="size-3.5" />
                        {{ t('settings.context.add') }}
                    </button>
                </div>
            </SettingsCard>

            <SettingsCard
                :icon="Eye"
                :title="t('settings.context.preview')"
                :description="t('settings.context.preview_hint')"
                tint="var(--sp-accent-cyan)"
            >
                <div class="space-y-3">
                    <div class="flex items-center gap-2">
                        <span
                            class="text-xs font-medium"
                            :class="
                                overBudget
                                    ? 'text-accent-red'
                                    : 'text-ink-muted'
                            "
                        >
                            {{
                                t('settings.context.budget', {
                                    tokens,
                                    max: maxTokens,
                                })
                            }}
                        </span>
                        <Loader2
                            v-if="previewing"
                            class="size-3.5 animate-spin text-ink-muted"
                        />
                        <span v-if="overBudget" class="text-accent-red text-xs">
                            {{ t('settings.context.budget_over') }}
                        </span>
                    </div>
                    <div
                        class="h-1 w-full overflow-hidden rounded-full bg-surface"
                    >
                        <div
                            class="h-full transition-all"
                            :class="
                                overBudget ? 'bg-accent-red' : 'bg-accent-blue'
                            "
                            :style="{
                                width: `${Math.min(100, (tokens / maxTokens) * 100)}%`,
                            }"
                        />
                    </div>
                    <p class="text-xs text-ink-muted">
                        {{ t('settings.context.budget_hint') }}
                    </p>
                    <pre
                        v-if="preview"
                        class="max-h-80 overflow-auto rounded-sp-sm border border-soft bg-surface p-3 text-xs whitespace-pre-wrap text-ink-muted"
                        >{{ preview }}</pre
                    >
                    <p v-else class="text-xs text-ink-muted italic">
                        {{ t('settings.context.preview_empty') }}
                    </p>

                    <label
                        class="flex items-center gap-2 pt-1 text-sm text-ink"
                    >
                        <input
                            v-model="form.enabled"
                            type="checkbox"
                            class="size-4 rounded border-medium"
                        />
                        {{ t('settings.context.enabled') }}
                    </label>
                    <p class="text-xs text-ink-muted">
                        {{ t('settings.context.enabled_hint') }}
                    </p>
                </div>
            </SettingsCard>

            <div class="flex items-center justify-end gap-3">
                <span v-if="updatedAt" class="text-xs text-ink-muted">
                    {{ new Date(updatedAt).toLocaleString() }}
                </span>
                <button
                    type="submit"
                    :disabled="form.processing || overBudget"
                    class="inline-flex h-9 items-center gap-1.5 rounded-sp-sm bg-accent-blue px-4 text-sm font-medium text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <Loader2
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    {{ t('settings.context.save') }}
                </button>
            </div>
        </form>
    </SettingsLayout>
</template>
