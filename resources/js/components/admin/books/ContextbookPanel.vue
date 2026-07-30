<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DraftConflicts from '@/components/admin/DraftConflicts.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { OrganizationIdentity } from '@/composables/useOrganizationIdentity';
import {
    BookOpen,
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
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The Contextbook tab of the organization identity: the business knowledge every
 * model interaction in the organization is grounded in.
 *
 * What the organization *is* — the descriptor, industry, size and website — is not
 * here: it sits above the tabs, because the Brandbook is read off the same facts.
 */
const props = defineProps<{
    form: OrganizationIdentity['form'];
    context: OrganizationIdentity['context'];
    maxTokens: number;
    formalityOptions: string[];
    unitOptions: string[];
}>();

const { t } = useI18n();

const form = props.form;
const book = props.context;

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

const conflictLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        book.conflicts.map((entry) => [entry.field, book.label(entry.field)]),
    ),
);
</script>

<template>
    <div class="space-y-4">
        <!-- Decisions the site reading left for this book. -->
        <SettingsCard
            v-if="book.conflicts.length"
            :icon="Wand2"
            :title="t('settings.identity.decisions')"
            :description="t('settings.identity.decisions_hint')"
            tint="var(--sp-accent-green)"
        >
            <DraftConflicts
                :entries="book.conflicts"
                :labels="conflictLabels"
                @accept="book.acceptConflict"
                @dismiss="book.dismissConflict"
            />
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
                        <Input v-model="form.geographies[i]" maxlength="60" />
                        <button
                            type="button"
                            class="shrink-0 rounded-xs border border-soft p-1.5 text-ink-muted hover:text-ink"
                            :aria-label="t('settings.context.remove')"
                            @click="book.removeAt(form.geographies, i)"
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
                        @click="book.removeAt(form.offerings, i)"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>
                <button
                    v-if="form.offerings.length < 10"
                    type="button"
                    class="inline-flex items-center gap-1 text-xs text-ink-muted hover:text-ink"
                    @click="
                        book.addRow(form.offerings, ['name', 'description'])
                    "
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
                        :placeholder="t('settings.context.glossary_meaning')"
                    />
                    <button
                        type="button"
                        class="mt-1 shrink-0 rounded-xs border border-soft p-1.5 text-ink-muted hover:text-ink"
                        :aria-label="t('settings.context.remove')"
                        @click="book.removeAt(form.glossary, i)"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>
                <button
                    v-if="form.glossary.length < 20"
                    type="button"
                    class="inline-flex items-center gap-1 text-xs text-ink-muted hover:text-ink"
                    @click="book.addRow(form.glossary, ['term', 'meaning'])"
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
                            @click="book.removeAt(form.never, i)"
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
                        <Label>{{ t('settings.context.escalation') }}</Label>
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
                        <Label>{{ t('settings.context.disclaimer') }}</Label>
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
                        @click="book.removeAt(form.links, i)"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>
                <button
                    v-if="form.links.length < 8"
                    type="button"
                    class="inline-flex items-center gap-1 text-xs text-ink-muted hover:text-ink"
                    @click="book.addRow(form.links, ['label', 'url'])"
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
                            book.overBudget
                                ? 'text-accent-red'
                                : 'text-ink-muted'
                        "
                    >
                        {{
                            t('settings.context.budget', {
                                tokens: book.tokens,
                                max: maxTokens,
                            })
                        }}
                    </span>
                    <Loader2
                        v-if="book.previewing"
                        class="size-3.5 animate-spin text-ink-muted"
                    />
                    <span
                        v-if="book.overBudget"
                        class="text-accent-red text-xs"
                    >
                        {{ t('settings.context.budget_over') }}
                    </span>
                </div>
                <div class="h-1 w-full overflow-hidden rounded-full bg-surface">
                    <div
                        class="h-full transition-all"
                        :class="
                            book.overBudget ? 'bg-accent-red' : 'bg-accent-blue'
                        "
                        :style="{
                            width: `${Math.min(100, (book.tokens / maxTokens) * 100)}%`,
                        }"
                    />
                </div>
                <p class="text-xs text-ink-muted">
                    {{ t('settings.context.budget_hint') }}
                </p>
                <pre
                    v-if="book.preview"
                    class="max-h-80 overflow-auto rounded-sp-sm border border-soft bg-surface p-3 text-xs whitespace-pre-wrap text-ink-muted"
                    >{{ book.preview }}</pre
                >
                <p v-else class="text-xs text-ink-muted italic">
                    {{ t('settings.context.preview_empty') }}
                </p>

                <label class="flex items-center gap-2 pt-1 text-sm text-ink">
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
    </div>
</template>
