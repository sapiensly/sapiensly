<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    BookTab,
    ImportStatus,
} from '@/composables/useOrganizationIdentity';
import {
    AlertCircle,
    ArrowRight,
    Check,
    History,
    Loader2,
    Wand2,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * "Import from your website", the one reading both organization books are drafted
 * from.
 *
 * It used to live on two pages, and each of them applied its own half and pointed
 * at the other with a link. Now the reading happens once, above the tabs, and what
 * it filled in is reported here — including the decisions it left in each book,
 * which is the only thing worth sending the user to a tab for.
 *
 * Presentational on purpose: applying a proposal is not the same job for the two
 * books (the Brandbook has to copy remote images onto our own storage first), so
 * the identity screen keeps the apply logic and passes back what happened.
 */
const props = defineProps<{
    url: string;
    /** Field label for the URL box — it is also the website we store. */
    label?: string;
    brief: string;
    loading: boolean;
    /** The outcome of the last import run, or null before any run. */
    status: ImportStatus | null;
    /** What was imported recently, for the "you already read this" offer. */
    lastImport: { url: string | null; at: string } | null;
}>();

const emit = defineEmits<{
    (e: 'update:url', value: string): void;
    (e: 'update:brief', value: string): void;
    (e: 'read'): void;
    (e: 'open', tab: BookTab): void;
}>();

const { t, locale } = useI18n();

/**
 * What the server will actually fetch, mirroring SiteUrl::normalize for the one
 * case worth showing: a bare host. Saying "we'll read https://acme.com" up front
 * is what stops someone reading "could not be read" as a verdict on their site.
 */
const normalizedUrl = computed<string | null>(() => {
    const typed = props.url.trim();
    if (typed === '' || /^[a-z][a-z0-9+.-]*:\/\//i.test(typed)) return null;
    return `https://${typed.replace(/^\/+/, '')}`;
});

/**
 * A failure the user can act on, rather than one message for four problems.
 *
 * A run with no address is not a failure when a written brief still produced a
 * draft — that is the Contextbook working as intended — but a site that would not
 * open is worth saying out loud even then, because the Brandbook needed the page.
 */
const failure = computed<string | null>(() => {
    const status = props.status;
    if (!status || status.read) return null;
    if (status.reason === 'no_url' && status.drafted) return null;

    const known = ['no_url', 'invalid_url', 'unreachable', 'not_html', 'empty'];
    const reason = known.includes(status.reason)
        ? status.reason
        : 'unreachable';

    return t(`site_import.reason.${reason}`);
});

/**
 * The reading worked and neither book got anything out of it. A field that came
 * back as a conflict is NOT nothing — it is a decision waiting in a tab, and
 * saying "nothing came of it" above that contradicts the page.
 */
const emptyRun = computed(
    () =>
        props.status !== null &&
        props.status.read &&
        props.status.applied.length === 0 &&
        props.status.pending.brand === 0 &&
        props.status.pending.context === 0 &&
        props.status.notes.length === 0,
);

/** The books still owed a decision, so each one can be opened from here. */
const pending = computed<{ book: BookTab; count: number }[]>(() =>
    (['brand', 'context'] as BookTab[])
        .map((book) => ({ book, count: props.status?.pending[book] ?? 0 }))
        .filter((entry) => entry.count > 0),
);

function relativeTime(iso: string): string {
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    const format = new Intl.RelativeTimeFormat(locale.value, {
        numeric: 'auto',
    });
    if (seconds < 90) return format.format(-Math.max(seconds, 1), 'second');
    if (seconds < 5400)
        return format.format(-Math.round(seconds / 60), 'minute');
    return format.format(-Math.round(seconds / 3600), 'hour');
}

/**
 * The offer to reuse an earlier reading: only while this page has not run its
 * own import, and only when it points somewhere else than the box does.
 */
const reusable = computed(() => {
    const last = props.lastImport;
    if (props.status || !last?.url) return null;
    return last.url === props.url.trim() ? null : last;
});

function reuse(url: string): void {
    emit('update:url', url);
    emit('read');
}
</script>

<template>
    <div class="space-y-3">
        <p class="text-xs text-ink-muted">
            {{ t('site_import.hint.both') }}
        </p>

        <Label v-if="label">{{ label }}</Label>

        <div class="flex items-center gap-2">
            <Input
                :model-value="url"
                type="text"
                inputmode="url"
                maxlength="300"
                placeholder="acme.com"
                @update:model-value="emit('update:url', String($event))"
                @keydown.enter.prevent="emit('read')"
            />
            <button
                type="button"
                :disabled="loading || (!url.trim() && !brief.trim())"
                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xs border border-soft px-3 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink disabled:opacity-50"
                @click="emit('read')"
            >
                <Loader2 v-if="loading" class="size-3.5 animate-spin" />
                <Wand2 v-else class="size-3.5" />
                {{ t('site_import.read') }}
            </button>
        </div>

        <p v-if="normalizedUrl" class="text-[11px] text-ink-muted">
            {{ t('site_import.will_read', { url: normalizedUrl }) }}
        </p>

        <Input
            :model-value="brief"
            maxlength="2000"
            :placeholder="t('site_import.brief_placeholder')"
            @update:model-value="emit('update:brief', String($event))"
            @keydown.enter.prevent="emit('read')"
        />

        <!-- Somebody already read a site; offer it rather than asking again. -->
        <div
            v-if="reusable"
            class="flex flex-wrap items-center gap-2 rounded-sp-sm border border-dashed border-soft px-3 py-2"
        >
            <History class="size-3.5 shrink-0 text-ink-muted" />
            <span class="text-xs text-ink-muted">
                {{
                    t('site_import.already_read', {
                        url: reusable.url,
                        when: relativeTime(reusable.at),
                    })
                }}
            </span>
            <button
                type="button"
                class="ml-auto h-7 shrink-0 rounded-xs border border-soft px-2.5 text-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                @click="reuse(reusable.url!)"
            >
                {{ t('site_import.reuse') }}
            </button>
        </div>

        <!-- What happened, in the user's terms. -->
        <div
            v-if="failure"
            class="flex items-start gap-2 rounded-sp-sm border border-soft px-3 py-2"
        >
            <AlertCircle class="text-accent-amber mt-0.5 size-3.5 shrink-0" />
            <p class="text-xs text-ink-muted">{{ failure }}</p>
        </div>

        <template v-if="status">
            <div
                v-if="status.applied.length"
                class="space-y-2 rounded-sp-sm border border-soft px-3 py-2"
            >
                <p class="flex items-center gap-1.5 text-xs text-ink">
                    <Check class="text-accent-green size-3.5 shrink-0" />
                    {{
                        t('site_import.filled', {
                            count: status.applied.length,
                        })
                    }}
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="label in status.applied"
                        :key="label"
                        class="rounded-xs border border-soft px-1.5 py-0.5 text-[11px] text-ink-muted"
                    >
                        {{ label }}
                    </span>
                </div>
                <p class="text-[11px] text-ink-muted">
                    {{ t('site_import.not_saved_yet') }}
                </p>
            </div>

            <p v-else-if="emptyRun" class="text-xs text-ink-muted">
                {{ t('site_import.nothing.any') }}
            </p>

            <!-- The page opened and had no words on it. Saying so is the whole
                 point: an empty draft with no explanation reads as a broken
                 feature rather than as a client-rendered home page. -->
            <div
                v-if="status.noProse"
                class="flex items-start gap-2 rounded-sp-sm border border-soft px-3 py-2"
            >
                <AlertCircle
                    class="text-accent-amber mt-0.5 size-3.5 shrink-0"
                />
                <p class="text-xs text-ink-muted">
                    {{ t('site_import.no_prose') }}
                </p>
            </div>

            <!-- Where the draft came from: without this, a draft built from the
                 brief alone reads as a draft the website produced. -->
            <p v-if="status.sources.length" class="text-[11px] text-ink-muted">
                {{
                    t('site_import.sources', {
                        sources: status.sources
                            .map((source) => t(`site_import.source.${source}`))
                            .join(', '),
                    })
                }}
            </p>

            <!-- The decisions the reading left behind, in the book that owns
                 them. Every other outcome is already applied to the form. -->
            <button
                v-for="entry in pending"
                :key="entry.book"
                type="button"
                class="flex items-center gap-1.5 text-xs text-accent-blue underline-offset-2 hover:underline"
                @click="emit('open', entry.book)"
            >
                {{
                    t(`site_import.pending.${entry.book}`, {
                        count: entry.count,
                    })
                }}
                <ArrowRight class="size-3.5" />
            </button>
        </template>

        <p
            v-for="note in status?.notes ?? []"
            :key="note"
            class="text-xs text-ink-muted"
        >
            {{ note }}
        </p>
    </div>
</template>
