<script setup lang="ts">
/**
 * Provisioning card — the headline of mid-conversation integration setup
 * (FR-7/8). When a flow needs a system that isn't connected, the builder
 * creates a DRAFT connection and surfaces this card: what the flow needs, the
 * state machine (proposed → awaiting authorization → ready), and a Connect
 * button that sends the user to authorize in the provider's OWN surface. The
 * AI never enters credentials (invariant 4).
 *
 * The dependent connector.call stays unauthorized until the user connects;
 * Recheck re-reads the live authorization state.
 */

import { ArrowUpRight, Check, Loader2 } from '@lucide/vue';
import axios from 'axios';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

export interface IntegrationProposal {
    integration_id: string;
    name: string;
    auth_type: string;
    authorize_required: boolean;
    authorized: boolean;
    reason?: string;
    actions?: string[];
}

const props = defineProps<{
    proposal: IntegrationProposal;
    appId: string;
}>();

const { t } = useI18n();

// Live authorization state, seeded from the proposal and refreshed on Recheck.
const authorized = ref(props.proposal.authorized);
const rechecking = ref(false);

const connectUrl = `/system/integrations/${props.proposal.integration_id}`;

async function recheck() {
    rechecking.value = true;
    try {
        const { data } = await axios.get(
            `/apps/${props.appId}/builder/connector-actions`,
        );
        const found = (data.integrations ?? []).find(
            (i: { id: string; authorized: boolean }) =>
                i.id === props.proposal.integration_id,
        );
        if (found) {
            authorized.value = found.authorized;
        }
    } catch {
        // Non-fatal — leave the current state; the user can retry.
    } finally {
        rechecking.value = false;
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
                    :href="connectUrl"
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
