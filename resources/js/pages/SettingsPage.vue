<template>
  <div class="p-4 sm:p-8 max-w-2xl selection:bg-primary selection:text-black">
    <div class="mb-10 border-b border-primary-dim pb-4">
       <h2 class="text-3xl font-display uppercase tracking-widest text-primary glow">System_Config</h2>
       <p class="text-[10px] text-primary/40 uppercase tracking-[0.2em] mt-1">Parameters: Global_Overrides</p>
    </div>

    <!-- GitHub -->
    <section class="bg-black border border-primary/20 p-8 relative overflow-hidden group">
      <!-- Decorative corners -->
      <div class="absolute top-0 left-0 w-2 h-2 border-t border-l border-primary/40"></div>
      <div class="absolute bottom-0 right-0 w-2 h-2 border-b border-r border-primary/40"></div>

      <div class="flex items-start justify-between gap-4 mb-10">
        <div class="flex items-center gap-4">
          <div class="w-10 h-10 border border-primary/40 flex items-center justify-center glow-box shrink-0">
             <svg class="w-6 h-6 text-primary" viewBox="0 0 24 24" fill="currentColor">
               <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z" />
             </svg>
          </div>
          <div>
            <h3 class="text-xl font-display uppercase tracking-widest text-primary glow">Uplink_Auth</h3>
            <p class="text-[10px] text-primary/40 uppercase tracking-widest mt-1">Provider: GitHub_API</p>
          </div>
        </div>
        
        <div
          v-if="settings.github.configured"
          class="flex items-center gap-2 text-[9px] font-bold text-primary border border-primary/40 bg-primary/10 px-2 py-1 uppercase tracking-widest"
        >
          <span class="w-1.5 h-1.5 bg-primary shadow-[0_0_5px_var(--color-primary)] animate-pulse"></span>
          AUTH_ACTIVE
        </div>
        <div
          v-else
          class="flex items-center gap-2 text-[9px] font-bold text-primary/30 border border-primary/10 px-2 py-1 uppercase tracking-widest"
        >
          AUTH_PENDING
        </div>
      </div>

      <div v-if="settings.github.configured" class="mb-8 p-3 border-l-2 border-primary/20 bg-primary/5 flex items-center gap-3 text-[11px] font-mono text-primary/60">
        <span class="text-primary/30 uppercase">[ CURRENT_KEY ]</span>
        <code class="text-primary tracking-widest">
          {{ settings.github.pat_preview }}
        </code>
      </div>

      <form @submit.prevent="savePat" class="space-y-6">
        <div class="space-y-2">
          <label class="block text-[10px] font-bold text-primary/60 uppercase tracking-widest ml-1">
            {{ settings.github.configured ? 'REPLACE_ACCESS_TOKEN' : 'INPUT_ACCESS_TOKEN' }}
          </label>
          <div class="relative">
             <span class="absolute left-3 top-3 text-primary/40 font-mono">></span>
             <input
                v-model="pat"
                type="password"
                placeholder="github_pat_••••••••••••"
                autocomplete="off"
                class="w-full pl-8 pr-3 py-3 bg-primary/5 border border-primary/30 text-primary text-sm font-mono focus:outline-none focus:border-primary transition-all placeholder:text-primary/10"
              />
          </div>
          <p class="text-[9px] text-primary/30 uppercase tracking-[0.2em] ml-1">
             Scope_Requirements: [ repo ] Full_Access
          </p>
        </div>

        <div v-if="error" class="text-[10px] text-danger border border-danger/50 bg-danger/10 px-3 py-2 uppercase font-bold animate-pulse">
          [!] SYNC_FAILURE: {{ error }}
        </div>

        <div v-if="success" class="text-[10px] text-primary border border-primary/50 bg-primary/10 px-3 py-2 uppercase font-bold">
          [+] UPLINK_ESTABLISHED_SUCCESSFULLY
        </div>

        <div class="pt-4">
           <button
            type="submit"
            :disabled="!pat || settings.loading"
            class="px-6 py-3 bg-primary/10 border border-primary text-primary text-xs font-bold uppercase tracking-widest hover:bg-primary hover:text-black transition-all disabled:opacity-20"
          >
            <span v-if="settings.loading">VERIFYING_INTEGRITY...</span>
            <span v-else>[ COMMENCE_UPLINK ]</span>
          </button>
        </div>
      </form>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings.js'

const settings = useSettingsStore()
const pat = ref('')
const error = ref('')
const success = ref(false)

onMounted(() => settings.fetchGithub())

async function savePat() {
  error.value = ''
  success.value = false
  try {
    await settings.updateGithubPat(pat.value)
    pat.value = ''
    success.value = true
  } catch (e) {
    error.value = e.response?.data?.message ?? 'UNSPECIFIED_FAILURE'
  }
}
</script>
