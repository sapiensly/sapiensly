<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link } from '@inertiajs/vue3';
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
 * "Import from your website", shared by the Brandbook and the Contextbook.
 *
 * Both books are read off the same home page, and they used to ask separately:
 * the admin typed the URL on one screen, then typed it again on the other, and
 * the site was downloaded — and drafted — twice. One endpoint now reads it once
 * and proposes both; this component is the face of that on either page.
 *
 * It is presentational on purpose. Applying a proposal is not the same job on
 * the two pages (the Brandbook has to copy remote images onto our own storage
 * first), so each page keeps its own apply logic and passes back what happened.
 */
export interface ImportStatus {
    /** The normalized URL that was actually read. */
    url: string | null;
    read: boolean;
    /** Why the read failed — see SiteFetch on the server. */
    reason: string;
    /** Human labels of the fields that landed on the form without asking. */
    applied: string[];
    /** Fields the site proposed that clash with something already set. */
    conflicts: number;
    /** What the draft was built from: 'website', 'brief'. */
    sources: string[];
    /** How many fields the OTHER book got from this same reading. */
    otherCount: number;
    /** Free-form remarks from the proposal (why an accent was refused, etc.). */
    notes: string[];
    /**
     * The page opened but had no words on it — a client-rendered site whose
     * content we do not run. Only the Contextbook cares: the brand signals live
     * in the markup and are read either way.
     */
    noProse?: boolean;
}

const props = defineProps<{
    /** Which book this page is; decides the copy and where "the other book" points. */
    book: 'brand' | 'context';
    url: string;
    /**
     * Field label for the URL box. The Contextbook passes one because that box
     * is also the website it stores; the Brandbook only ever reads, so it has
     * nothing to label.
     */
    label?: string;
    /** Only the Contextbook drafts from a written brief as well as the page. */
    brief?: string;
    withBrief?: boolean;
    loading: boolean;
    /** The outcome of the last import run on THIS page, or null before any run. */
    status: ImportStatus | null;
    /** What was imported recently anywhere in the org, for the "you already read this" offer. */
    lastImport: { url: string | null; at: string } | null;
}>();

const emit = defineEmits<{
    (e: 'update:url', value: string): void;
    (e: 'update:brief', value: string): void;
    (e: 'read'): void;
}>();

const { t, locale } = useI18n();

const OTHER = {
    brand: { href: '/settings/organization/context', key: 'context' },
    context: { href: '/settings/organization/brand', key: 'brand' },
} as const;

const other = computed(() => OTHER[props.book]);

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

/** A failure the user can act on, rather than one message for four problems. */
const failure = computed<string | null>(() => {
    if (!props.status || props.status.read) return null;
    const known = ['no_url', 'invalid_url', 'unreachable', 'not_html', 'empty'];
    const reason = known.includes(props.status.reason)
        ? props.status.reason
        : 'unreachable';
    return t(`site_import.reason.${reason}`);
});

/**
 * The reading worked and this book got nothing out of it. A field that came back
 * as a conflict is NOT nothing — it is a decision waiting below, and saying "no
 * icon, logo or colour we can use" above it contradicts the page.
 */
const emptyForBook = computed(
    () =>
        props.status !== null &&
        props.status.read &&
        props.status.applied.length === 0 &&
        props.status.conflicts === 0 &&
        props.status.notes.length === 0,
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
 * The offer to reuse a reading from the other page: only while this page has not
 * run its own import, and only when it points somewhere else than the box does.
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
            {{ t(`site_import.hint.${book}`) }}
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
                :disabled="loading || (!url.trim() && !brief?.trim())"
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
            v-if="withBrief"
            :model-value="brief ?? ''"
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

        <template v-else-if="status">
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

            <p v-else-if="emptyForBook" class="text-xs text-ink-muted">
                {{ t(`site_import.nothing.${book}`) }}
            </p>

            <!-- The page opened and had no words on it. Saying so is the whole
                 point: an empty draft with no explanation reads as a broken
                 feature rather than as a client-rendered home page. -->
            <div
                v-if="book === 'context' && status.noProse"
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
            <p
                v-if="book === 'context' && status.sources.length"
                class="text-[11px] text-ink-muted"
            >
                {{
                    t('site_import.sources', {
                        sources: status.sources
                            .map((source) => t(`site_import.source.${source}`))
                            .join(', '),
                    })
                }}
            </p>
        </template>

        <p
            v-for="note in status?.notes ?? []"
            :key="note"
            class="text-xs text-ink-muted"
        >
            {{ note }}
        </p>

        <!-- The other half of the same reading, one click away and already paid for. -->
        <Link
            v-if="status?.read && status.otherCount"
            :href="other.href"
            class="inline-flex items-center gap-1.5 text-xs text-accent-blue underline-offset-2 hover:underline"
        >
            {{
                t(`site_import.other.${other.key}`, {
                    count: status.otherCount,
                })
            }}
            <ArrowRight class="size-3.5" />
        </Link>

        <!-- Conflicts and per-field failures stay with the page that knows how to
             apply them. -->
        <slot />
    </div>
</template>
