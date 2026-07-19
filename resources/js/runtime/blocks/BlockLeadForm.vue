<script setup lang="ts">
import axios from 'axios';
import { computed, inject, onMounted, ref } from 'vue';

interface LeadField {
    field_id: string;
    label?: string;
    placeholder?: string;
    required?: boolean;
    input?: 'text' | 'email' | 'phone' | 'textarea';
}

interface LeadFormBlock {
    id: string;
    type: 'lead_form';
    object_id: string;
    fields: LeadField[];
    submit_label?: string;
    success_message?: string;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: LeadFormBlock }>();

// On the public page appSlug IS the public slug, so the endpoint resolves; the
// builder preview / authenticated runtime set publicSurface=false and render a
// disabled form (capture only goes live on the published page).
const appSlug = inject<string>('appSlug', '');
const publicSurface = inject<boolean>('publicSurface', false);
const turnstileSiteKey = inject<string | null>('turnstileSiteKey', null);

const values = ref<Record<string, string>>({});
const honeypot = ref('');
const turnstileToken = ref('');
const submitting = ref(false);
const done = ref(false);
const doneMessage = ref('');
const error = ref('');

const inputType = (f: LeadField): string =>
    f.input === 'email' ? 'email' : f.input === 'phone' ? 'tel' : 'text';

// Cloudflare Turnstile: load + render only on the public page and only when a
// site key is configured. Failure to load never blocks the form — the server
// verifies (or skips when keyless) and the honeypot/throttle floor remains.
const turnstileEl = ref<HTMLElement | null>(null);
onMounted(() => {
    if (!publicSurface || !turnstileSiteKey || typeof window === 'undefined') {
        return;
    }
    const render = () => {
        const t = (
            window as unknown as {
                turnstile?: {
                    render: (
                        el: HTMLElement,
                        opts: Record<string, unknown>,
                    ) => void;
                };
            }
        ).turnstile;
        if (t && turnstileEl.value) {
            t.render(turnstileEl.value, {
                sitekey: turnstileSiteKey,
                callback: (token: string) => (turnstileToken.value = token),
            });
        }
    };
    if ((window as { turnstile?: unknown }).turnstile) {
        render();
        return;
    }
    const script = document.createElement('script');
    script.src =
        'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    script.async = true;
    script.onload = render;
    document.head.appendChild(script);
});

const canSubmit = computed(() => publicSurface && !submitting.value);

async function submit() {
    if (!canSubmit.value) {
        return;
    }
    submitting.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(`/l/${appSlug}/lead`, {
            block_id: props.block.id,
            values: values.value,
            website: honeypot.value,
            turnstile_token: turnstileToken.value || null,
        });
        done.value = true;
        doneMessage.value = data.message ?? '';
    } catch (e) {
        const message = (e as { response?: { data?: { message?: string } } })
            .response?.data?.message;
        error.value =
            message ??
            'No pudimos enviar tus datos. Inténtalo de nuevo en un momento.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <!-- Structural classes only — the landing's custom_css owns the look. -->
    <div class="sp-lead-form">
        <div v-if="done" class="sp-lead-form-success" role="status">
            {{ doneMessage }}
        </div>

        <form v-else novalidate @submit.prevent="submit">
            <div
                v-for="f in block.fields"
                :key="f.field_id"
                class="sp-lead-field"
            >
                <label :for="`lf-${block.id}-${f.field_id}`">
                    {{ f.label ?? f.field_id }}
                    <span v-if="f.required" aria-hidden="true">*</span>
                </label>
                <textarea
                    v-if="f.input === 'textarea'"
                    :id="`lf-${block.id}-${f.field_id}`"
                    v-model="values[f.field_id]"
                    :placeholder="f.placeholder"
                    :required="f.required"
                    rows="4"
                />
                <input
                    v-else
                    :id="`lf-${block.id}-${f.field_id}`"
                    v-model="values[f.field_id]"
                    :type="inputType(f)"
                    :placeholder="f.placeholder"
                    :required="f.required"
                />
            </div>

            <!-- Honeypot: visually removed, still in the DOM for bots. -->
            <div class="sp-lead-hp" aria-hidden="true">
                <label :for="`lf-${block.id}-website`">Website</label>
                <input
                    :id="`lf-${block.id}-website`"
                    v-model="honeypot"
                    type="text"
                    name="website"
                    tabindex="-1"
                    autocomplete="off"
                />
            </div>

            <div v-if="turnstileSiteKey && publicSurface" ref="turnstileEl" />

            <p v-if="error" class="sp-lead-form-error" role="alert">
                {{ error }}
            </p>

            <button
                type="submit"
                class="sp-lead-submit"
                :disabled="!canSubmit"
                :title="
                    publicSurface
                        ? undefined
                        : 'La captura se activa en la página publicada'
                "
            >
                {{
                    submitting
                        ? '…'
                        : (block.submit_label ?? 'Quiero que me contacten')
                }}
            </button>
            <p v-if="!publicSurface" class="sp-lead-form-note">
                Vista previa — el formulario captura leads en la página
                publicada.
            </p>
        </form>
    </div>
</template>

<style scoped>
/* Sensible structural defaults; the landing's custom_css overrides freely. */
.sp-lead-form form {
    display: grid;
    gap: 0.7rem;
}
.sp-lead-field {
    display: grid;
    gap: 0.25rem;
}
.sp-lead-field label {
    font-size: 0.85rem;
    opacity: 0.85;
}
.sp-lead-field input,
.sp-lead-field textarea {
    width: 100%;
    font: inherit;
    color: inherit;
    background: rgba(127, 127, 127, 0.08);
    border: 1px solid rgba(127, 127, 127, 0.35);
    border-radius: 9px;
    padding: 0.7rem 0.85rem;
}
.sp-lead-field input:focus,
.sp-lead-field textarea:focus {
    outline: none;
    border-color: var(--sp-accent, #0096ff);
}
.sp-lead-submit {
    font: inherit;
    font-weight: 600;
    padding: 0.8rem 1.1rem;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    color: var(--sp-accent-contrast, #fff);
    background: var(--sp-accent, #0096ff);
}
.sp-lead-submit:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.sp-lead-form-success {
    padding: 1rem 1.1rem;
    border-radius: 10px;
    border: 1px solid var(--sp-accent, #0096ff);
}
.sp-lead-form-error {
    margin: 0;
    font-size: 0.85rem;
    color: #e5484d;
}
.sp-lead-form-note {
    margin: 0;
    font-size: 0.75rem;
    opacity: 0.6;
}
.sp-lead-hp {
    position: absolute;
    left: -9999px;
    top: auto;
    width: 1px;
    height: 1px;
    overflow: hidden;
}
</style>
