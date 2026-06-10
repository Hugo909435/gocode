<template>
    <div
        class="min-h-screen bg-bg-terminal flex items-center justify-center p-4 selection:bg-primary selection:text-black"
    >
        <div
            class="w-full max-w-md border border-primary-dim bg-bg-terminal p-8 relative overflow-hidden group"
        >
            <!-- Decorative corners -->
            <div class="absolute top-0 left-0 w-4 h-4 border-t-2 border-l-2 border-primary"></div>
            <div class="absolute top-0 right-0 w-4 h-4 border-t-2 border-r-2 border-primary"></div>
            <div
                class="absolute bottom-0 left-0 w-4 h-4 border-b-2 border-l-2 border-primary"
            ></div>
            <div
                class="absolute bottom-0 right-0 w-4 h-4 border-b-2 border-r-2 border-primary"
            ></div>

            <div class="flex flex-col items-center mb-10 text-center">
                <div
                    class="w-16 h-16 border-2 border-primary flex items-center justify-center mb-4 glow-box"
                >
                    <span class="font-display text-4xl leading-none">>_</span>
                </div>
                <div>
                    <h1
                        class="text-4xl font-display uppercase tracking-[0.2em] text-primary glow mb-1"
                    >
                        gocode_OS
                    </h1>
                    <p class="text-[10px] text-primary/50 uppercase tracking-[0.3em]">
                        Protocol: Authentication_Required
                    </p>
                </div>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="space-y-1">
                    <label
                        class="block text-[10px] font-bold text-primary/60 uppercase tracking-widest ml-1"
                        >User_Identity</label
                    >
                    <div class="relative">
                        <span class="absolute left-3 top-2.5 text-primary text-sm opacity-50"
                            >></span
                        >
                        <input
                            v-model="form.email"
                            type="email"
                            required
                            placeholder="IDENTITY@NODE"
                            class="w-full pl-8 pr-3 py-2 bg-primary/5 border border-primary/20 text-primary text-sm focus:outline-none focus:border-primary focus:bg-primary/10 transition-all placeholder:text-primary/20"
                        />
                    </div>
                </div>

                <div class="space-y-1">
                    <label
                        class="block text-[10px] font-bold text-primary/60 uppercase tracking-widest ml-1"
                        >Access_Key</label
                    >
                    <div class="relative">
                        <span class="absolute left-3 top-2.5 text-primary text-sm opacity-50"
                            >*</span
                        >
                        <input
                            v-model="form.password"
                            type="password"
                            required
                            placeholder="••••••••"
                            class="w-full pl-8 pr-3 py-2 bg-primary/5 border border-primary/20 text-primary text-sm focus:outline-none focus:border-primary focus:bg-primary/10 transition-all placeholder:text-primary/20"
                        />
                    </div>
                </div>

                <div
                    v-if="error"
                    class="p-2 border border-danger/50 bg-danger/10 text-danger text-[10px] uppercase font-bold text-center animate-pulse"
                >
                    [!] ACCESS_DENIED: {{ error }}
                </div>

                <button
                    type="submit"
                    :disabled="loading"
                    class="w-full py-3 bg-primary/10 border border-primary text-primary text-xs font-bold uppercase tracking-[0.2em] hover:bg-primary hover:text-black transition-all duration-300 disabled:opacity-30 group"
                >
                    {{ loading ? 'Authorizing...' : '[ EXECUTE_LOGIN ]' }}
                </button>

                <button
                    v-if="isDev"
                    type="button"
                    :disabled="loading"
                    class="w-full py-2 text-[9px] text-warning/50 border border-dashed border-warning/30 hover:border-warning hover:text-warning transition-all uppercase tracking-widest"
                    @click="devLogin"
                >
                    // Overload: Admin_Bypass
                </button>
            </form>

            <div
                class="mt-8 pt-6 border-t border-primary/10 flex justify-between items-center text-[9px] text-primary/30 font-mono uppercase"
            >
                <span>v2.0.6-stable</span>
                <span>Secure_Tunnel_AES256</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth.js';

const auth = useAuthStore();
const router = useRouter();

const isDev = import.meta.env.DEV;

const form = ref({ email: '', password: '' });
const loading = ref(false);
const error = ref('');

async function devLogin() {
    form.value = { email: 'admin@gocode.local', password: 'password' };
    await submit();
}

async function submit() {
    loading.value = true;
    error.value = '';
    try {
        await auth.login(form.value.email, form.value.password);
        router.push({ name: 'projects' });
    } catch (e) {
        error.value = e.response?.data?.message ?? 'SYSTEM_FAILURE';
    } finally {
        loading.value = false;
    }
}
</script>
