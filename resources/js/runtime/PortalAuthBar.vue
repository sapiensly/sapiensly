<script setup lang="ts">
import { LogIn, LogOut, Mail } from '@lucide/vue';
import axios from 'axios';
import { ref } from 'vue';

/**
 * Sign-in for a portal, by emailed link.
 *
 * The answer is deliberately the same whether the address is known, invited or
 * neither: a portal that replied differently would tell anyone who asked which
 * addresses have accounts. So the confirmation is written to be true in every
 * case — "if that address can get in, we sent a link" — rather than reassuring
 * in one and revealing in another.
 */
const props = defineProps<{
    mount: string;
    user: { email: string; name: string | null } | null;
}>();

const open = ref(false);
const email = ref('');
const website = ref(''); // honeypot
const busy = ref(false);
const sent = ref(false);

async function requestLink() {
    if (busy.value || !email.value.trim()) return;
    busy.value = true;
    try {
        await axios.post(`${props.mount}/auth/request`, {
            email: email.value.trim(),
            website: website.value,
        });
    } catch {
        // Deliberately silent: the confirmation below is true either way, and
        // an error here would distinguish cases the endpoint refuses to.
    } finally {
        busy.value = false;
        sent.value = true;
    }
}

function logout() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `${props.mount}/auth/logout`;
    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');
    if (token) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_token';
        input.value = token;
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
}
</script>

<template>
    <div class="flex items-center gap-2 text-xs">
        <template v-if="props.user">
            <span class="hidden text-current opacity-70 sm:inline">
                {{ props.user.name || props.user.email }}
            </span>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-pill border border-current/20 px-3 py-1.5 opacity-70 transition-opacity hover:opacity-100"
                @click="logout"
            >
                <LogOut class="size-3.5" />
                Salir
            </button>
        </template>

        <template v-else>
            <button
                v-if="!open"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-pill border border-current/20 px-3 py-1.5 opacity-70 transition-opacity hover:opacity-100"
                @click="open = true"
            >
                <LogIn class="size-3.5" />
                Entrar
            </button>

            <div v-else class="flex items-center gap-2">
                <p
                    v-if="sent"
                    class="flex items-center gap-1.5 text-current opacity-70"
                >
                    <Mail class="size-3.5" />
                    Si esa dirección puede entrar, te enviamos un enlace.
                </p>
                <template v-else>
                    <input
                        v-model="email"
                        type="email"
                        inputmode="email"
                        autocomplete="email"
                        placeholder="tu@correo.com"
                        class="w-48 rounded-md border border-current/20 bg-transparent px-2 py-1 text-xs text-current placeholder:opacity-50"
                        @keyup.enter="requestLink"
                    />
                    <!-- Humans never see this; bots fill it in. -->
                    <input
                        v-model="website"
                        type="text"
                        tabindex="-1"
                        autocomplete="off"
                        aria-hidden="true"
                        class="hidden"
                    />
                    <button
                        type="button"
                        :disabled="busy"
                        class="rounded-pill px-3 py-1.5 font-medium text-white disabled:opacity-50"
                        :style="{
                            backgroundColor: 'var(--sp-accent, #3b82f6)',
                        }"
                        @click="requestLink"
                    >
                        Enviar enlace
                    </button>
                </template>
            </div>
        </template>
    </div>
</template>
