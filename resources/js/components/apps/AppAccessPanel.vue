<script setup lang="ts">
import { Loader2, ShieldCheck } from '@lucide/vue';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';

const { t } = useI18n();

const props = defineProps<{
    appId: string;
}>();

interface Role {
    slug: string;
    name: string;
    is_default: boolean;
}

interface Member {
    user_id: number;
    name: string;
    email: string;
    role_slug: string | null;
    assignment_id: string | null;
}

interface Roster {
    access_mode: string;
    roles: Role[];
    members: Member[];
}

const loading = ref(true);
const savingUserId = ref<number | null>(null);
const savingMode = ref(false);
const roster = ref<Roster>({ access_mode: 'open', roles: [], members: [] });

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get<Roster>(`/apps/${props.appId}/access`);
        roster.value = data;
    } finally {
        loading.value = false;
    }
}

/**
 * Apply a role selection for one member. An empty value resets the member to the
 * default role (revoke the explicit assignment); any slug grants/replaces it.
 */
async function onRoleChange(member: Member, slug: string) {
    savingUserId.value = member.user_id;
    try {
        if (slug === '') {
            if (member.assignment_id) {
                const { data } = await axios.delete<Roster>(
                    `/apps/${props.appId}/access/${member.assignment_id}`,
                );
                roster.value = data;
            }
        } else {
            const { data } = await axios.post<Roster>(
                `/apps/${props.appId}/access`,
                {
                    assigned_user_id: member.user_id,
                    role_slug: slug,
                },
            );
            roster.value = data;
        }
        toast.success(t('apps.access.saved'));
    } catch {
        toast.error(t('apps.access.save_failed'));
        await load();
    } finally {
        savingUserId.value = null;
    }
}

/**
 * Switch the app's access mode. Persists as a manifest patch (a new version)
 * server-side; the response carries the refreshed roster.
 */
async function onModeChange(mode: 'open' | 'allowlist') {
    if (mode === roster.value.access_mode || savingMode.value) {
        return;
    }
    savingMode.value = true;
    try {
        const { data } = await axios.post<Roster>(
            `/apps/${props.appId}/access/mode`,
            {
                access_mode: mode,
            },
        );
        roster.value = data;
        toast.success(t('apps.access.saved'));
    } catch {
        toast.error(t('apps.access.save_failed'));
    } finally {
        savingMode.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <header class="mb-5 flex items-start gap-3">
            <span
                class="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-sp-sm bg-accent-blue/15 text-accent-blue"
            >
                <ShieldCheck class="size-5" />
            </span>
            <div>
                <h2 class="text-base font-semibold text-ink">
                    {{ t('apps.access.title') }}
                </h2>
                <p class="mt-1 text-xs leading-relaxed text-ink-muted">
                    {{ t('apps.access.description') }}
                </p>
                <div class="mt-3">
                    <div
                        class="inline-flex items-center rounded-pill border border-medium bg-surface p-0.5"
                    >
                        <button
                            v-for="mode in ['open', 'allowlist'] as const"
                            :key="mode"
                            type="button"
                            :disabled="savingMode"
                            class="inline-flex items-center gap-1.5 rounded-pill px-3 py-1 text-xs transition-colors disabled:opacity-50"
                            :class="
                                roster.access_mode === mode
                                    ? 'bg-accent-blue/15 text-accent-blue'
                                    : 'text-ink-muted hover:text-ink'
                            "
                            @click="onModeChange(mode)"
                        >
                            {{ mode === 'allowlist' ? t('apps.access.mode_allowlist_label') : t('apps.access.mode_open_label') }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-ink-muted">
                        {{
                            roster.access_mode === 'allowlist'
                                ? t('apps.access.mode_allowlist')
                                : t('apps.access.mode_open')
                        }}
                    </p>
                </div>
            </div>
        </header>

        <div
            v-if="loading"
            class="flex items-center justify-center py-12 text-ink-muted"
        >
            <Loader2 class="size-5 animate-spin" />
        </div>

        <p
            v-else-if="roster.roles.length === 0"
            class="rounded-sp-sm border border-soft bg-surface px-4 py-6 text-center text-sm text-ink-muted"
        >
            {{ t('apps.access.no_roles') }}
        </p>

        <p
            v-else-if="roster.members.length === 0"
            class="rounded-sp-sm border border-soft bg-surface px-4 py-6 text-center text-sm text-ink-muted"
        >
            {{ t('apps.access.no_members') }}
        </p>

        <div v-else class="overflow-hidden rounded-sp-sm border border-soft">
            <table class="w-full text-sm">
                <thead
                    class="bg-surface text-left text-xs tracking-wide text-ink-muted uppercase"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ t('apps.access.member') }}
                        </th>
                        <th class="w-56 px-4 py-2 font-medium">
                            {{ t('apps.access.role') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-soft">
                    <tr
                        v-for="member in roster.members"
                        :key="member.user_id"
                        class="bg-navy"
                    >
                        <td class="px-4 py-2.5">
                            <div class="font-medium text-ink">
                                {{ member.name }}
                            </div>
                            <div class="text-xs text-ink-muted">
                                {{ member.email }}
                            </div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <select
                                    :value="member.role_slug ?? ''"
                                    :disabled="savingUserId === member.user_id"
                                    class="w-full rounded-sp-sm border border-medium bg-surface px-2 py-1.5 text-xs text-ink focus:border-accent-blue focus:outline-none disabled:opacity-50"
                                    @change="
                                        onRoleChange(
                                            member,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="">
                                        {{ t('apps.access.default_role') }}
                                    </option>
                                    <option
                                        v-for="role in roster.roles"
                                        :key="role.slug"
                                        :value="role.slug"
                                    >
                                        {{ role.name }}
                                    </option>
                                </select>
                                <Loader2
                                    v-if="savingUserId === member.user_id"
                                    class="size-4 shrink-0 animate-spin text-ink-muted"
                                />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
