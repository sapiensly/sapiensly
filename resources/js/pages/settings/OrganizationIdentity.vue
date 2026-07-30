<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsCard from '@/components/admin/SettingsCard.vue';
import SiteImport from '@/components/admin/SiteImport.vue';
import BrandbookPanel from '@/components/admin/books/BrandbookPanel.vue';
import ContextbookPanel from '@/components/admin/books/ContextbookPanel.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    type BookTab,
    type IdentityProps,
    useOrganizationIdentity,
} from '@/composables/useOrganizationIdentity';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head } from '@inertiajs/vue3';
import { Building2, Loader2 } from '@lucide/vue';
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The organization's identity: the general facts, then the Brandbook and the
 * Contextbook as two tabs over them, saved together.
 *
 * The books were a page each. They are the same subject read off the same
 * website, so the URL is asked for once, above the tabs, and one reading fills
 * both — see {@see useOrganizationIdentity}, which owns all of the state.
 */
const props = defineProps<IdentityProps>();

const { t } = useI18n();

const identity = useOrganizationIdentity(props);
const { form, tab, openTab, errorCount, submit, brand, context, site } =
    identity;

// The old per-book addresses redirect here with ?tab=, so honour it.
onMounted(identity.openInitialTab);

const TABS: { key: BookTab; label: string }[] = [
    { key: 'brand', label: t('settings.brand.title') },
    { key: 'context', label: t('settings.context.title') },
];
</script>

<template>
    <Head :title="t('settings.identity.title')" />

    <SettingsLayout>
        <form class="space-y-4" @submit.prevent="submit">
            <!-- What the organization is. Both books are built from these facts
                 and from the website below, so they are asked once, up here. -->
            <SettingsCard
                :icon="Building2"
                :title="t('settings.identity.title')"
                :description="t('settings.identity.description')"
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
                    <div class="grid gap-4 sm:grid-cols-2">
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
                    </div>

                    <!-- Never a blank page: one reading of the organization's own
                         website drafts both books. The URL box is the website we
                         store — it is the same fact, typed once. -->
                    <div
                        class="space-y-2 rounded-sp-sm border border-dashed border-soft p-3"
                    >
                        <SiteImport
                            v-model:url="form.website"
                            v-model:brief="site.brief"
                            :label="t('settings.context.website')"
                            :loading="site.reading"
                            :status="site.status"
                            :last-import="props.lastImport"
                            @read="site.readSite()"
                            @open="openTab"
                        />
                        <InputError :message="form.errors.website" />
                    </div>
                </div>
            </SettingsCard>

            <!-- The two books. One save below writes both. -->
            <nav
                class="flex items-center gap-5 border-b border-soft"
                role="tablist"
                :aria-label="t('settings.identity.title')"
            >
                <button
                    v-for="item in TABS"
                    :key="item.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === item.key"
                    :class="[
                        'relative flex items-center gap-2 px-1 pb-3 text-sm transition-colors outline-none',
                        tab === item.key
                            ? 'text-ink after:absolute after:bottom-[-1px] after:left-0 after:h-[2px] after:w-full after:bg-accent-blue after:content-[\'\']'
                            : 'text-ink-muted hover:text-ink',
                    ]"
                    @click="openTab(item.key)"
                >
                    {{ item.label }}
                    <!-- An objection in a tab you are not looking at is an
                         objection nobody reads. -->
                    <span
                        v-if="errorCount[item.key]"
                        class="bg-accent-red/15 text-accent-red rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                    >
                        {{ errorCount[item.key] }}
                    </span>
                </button>
            </nav>

            <!-- Kept mounted: switching tabs must not throw away an upload in
                 flight or a palette somebody just generated. -->
            <BrandbookPanel
                v-show="tab === 'brand'"
                :form="form"
                :brand="brand"
            />
            <ContextbookPanel
                v-show="tab === 'context'"
                :form="form"
                :context="context"
                :max-tokens="props.maxTokens"
                :formality-options="props.formalityOptions"
                :unit-options="props.unitOptions"
            />

            <div class="flex items-center justify-end gap-3">
                <span v-if="props.updatedAt" class="text-xs text-ink-muted">
                    {{ new Date(props.updatedAt).toLocaleString() }}
                </span>
                <button
                    type="submit"
                    :disabled="form.processing || context.overBudget"
                    class="inline-flex h-9 items-center gap-1.5 rounded-sp-sm bg-accent-blue px-4 text-sm font-medium text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <Loader2
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    {{ t('settings.identity.save') }}
                </button>
            </div>
        </form>
    </SettingsLayout>
</template>
