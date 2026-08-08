<script setup lang="ts">
/**
 * Provisioning card — the headline of mid-conversation integration setup
 * (FR-7/8). When a flow needs a system that isn't connected, the builder
 * creates a DRAFT connection and surfaces this card: what the flow needs, the
 * state machine (proposed → awaiting authorization → ready), and the way to
 * finish. The AI never enters credentials (invariant 4).
 *
 * Authorization is answered HERE, in the two shapes it actually takes:
 *
 *  - OAuth: `authorize_url` sends the user to consent in the provider's own
 *    surface, per user. It used to link to the integrations admin, which has no
 *    authorize button — the real route ran through inventing a Tool nobody
 *    asked for, six screens behind a button that said "Connect X".
 *  - A key/bearer secret: a password field on the card itself, posted straight
 *    to the server. The chat never sees it; the conversation only learns the
 *    connection became authorized.
 *
 * The dependent connector.call stays unauthorized until then; Recheck re-reads
 * the live authorization state.
 */

import { ArrowUpRight, Check, Loader2 } from '@lucide/vue';
import axios from 'axios';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

export interface IntegrationProposal {
    integration_id: string;
    name: string;
    auth_type: string;
    authorize_required: boolean;
    authorized: boolean;
    /** Present only for per-user OAuth: where the consent round-trip starts. */
    authorize_url?: string | null;
    /** The auth_config key a single typed secret lands under, when it is one. */
    secret_field?: string | null;
    reason?: string;
    actions?: string[];
}

const props = defineProps<{
    proposal: IntegrationProposal;
    appId: string;
}>();

const { t } = useI18n();

// Live authorization state, seeded from the proposal and refreshed on Recheck
// (and immediately after a secret is saved).
const authorized = ref(props.proposal.authorized);
const authorizeUrl = ref(props.proposal.authorize_url ?? null);
const secretField = ref(props.proposal.secret_field ?? null);

const rechecking = ref(false);
const saving = ref(false);
const secret = ref('');
const error = ref<string | null>(null);

/** The connection is finished by consent elsewhere, or by a secret typed here. */
const connectsByConsent = computed(() => !!authorizeUrl.value);
const connectsBySecret = computed(
    () => !connectsByConsent.value && !!secretField.value,
);

/**
 * Come back to the builder once consent completes, rather than to whichever
 * admin page the flow happened to pass through.
 */
const returnTo = computed(() =>
    typeof window === 'undefined'
        ? ''
        : window.location.pathname + window.location.search,
);

const consentHref = computed(() =>
    authorizeUrl.value
        ? `${authorizeUrl.value}?return_to=${encodeURIComponent(returnTo.value)}`
        : '',
);

async function recheck() {
    rechecking.value = true;
    error.value = null;
    try {
        const { data } = await axios.get(
            `/apps/${props.appId}/builder/connector-actions`,
        );
        const found = (data.integrations ?? []).find(
            (i: { id: string }) => i.id === props.proposal.integration_id,
        );
        if (found) {
            authorized.value = found.authorized;
            authorizeUrl.value = found.authorize_url ?? null;
            secretField.value = found.secret_field ?? null;
        }
    } catch {
        // Non-fatal — leave the current state; the user can retry.
    } finally {
        rechecking.value = false;
    }
}

async function saveSecret() {
    if (secret.value.trim() === '' || saving.value) return;

    saving.value = true;
    error.value = null;
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/integrations/${props.proposal.integration_id}/credentials`,
            { secret: secret.value },
        );
        authorized.value = !!data.authorized;
        // Drop it from memory the moment the server has it.
        secret.value = '';
    } catch (e) {
        const message = (e as { response?: { data?: { message?: string } } })
            ?.response?.data?.message;
        error.value = message ?? t('apps.builder.provision.secret_failed');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div
        class="mr-8 overflow-hidden rounded-sp-sm border border-soft bg-navy/50"
    >
        <div class="border-b border-soft px-3 py-2.5">
            <span class="text-[10px] tracking-wider text-ink-subtle uppercase">
                {{ t('apps.builder.provision.heading') }}
            </span>
            <p class="mt-0.5 text-sm font-medium text-ink">
                {{ t('apps.builder.provision.title', { name: proposal.name }) }}
            </p>
            <p v-if="proposal.reason" class="mt-0.5 text-xs text-ink-muted">
                {{ proposal.reason }}
            </p>
        </div>

        <div class="space-y-3 px-3 py-3">
            <!-- State machine -->
            <ol
                class="flex items-center gap-1 text-[10px] tracking-wider uppercase"
            >
                <li class="flex items-center gap-1 text-accent-blue">
                    <Check class="size-3" />
                    {{ t('apps.builder.provision.state_proposed') }}
                </li>
                <li class="text-ink-subtle">—</li>
                <li
                    :class="
                        authorized
                            ? 'text-accent-blue'
                            : 'font-medium text-amber-300'
                    "
                >
                    <span class="inline-flex items-center gap-1">
                        <Check v-if="authorized" class="size-3" />
                        {{ t('apps.builder.provision.state_authorize') }}
                    </span>
                </li>
                <li class="text-ink-subtle">—</li>
                <li
                    :class="
                        authorized
                            ? 'font-medium text-sp-success'
                            : 'text-ink-subtle'
                    "
                >
                    {{ t('apps.builder.provision.state_ready') }}
                </li>
            </ol>

            <!-- What the flow needs -->
            <div v-if="proposal.actions?.length" class="flex flex-wrap gap-1.5">
                <span
                    v-for="(action, i) in proposal.actions"
                    :key="i"
                    class="rounded-pill bg-surface px-1.5 py-0.5 text-[10px] text-ink-muted"
                >
                    {{ action }}
                </span>
            </div>

            <!-- Invariant 4, in plain copy -->
            <p class="text-xs text-ink-subtle">
                {{
                    t('apps.builder.provision.credentials_note', {
                        name: proposal.name,
                    })
                }}
            </p>

            <!-- The secret goes straight to the server from here: no trip to the
                 integrations admin, and nothing typed into the conversation. -->
            <form
                v-if="!authorized && connectsBySecret"
                class="space-y-1.5"
                @submit.prevent="saveSecret"
            >
                <label
                    :for="`sp-secret-${proposal.integration_id}`"
                    class="block text-[10px] tracking-wider text-ink-subtle uppercase"
                >
                    {{ t('apps.builder.provision.secret_label') }}
                </label>
                <div class="flex items-center gap-2">
                    <input
                        :id="`sp-secret-${proposal.integration_id}`"
                        v-model="secret"
                        type="password"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="
                            t('apps.builder.provision.secret_placeholder')
                        "
                        class="min-w-0 flex-1 rounded-xs border border-soft bg-surface px-2.5 py-1.5 font-mono text-xs text-ink placeholder:text-ink-subtle focus:border-accent-blue focus:outline-none"
                    />
                    <button
                        type="submit"
                        class="inline-flex shrink-0 items-center gap-1 rounded-pill bg-accent-blue px-3 py-1.5 text-[11px] font-medium text-white transition-colors hover:bg-accent-blue/90 disabled:opacity-50"
                        :disabled="saving || secret.trim() === ''"
                    >
                        <Loader2 v-if="saving" class="size-3 animate-spin" />
                        {{ t('apps.builder.provision.secret_save') }}
                    </button>
                </div>
                <p v-if="error" class="text-[11px] text-sp-danger">
                    {{ error }}
                </p>
            </form>
        </div>

        <!-- Actions -->
        <div
            class="flex items-center justify-end gap-2 border-t border-soft px-3 py-2.5"
        >
            <template v-if="authorized">
                <span
                    class="inline-flex items-center gap-1 text-[11px] font-medium text-sp-success"
                >
                    <Check class="size-3.5" />
                    {{ t('apps.builder.provision.connected') }}
                </span>
            </template>
            <template v-else>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-xs px-2.5 py-1 text-[11px] text-ink-muted transition-colors hover:bg-surface hover:text-ink disabled:opacity-50"
                    :disabled="rechecking"
                    @click="recheck"
                >
                    <Loader2 v-if="rechecking" class="size-3 animate-spin" />
                    {{ t('apps.builder.provision.recheck') }}
                </button>
                <a
                    v-if="connectsByConsent"
                    :href="consentHref"
                    class="inline-flex items-center gap-1 rounded-pill bg-accent-blue px-3 py-1 text-[11px] font-medium text-white transition-colors hover:bg-accent-blue/90"
                >
                    {{
                        t('apps.builder.provision.connect', {
                            name: proposal.name,
                        })
                    }}
                    <ArrowUpRight class="size-3" />
                </a>
                <!-- Neither consent nor a single secret (basic auth, client
                     credentials): the admin form is the honest destination. -->
                <a
                    v-else-if="!connectsBySecret"
                    :href="`/system/integrations/${proposal.integration_id}/edit`"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1 rounded-pill bg-accent-blue px-3 py-1 text-[11px] font-medium text-white transition-colors hover:bg-accent-blue/90"
                >
                    {{
                        t('apps.builder.provision.connect', {
                            name: proposal.name,
                        })
                    }}
                    <ArrowUpRight class="size-3" />
                </a>
            </template>
        </div>
    </div>
</template>
