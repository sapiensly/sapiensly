<script setup lang="ts">
import { Check, Copy, KeyRound, Loader2, X } from '@lucide/vue';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Issue and revoke the credentials for this app's REST data API.
 *
 * The token appears exactly once, in the response to minting it — only its hash
 * is stored. So this panel is built around that fact rather than hiding it: the
 * new key is shown in a block the user must copy before closing, and the list
 * afterwards can only ever show the prefix.
 *
 * A key names an app ROLE, which is its ceiling. The role picker is the whole
 * security decision, so it is a required, deliberate choice with no default.
 */
const props = defineProps<{
    appId: string;
    roles: Array<{ id: string; slug: string; name: string }>;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const { t } = useI18n();

interface Key {
    id: string;
    name: string;
    prefix: string;
    role_slug: string;
    scopes: unknown;
    last_used_at: string | null;
    expires_at: string | null;
    revoked: boolean;
}

const keys = ref<Key[]>([]);
const name = ref('');
const roleSlug = ref('');
const busy = ref(false);
const error = ref('');
const freshToken = ref('');
const copied = ref(false);

async function load() {
    try {
        const { data } = await axios.get(
            `/apps/${props.appId}/builder/api-keys`,
        );
        keys.value = data.keys ?? [];
    } catch {
        /* an empty list is a truthful fallback here */
    }
}

async function create() {
    if (busy.value || !name.value.trim() || !roleSlug.value) return;
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/api-keys`,
            {
                name: name.value.trim(),
                role_slug: roleSlug.value,
            },
        );
        freshToken.value = data.token;
        keys.value = data.keys ?? [];
        name.value = '';
    } catch (e) {
        error.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? t('apps.builder.api.failed');
    } finally {
        busy.value = false;
    }
}

async function revoke(id: string) {
    busy.value = true;
    try {
        const { data } = await axios.delete(
            `/apps/${props.appId}/builder/api-keys/${id}`,
        );
        keys.value = data.keys ?? [];
    } catch {
        error.value = t('apps.builder.api.failed');
    } finally {
        busy.value = false;
    }
}

async function copyToken() {
    await navigator.clipboard.writeText(freshToken.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}

onMounted(load);
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        @click.self="emit('close')"
    >
        <div
            class="flex max-h-[85dvh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-medium bg-surface shadow-xl"
        >
            <header
                class="flex items-center justify-between border-b border-medium px-5 py-3"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-medium text-ink"
                >
                    <KeyRound class="size-4 text-accent-blue" />
                    {{ t('apps.builder.api.title') }}
                </h2>
                <button
                    type="button"
                    class="text-ink-muted transition-colors hover:text-ink"
                    @click="emit('close')"
                >
                    <X class="size-4" />
                </button>
            </header>

            <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <p class="text-xs text-ink-muted">
                    {{ t('apps.builder.api.intro') }}
                </p>

                <!-- The token, once. Deliberately loud: closing this panel is
                     the last chance to copy it. -->
                <div
                    v-if="freshToken"
                    class="space-y-2 rounded-lg border border-accent-blue/30 bg-accent-blue/5 px-4 py-3"
                >
                    <p class="text-xs font-medium text-accent-blue">
                        {{ t('apps.builder.api.copy_now') }}
                    </p>
                    <div class="flex items-center gap-2">
                        <code
                            class="min-w-0 flex-1 truncate rounded-md bg-surface px-2 py-1.5 font-mono text-[11px] text-ink"
                        >
                            {{ freshToken }}
                        </code>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-pill border border-medium bg-surface px-2.5 py-1.5 text-xs text-ink-muted hover:text-ink"
                            @click="copyToken"
                        >
                            <Check v-if="copied" class="size-3.5" />
                            <Copy v-else class="size-3.5" />
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <label class="flex flex-col gap-1 text-xs text-ink-muted">
                        {{ t('apps.builder.api.name') }}
                        <input
                            v-model="name"
                            type="text"
                            :placeholder="t('apps.builder.api.name_hint')"
                            class="rounded-md border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
                        />
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-ink-muted">
                        {{ t('apps.builder.api.role') }}
                        <select
                            v-model="roleSlug"
                            class="rounded-md border border-medium bg-surface px-2 py-1.5 text-sm text-ink"
                        >
                            <option value="" disabled>
                                {{ t('apps.builder.api.role_pick') }}
                            </option>
                            <option
                                v-for="r in props.roles"
                                :key="r.id"
                                :value="r.slug"
                            >
                                {{ r.name }}
                            </option>
                        </select>
                    </label>
                    <button
                        type="button"
                        :disabled="busy || !name.trim() || !roleSlug"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-accent-blue/30 bg-accent-blue/10 px-3 py-1.5 text-xs font-medium text-accent-blue transition-colors hover:bg-accent-blue/20 disabled:opacity-50"
                        @click="create"
                    >
                        <Loader2 v-if="busy" class="size-3.5 animate-spin" />
                        {{ t('apps.builder.api.create') }}
                    </button>
                </div>

                <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

                <div
                    v-if="keys.length"
                    class="overflow-x-auto rounded-md border border-medium"
                >
                    <table class="w-full text-left text-xs">
                        <thead class="bg-surface-muted text-ink-muted">
                            <tr>
                                <th class="px-3 py-2 font-medium">
                                    {{ t('apps.builder.api.col_name') }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ t('apps.builder.api.col_role') }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ t('apps.builder.api.col_used') }}
                                </th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="k in keys"
                                :key="k.id"
                                class="border-t border-medium"
                                :class="k.revoked ? 'opacity-50' : ''"
                            >
                                <td class="px-3 py-2 text-ink">
                                    {{ k.name }}
                                    <span class="ml-1 font-mono text-ink-muted"
                                        >sap_{{ k.prefix }}…</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-ink-muted">
                                    {{ k.role_slug }}
                                </td>
                                <td class="px-3 py-2 text-ink-muted">
                                    {{
                                        k.last_used_at
                                            ? new Date(
                                                  k.last_used_at,
                                              ).toLocaleDateString()
                                            : t('apps.builder.api.never_used')
                                    }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        v-if="!k.revoked"
                                        type="button"
                                        class="text-[11px] text-ink-muted hover:text-red-400"
                                        @click="revoke(k.id)"
                                    >
                                        {{ t('apps.builder.api.revoke') }}
                                    </button>
                                    <span
                                        v-else
                                        class="text-[11px] text-ink-muted"
                                    >
                                        {{ t('apps.builder.api.revoked') }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <footer
                class="flex items-center justify-end border-t border-medium px-5 py-3"
            >
                <button
                    type="button"
                    class="rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs text-ink-muted transition-colors hover:text-ink"
                    @click="emit('close')"
                >
                    {{ t('apps.builder.api.done') }}
                </button>
            </footer>
        </div>
    </div>
</template>
